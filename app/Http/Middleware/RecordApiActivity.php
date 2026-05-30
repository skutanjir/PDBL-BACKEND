<?php

namespace App\Http\Middleware;

use App\Services\MonitoringService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RecordApiActivity
{
    public function handle(Request $request, Closure $next): Response
    {
        $startedAt = microtime(true);
        $response = $next($request);

        if (!str_starts_with($request->path(), 'api/monitoring/activity')) {
            app(MonitoringService::class)->recordApiActivity(
                $request,
                $response->getStatusCode(),
                (int) round((microtime(true) - $startedAt) * 1000),
                $response->getStatusCode() === 423
            );
        }

        return $response;
    }
}
