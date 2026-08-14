<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class SetLocale
{
    public function handle(Request $request, Closure $next)
    {
        $locale = session('locale', 'en');

        // CATERING-SLICE-1: 'ur' added — config/saas.php has always declared en,ur.
        if (! in_array($locale, ['en', 'ar', 'ur'])) {
            $locale = 'en';
        }

        app()->setLocale($locale);

        return $next($request);
    }
}
