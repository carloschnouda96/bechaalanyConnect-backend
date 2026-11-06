<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/** Response headers no CMS page or API reply needs to go without. */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');

        // The admin and the API docs load scripts from this site only. Inline scripts and eval
        // stay because the vendor CMS and CKEditor 4 depend on them, so this bounds where code
        // can load from and where a page can send data, rather than stopping every injection.
        if ($request->is(trim((string) config('hellotree.cms_route_prefix'), '/') . '*', 'api-docs')) {
            $response->headers->set('Content-Security-Policy', implode('; ', [
                "default-src 'self'",
                "script-src 'self' 'unsafe-inline' 'unsafe-eval'",
                "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com",
                "font-src 'self' data: https://fonts.gstatic.com",
                "img-src 'self' data: blob: https:",
                "connect-src 'self'",
                "worker-src 'self' blob:",
                "frame-src 'self'",
                "object-src 'none'",
                "base-uri 'self'",
                "form-action 'self'",
                "frame-ancestors 'self'",
            ]));
        }

        if ($request->isSecure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000');
        }

        return $response;
    }
}
