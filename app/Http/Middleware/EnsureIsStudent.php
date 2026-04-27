<?php

namespace App\Http\Middleware;

use App\Models\Student;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureIsStudent
{
    /**
     * Ensure the authenticated Sanctum token belongs to a Student model.
     * Returns 403 if the token owner is not an App\Models\Student.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! ($user instanceof Student)) {
            return response()->json([
                'success' => false,
                'message' => 'Forbidden. This endpoint is for students only.',
            ], 403);
        }

        return $next($request);
    }
}
