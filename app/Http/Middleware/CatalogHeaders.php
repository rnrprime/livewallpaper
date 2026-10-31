<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CatalogHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        // 304 revalidations keep the same caching policy as the 200 they confirm.
        $response->headers->set('Cache-Control', in_array($response->getStatusCode(), [200, 304], true)
            ? sprintf('public, max-age=%d, stale-while-revalidate=%d',
                (int) config('catalog.max_age'), (int) config('catalog.stale_while_revalidate'))
            : 'no-store');

        return $response;
    }
}
