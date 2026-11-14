<?php

/*
|--------------------------------------------------------------------------
| Trusted proxies
|--------------------------------------------------------------------------
|
| The site sits behind Cloudflare, so the connecting address PHP sees is a
| Cloudflare edge, not the visitor. App\Http\Middleware\TrustCloudflareHop
| trusts one hop only: when the connecting address is in this list, the
| visitor is the right-most X-Forwarded-For entry (the one that hop
| appended). The header is cut to that entry before it is read, so entries a
| caller wrote are never used; if that entry equals the connecting address or
| is not a valid IP, the address is the connecting address. The app cannot
| tell this site's edge from any other Cloudflare address, so a connection
| that reaches the origin directly from Cloudflare's network is trusted the
| same way and can choose its address. Only authenticating the origin
| connection closes that.
| Every other request keeps its connecting address and its header is
| ignored. Only X-Forwarded-For is trusted (see bootstrap/app.php); scheme,
| host and port are not taken from headers.
|
| 127.0.0.1 stays for a local reverse proxy. With a local proxy in front of
| PHP and Cloudflare in front of that, the one trusted hop is the local
| proxy, so the address is the right-most entry that proxy delivers. If it
| appends or sets the address it received the request from (nginx:
| $proxy_add_x_forwarded_for or $remote_addr), that is the Cloudflare edge:
| visitors behind one edge share an allowance again, but none can choose an
| address. A proxy that passes the header through unchanged (nginx's
| proxy_pass default) lets any caller that reaches it choose its address.
|
| Never put a catch-all range (0.0.0.0/0, ::/0) here: that would let any
| caller set its own address. A bare '*' matches no address in
| TrustCloudflareHop, so it would trust nothing and every visitor behind
| Cloudflare would share the edge's allowance again.
|
| Refreshing the list: Cloudflare publishes its ranges at
| https://www.cloudflare.com/ips-v4 and https://www.cloudflare.com/ips-v6.
| They change rarely. Compare both lists with the two blocks below when
| upgrading, and update them in a normal PR. Last compared: 2026-11-14.
|
*/

$cloudflare = [
    // https://www.cloudflare.com/ips-v4
    '173.245.48.0/20',
    '103.21.244.0/22',
    '103.22.200.0/22',
    '103.31.4.0/22',
    '141.101.64.0/18',
    '108.162.192.0/18',
    '190.93.240.0/20',
    '188.114.96.0/20',
    '197.234.240.0/22',
    '198.41.128.0/17',
    '162.158.0.0/15',
    '104.16.0.0/13',
    '104.24.0.0/14',
    '172.64.0.0/13',
    '131.0.72.0/22',
    // https://www.cloudflare.com/ips-v6
    '2400:cb00::/32',
    '2606:4700::/32',
    '2803:f800::/32',
    '2405:b500::/32',
    '2405:8100::/32',
    '2a06:98c0::/29',
    '2c0f:f248::/32',
];

return [
    'proxies' => array_merge(['127.0.0.1'], $cloudflare),
];
