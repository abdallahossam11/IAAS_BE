<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureGateApiKey
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $expectedKey = config('services.gate.api_key');
        $providedKey = $request->header('X-GATE-API-KEY');

        if (empty($expectedKey) || empty($providedKey) || ! hash_equals($expectedKey, $providedKey)) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized gate device.'
            ], 401);
        }

        return $next($request);
    }
}
