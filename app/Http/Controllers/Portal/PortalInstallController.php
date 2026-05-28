<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Services\Portal\PortalInstallService;
use App\Support\PortalConfig;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

class PortalInstallController extends Controller
{
    public function __construct(private readonly PortalInstallService $service) {}

    /**
     * Public landing page for client self-service RMM installs.
     * Invalid tokens, missing RMM, or API failures all render the invalid page.
     */
    public function show(Request $request, string $token): View|RedirectResponse
    {
        $client = $this->service->findByToken($token);
        if (! $client) {
            return $this->invalidPage('This setup link is not valid. Contact your IT support team.');
        }

        $package = $this->service->buildPackage($client);
        if (! $package) {
            return $this->invalidPage(sprintf(
                'Device enrollment is not configured for your organization. Contact %s for assistance.',
                PortalConfig::companyName(),
            ));
        }

        // ?download=1 — auto-detect platform from UA and redirect to installer
        if ($request->boolean('download')) {
            $platform = $this->detectPlatform($request->userAgent() ?? '');
            $info = $platform ? $package->for($platform) : null;
            if ($info && $info->hasDownload()) {
                return redirect()->away($info->downloadUrl);
            }
            // fall through to the landing page
        }

        Log::info('[PortalInstall] Landing page viewed', [
            'client_id' => $client->id,
            'token_prefix' => substr($token, 0, 8),
            'ip' => $request->ip(),
        ]);

        return view('portal.install.show', compact('package'));
    }

    /**
     * Direct download redirect for the given platform.
     * Only used when InstallerInfo has a download_url and no script.
     * (Script and key-based installers are handled inline on the landing page.)
     */
    public function download(Request $request, string $token): RedirectResponse
    {
        $client = $this->service->findByToken($token);
        if (! $client) {
            return redirect()->route('portal.install.show', ['token' => $token]);
        }

        $package = $this->service->buildPackage($client);
        if (! $package) {
            return redirect()->route('portal.install.show', ['token' => $token]);
        }

        $platform = $request->query('platform');
        if (! is_string($platform)) {
            return redirect()->route('portal.install.show', ['token' => $token]);
        }

        $info = $package->for($platform);
        if (! $info || ! $info->hasDownload()) {
            return redirect()->route('portal.install.show', ['token' => $token]);
        }

        Log::info('[PortalInstall] Download redirect', [
            'client_id' => $client->id,
            'platform' => $platform,
        ]);

        return redirect()->away($info->downloadUrl);
    }

    private function detectPlatform(string $userAgent): ?string
    {
        if (stripos($userAgent, 'Windows') !== false) {
            return 'windows';
        }
        if (stripos($userAgent, 'Mac OS') !== false || stripos($userAgent, 'Macintosh') !== false) {
            return 'mac';
        }
        if (stripos($userAgent, 'Linux') !== false) {
            return 'linux';
        }

        return null;
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
