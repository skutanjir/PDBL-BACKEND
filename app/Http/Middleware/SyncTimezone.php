<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class SyncTimezone
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $timezone = $request->header('X-Timezone');
        $user = $request->user();

        if ($timezone && $user) {
            if ($user->timezone !== $timezone) {
                $user->timezone = $timezone;
                $user->save();
            }
        }

        return $next($request);
    }
}
