<?php

namespace App\Http\Middleware;

use Illuminate\Http\Middleware\TrustProxies as Middleware;
use Illuminate\Http\Request;

class TrustProxies extends Middleware
{
    /**
     * The trusted proxies for this application.
     *
     * Leave empty unless the application is behind a reverse proxy. Set
     * TRUSTED_PROXIES=* (or a comma-separated list of proxy IPs) in .env when
     * a proxy terminates TLS.
     *
     * @var array|string|null
     */
    protected $proxies;

    /**
     * The headers that should be used to detect proxies.
     *
     * @var int
     */
    protected $headers = Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_HOST | Request::HEADER_X_FORWARDED_PORT | Request::HEADER_X_FORWARDED_PROTO;

    public function __construct()
    {
        $proxies = config('app.trusted_proxies');

        if (is_string($proxies) && $proxies !== '') {
            $this->proxies = $proxies === '*' ? '*' : array_map('trim', explode(',', $proxies));
        }
    }
}
