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
            return $this->invalidPage('This setup link is not valid. Contact your IT support team.');
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
    public function command(Request $request, string $token): View|Response|RedirectResponse
    {
        $client = $this->service->findByToken($token);
        if (! $client) {
            return $this->invalidPage('This setup link is not valid. Contact your IT support team.');
        }

        $platform = (string) $request->input('platform');
        $supported = $this->service->supportedPlatforms($client);
        if (! in_array($platform, $supported, true)) {
            return redirect()->route('portal.install.show', ['token' => $token]);
        }

        // The mint happens INSIDE buildInstaller(): TacticalClient POSTs
        // agents/installer/ (creating a live 168h enrolment token upstream)
        // before it can return null for a payload with no usable url/cmd. So
        // the audit row records the mint REQUEST, written before the outcome
        // is known — auditing only the success branch would leave a real
        // upstream credential with no row at all.
        $this->auditMint($client, $platform, $request);

        $info = $this->service->buildInstaller($client, $platform);
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
    private function renderPage(Client $client, string $token, array $platforms, ?string $mintedPlatform = null): Response
    {
        $package = $this->service->package($client, $platforms);

        // #857: the signed download route MINTS, so its URL is a credential-
        // producing capability link and must never appear on a page any
        // unauthenticated visitor (or crawler) can reach without asking. Only
        // the platform the visitor just explicitly minted gets one.
        $downloadUrls = [];
        if ($mintedPlatform !== null) {
            $downloadUrls[$mintedPlatform] = URL::temporarySignedRoute(
                'portal.install.download',
                now()->addHour(),
                ['token' => $token, 'platform' => $mintedPlatform],
            );
        }

        return response()
            ->view('portal.install.show', [
                'package' => $package,
                'token' => $token,
                'downloadUrls' => $downloadUrls,
                'mintedPlatform' => $mintedPlatform,
            ])
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
            ->header('Pragma', 'no-cache');
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
