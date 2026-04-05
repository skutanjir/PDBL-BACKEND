<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ForceGzipResponse
{
    /**
     * Handle an incoming request and compress the response using Gzip if supported.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // Don't compress if gzip isn't supported or already compressed
        if (!$request->expectsJson() || !in_array('gzip', $request->getEncodings()) || !function_exists('gzencode')) {
            return $response;
        }

        // Only compress content if it's large enough (e.g., > 1KB)
        $content = $response->getContent();
        if (strlen($content) < 1000) {
            return $response;
        }

        $compressed = gzencode($content, 6); // Compromise between speed and compression

        if ($compressed === false) {
            return $response;
        }

        $response->setContent($compressed);
        $response->headers->add([
            'Content-Encoding' => 'gzip',
            'Content-Type' => 'application/json',
            'Content-Length' => strlen($compressed),
            'Vary' => 'Accept-Encoding',
        ]);

        return $response;
    }
}
