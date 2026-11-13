<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\PortalInstallAudit;
use App\Services\Portal\PortalInstallService;
use App\Support\PortalConfig;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Illuminate\View\View;

class PortalInstallController extends Controller
{
    public function __construct(private readonly PortalInstallService $service) {}

    /**
     * Public landing page for client self-service RMM installs. Invalid or
     * expired tokens, missing RMM, or API failures all render the invalid page.
     *
     * #857: this GET mints NOTHING — and hands out nothing that mints on
     * access either. It renders platform availability and a "Show my install
     * command" button, and NO signed download link: that route's handler mints
     * too, so a link-following crawler or mail-security prefetch would walk
     * one hop deeper into exactly the hole this gate closes. The credential
     * itself only exists after the explicit POST to command(). Before this,
     * every bare page load minted up to three live enrolment tokens upstream —
     * one per platform — for any crawler, link scanner, or mail-security
     * prefetch that touched the URL.
     */
    public function show(Request $request, string $token): View|Response
    {
        $client = $this->service->findByToken($token);
        if (! $client) {
            return $this->invalidPage('This setup link is not valid. Contact us.');
        }

        $platforms = $this->service->supportedPlatforms($client);
        if (empty($platforms)) {
            return $this->invalidPage(sprintf(
                'Device enrollment is not configured for your organization. Contact %s for assistance.',
                PortalConfig::companyName(),
            ));
        }

        Log::info('[PortalInstall] Landing page viewed', [
            'client_id' => $client->id,
            'token_prefix' => substr($token, 0, 8),
            'ip' => $request->ip(),
        ]);

        return $this->renderPage($client, $token, array_fill_keys($platforms, null));
    }

    /**
     * The ONLY place a portal visit turns into an enrolment credential.
     * CSRF-protected POST under a tight throttle; mints for exactly one
     * platform and records the fact — never the credential — in
     * portal_install_audits.
     *
     * #841: the response can carry InstallerInfo::$installScript, which holds
     * a live --auth enrolment token. TacticalClient's contract for that value
     * — hand it over, never persist it — is what the MCP surface honours with
     * no-store, so this unauthenticated response is returned no-store too: no
     * browser, proxy, or TLS-inspecting gateway may retain the credential.
     */
    public function command(Request $request, string $token): View|Response|RedirectResponse|\Symfony\Component\HttpFoundation\StreamedResponse
    {
        $client = $this->service->findByToken($token);
        if (! $client) {
            return $this->invalidPage('This setup link is not valid. Contact us.');
        }

        $platform = (string) $request->input('platform');
        $supported = $this->service->supportedPlatforms($client);
        if (! in_array($platform, $supported, true)) {
            return redirect()->route('portal.install.show', ['token' => $token]);
        }

        $goarch = (string) $request->input('goarch', 'amd64');
        if ($client->effectiveInstallRmm() === 'tactical') {
            $allowed = $platform === 'windows' ? ['amd64', '386'] : ['amd64', 'arm64'];
            if (! in_array($goarch, $allowed, true)) {
                return $this->renderPage($client, $token, array_fill_keys($supported, null), null, 'Choose a supported architecture.');
            }
            if ($platform === 'windows' && $request->input('method') === 'exe') {
                $nonce = $request->input('nonce');
                $issued = $request->session()->get('tactical_installer_nonce');
                if (! is_string($nonce) || ! is_array($issued)
                    || ! is_string($issued['value'] ?? null) || ! is_int($issued['expires'] ?? null)
                    || ($issued['client_id'] ?? null) !== $client->id
                    || ! hash_equals($issued['value'], $nonce) || $issued['expires'] <= time()
                    || ! \Illuminate\Support\Facades\Cache::add('tactical-installer-used:'.hash('sha256', $nonce), true, 3600)) {
                    return $this->renderPage($client, $token, array_fill_keys($supported, null), null, 'This download request expired or was already used. Request a new installer.');
                }
                $this->auditMint($client, $platform, $request);
                try {
                    $binary = app(\App\Services\Tactical\TacticalClient::class)
                        ->generateWindowsInstaller((string) $client->tactical_site_id, $goarch);
                } catch (\App\Services\Tactical\InstallerGenerationException $e) {
                    return $this->renderPage($client, $token, array_fill_keys($supported, null), 'windows', $e->getMessage());
                } catch (\Throwable) {
                    return $this->renderPage($client, $token, array_fill_keys($supported, null), 'windows', 'The installer could not be prepared. Use the manual fallback or contact us.');
                }

                return response()->streamDownload(static function () use ($binary): void {
                    echo $binary;
                }, 'workstation-setup.exe', [
                    'Content-Type' => 'application/octet-stream',
                    'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
                    'Pragma' => 'no-cache', 'X-Content-Type-Options' => 'nosniff',
                    'Referrer-Policy' => 'no-referrer',
                ]);
            }
        }

        // The mint happens INSIDE buildInstaller(): TacticalClient POSTs
        // agents/installer/ (creating a live 168h enrolment token upstream)
        // before it can return null for a payload with no usable url/cmd. So
        // the audit row records the mint REQUEST, written before the outcome
        // is known — auditing only the success branch would leave a real
        // upstream credential with no row at all.
        $this->auditMint($client, $platform, $request);

        $info = $this->service->buildInstaller($client, $platform, $goarch);
        if (! $info) {
            return $this->invalidPage(sprintf(
                'We could not prepare your installer right now. Contact %s for assistance.',
                PortalConfig::companyName(),
            ));
        }

        $platforms = array_fill_keys($supported, null);
        $platforms[$platform] = $info;

        return $this->renderPage($client, $token, $platforms, $platform);
    }

