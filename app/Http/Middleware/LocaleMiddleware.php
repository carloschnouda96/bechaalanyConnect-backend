<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Hellotreedigital\Cms\Models\Language;
use Illuminate\Support\Facades\App;

class LocaleMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure(\Illuminate\Http\Request): (\Illuminate\Http\Response|\Illuminate\Http\RedirectResponse)  $next
     * @return \Illuminate\Http\Response|\Illuminate\Http\RedirectResponse
     */
    public function handle(Request $request, Closure $next)
    {
        $language = Language::where('slug', $request->locale)->firstOrFail();

        App::setLocale($language->slug);

        return $next($request);
    }
}
