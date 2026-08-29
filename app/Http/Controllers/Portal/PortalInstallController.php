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
     * #857: this GET mints NOTHING. It renders platform availability, both
     * install steps, and a "Show my install command" button; the credential
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

        $info = $this->service->buildInstaller($client, $platform);
        if (! $info) {
            return $this->invalidPage(sprintf(
                'We could not prepare your installer right now. Contact %s for assistance.',
                PortalConfig::companyName(),
            ));
        }

        $this->auditMint($client, $platform, $request);

        $platforms = array_fill_keys($supported, null);
        $platforms[$platform] = $info;

        return $this->renderPage($client, $token, $platforms, $platform);
    }

    /**
     * Direct download redirect for the given platform.
     *
     * #860: reachable only through a short-lived signed URL issued by the
     * pages this controller renders — a constructed URL fails the signature
     * check with a 403 before this method runs. For Tactical the vendor
     * download URL is itself minted (it carries a deployment token), so this
     * resolves ONE platform, never the whole package, and audits the mint.
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

        $info = $this->service->buildInstaller($client, $platform);
        if (! $info || ! $info->hasDownload()) {
            return redirect()->route('portal.install.show', ['token' => $token]);
        }

        Log::info('[PortalInstall] Download redirect', [
            'client_id' => $client->id,
            'platform' => $platform,
        ]);
        $this->auditMint($client, $platform, $request);

        return redirect()->away($info->downloadUrl);
    }

    /**
     * @param  array<string, \App\Services\Portal\InstallerInfo|null>  $platforms
     */
    private function renderPage(Client $client, string $token, array $platforms, ?string $mintedPlatform = null): Response
    {
        $package = $this->service->package($client, $platforms);

        $downloadUrls = [];
        foreach (array_keys($platforms) as $platform) {
            $downloadUrls[$platform] = URL::temporarySignedRoute(
                'portal.install.download',
                now()->addHour(),
                ['token' => $token, 'platform' => $platform],
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
