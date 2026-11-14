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
 * trusted entry from the X-Forwarded-For chain before taking the right-most
 * survivor. With Cloudflare's ranges in the list, a request that starts on
 * Cloudflare's network (for example a Worker) would have the entry the edge
 * appended for it dropped as a proxy hop, and an entry the caller wrote
 * itself would become the address. Trusting only the connecting address
 * keeps the appended entry.
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
            $this->setTrustedProxyIpAddressesToTheCallingIp($request);
        }
    }
}
