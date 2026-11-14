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
| appended); entries further left are never used, so a caller cannot choose
| its address by writing the header, even from Cloudflare's own network.
| Every other request keeps its connecting address and its header is
| ignored. Only X-Forwarded-For is trusted (see bootstrap/app.php); scheme,
| host and port are not taken from headers.
|
| 127.0.0.1 stays for a local reverse proxy. With a local proxy in front of
| PHP and Cloudflare in front of that, the one trusted hop is the local
| proxy, so the address becomes the Cloudflare edge it appended: visitors
| behind one edge share an allowance again, but none can choose an
| address. Never put '*' or a catch-all range here: that would let any
| caller set its own address.
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
