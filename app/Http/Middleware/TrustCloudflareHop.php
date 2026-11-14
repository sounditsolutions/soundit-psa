<?php

namespace App\Http\Middleware;

use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * Trusts one proxy hop: the connecting address, and only when it is inside
 * config/trustedproxy.php. The visitor's address is then the right-most
 * X-Forwarded-For entry, the one that hop appended.
 *
 * The framework's TrustProxies trusts the whole list, and Symfony drops every
 * trusted entry, and every entry that is not a valid IP, from the
 * X-Forwarded-For chain before taking the right-most survivor. So when the
 * connecting address is listed, the header is first cut down to its
 * right-most entry, and no entry a caller wrote reaches Symfony. If that
 * entry equals the connecting address or is not a valid IP, the address is
 * the connecting address. Whether the connecting address is this site's own
 * edge is not checked (see config/trustedproxy.php).
 *
 * Only X-Forwarded-For is trusted (bootstrap/app.php). Never '*'. A
 * connecting address outside the list trusts nothing, so its header is
 * ignored.
 */
class TrustCloudflareHop extends TrustProxies
{
    protected $headers = Request::HEADER_X_FORWARDED_FOR;

    protected function setTrustedProxyIpAddresses(Request $request)
    {
        $remote = $request->server->get('REMOTE_ADDR');
        $listed = (array) config('trustedproxy.proxies', []);

        if (is_string($remote) && $remote !== '' && $listed !== [] && IpUtils::checkIp($remote, $listed)) {
            $this->keepOnlyTheAppendedEntry($request);
            $this->setTrustedProxyIpAddressesToTheCallingIp($request);
        }
    }

    /**
     * Cut X-Forwarded-For to its right-most entry: the one the connecting hop
     * appended. Every entry to its left came from the caller.
     */
    private function keepOnlyTheAppendedEntry(Request $request): void
    {
        $lines = $request->headers->all('x-forwarded-for');
        if ($lines === []) {
            return;
        }

        $entries = explode(',', (string) end($lines));
        $request->headers->set('X-Forwarded-For', trim((string) end($entries)));
    }
}