    /**
     * Direct download redirect for the given platform.
     *
     * #860: reachable only through a short-lived signed URL — a constructed
     * URL fails the signature check with a 403 before this method runs. That
     * URL is issued ONLY by the page that follows an explicit mint, never by
     * the bare landing page (#857): for Tactical the vendor download URL is
     * itself minted (it carries a deployment token), so a signed GET on the
     * bare page would be a prefetch-mint link. Resolves ONE platform, never
     * the whole package, and audits the mint.
     */
    public function download(Request $request, string $token): RedirectResponse
    {
        $client = $this->service->findByToken($token);
        if (! $client) {
            return redirect()->route('portal.install.show', ['token' => $token]);
        }

        // Legacy signed GET must not mint Tactical enrollment credentials.
        if ($client->effectiveInstallRmm() === 'tactical') {
            return redirect()->route('portal.install.show', ['token' => $token]);
        }

        $platform = $request->query('platform');
        if (! is_string($platform) || ! in_array($platform, $this->service->supportedPlatforms($client), true)) {
            return redirect()->route('portal.install.show', ['token' => $token]);
        }

        // Audited before the call for the same reason as command(): the mint
        // happens inside buildInstaller(), so a null or download-less return is
        // an outcome — not proof that nothing was minted upstream.
        $this->auditMint($client, $platform, $request);

        $info = $this->service->buildInstaller($client, $platform);
        if (! $info || ! $info->hasDownload()) {
            return redirect()->route('portal.install.show', ['token' => $token]);
        }

        Log::info('[PortalInstall] Download redirect', [
            'client_id' => $client->id,
            'platform' => $platform,
        ]);

        return redirect()->away($info->downloadUrl);
    }

    /**
     * @param  array<string, \App\Services\Portal\InstallerInfo|null>  $platforms
     */
    private function renderPage(Client $client, string $token, array $platforms, ?string $mintedPlatform = null, ?string $installerError = null): Response
    {
        $package = $this->service->package($client, $platforms);

        // #857: the signed download route MINTS, so its URL is a credential-
        // producing capability link and must never appear on a page any
        // unauthenticated visitor (or crawler) can reach without asking. Only
        // the platform the visitor just explicitly minted gets one.
        $downloadUrls = [];
        if ($mintedPlatform !== null && $client->effectiveInstallRmm() !== 'tactical') {
            $downloadUrls[$mintedPlatform] = URL::temporarySignedRoute(
                'portal.install.download',
                now()->addHour(),
                ['token' => $token, 'platform' => $mintedPlatform],
            );
        }

        $installerNonce = null;
        if ($client->effectiveInstallRmm() === 'tactical') {
            $installerNonce = bin2hex(random_bytes(32));
            request()->session()->put('tactical_installer_nonce', ['value' => $installerNonce, 'expires' => time() + 3600, 'client_id' => $client->id]);
        }

        return response()
            ->view('portal.install.show', [
                'isTactical' => $client->effectiveInstallRmm() === 'tactical',
                'installerNonce' => $installerNonce,
                'installerError' => $installerError,
                'package' => $package,
                'token' => $token,
                'downloadUrls' => $downloadUrls,
                'mintedPlatform' => $mintedPlatform,
            ])
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
            ->header('Pragma', 'no-cache')
            ->header('Referrer-Policy', 'no-referrer');
    }

    private function auditMint(Client $client, string $platform, Request $request): void
    {
        PortalInstallAudit::create([
            'client_id' => $client->id,
            'rmm' => $client->effectiveInstallRmm() ?? 'unknown',
            'platform' => $platform,
            'ip' => $request->ip(),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 255) ?: null,
        ]);
    }

    private function invalidPage(string $message): View
    {
        return view('portal.install.invalid', [
            'message' => $message,
            'mspName' => PortalConfig::companyName(),
            'mspLogoUrl' => PortalConfig::logoUrl(),
            'supportEmail' => PortalConfig::supportEmail(),
            'supportPhone' => PortalConfig::supportPhone(),
        ]);
    }
}
