<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Seo;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Adds X-Robots-Tag headers so authenticated application pages are never
 * indexed, even though they are rendered by the same SPA shell as the public
 * landing/documentation pages.
 */
class SeoHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($request->isMethod('GET') || $request->isMethod('HEAD')) {
            $name = $request->route()?->getName();

            if (! in_array($name, Seo::MACHINE_ROUTES, true) && ! in_array($name, Seo::PUBLIC_ROUTES, true)) {
                $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
            }
        }

        return $response;
    }
}
