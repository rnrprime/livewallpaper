<?php

return [

    /*
    | Proxies allowed to report the real client IP (comma-separated IPs/CIDRs, or *).
    | Set this when a CDN or load balancer sits in front of the API; otherwise every
    | user shares the proxy's address and its rate-limit bucket.
    */

    'proxies' => env('TRUSTED_PROXIES'),

];
