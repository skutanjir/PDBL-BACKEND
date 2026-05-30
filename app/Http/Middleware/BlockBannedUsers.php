<?php

namespace App\Http\Middleware;

use App\Services\MonitoringService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class BlockBannedUsers
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = auth('api')->user();
        if ($user && $user->status === 'banned') {
            app(MonitoringService::class)->recordApiActivity($request, 423, 0, true);
            return response()->json([
                'message' => 'Your account access is blocked.',
                'status' => 'banned',
                'reason' => $user->status_reason,
            ], 423);
        }

        return $next($request);
    }
}
