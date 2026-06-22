<?php

namespace App\Http\Middleware;

use App\Models\Student;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePasswordChanged
{
    /**
     * Block normal student business features while the account is still on an
     * admin-issued temporary password. The student must change their password
     * (or reset it via the forgot-password flow) before these unlock.
     *
     * Apply only to "business" student routes — never to login, OTP verify,
     * logout, change-password, the forgot-password endpoints, or profile/me.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof Student && $user->password_must_be_changed) {
            return response()->json([
                'message' => 'Password change is required before continuing.',
                'code' => 'PASSWORD_CHANGE_REQUIRED',
                'must_change_password' => true,
            ], 409);
        }

        return $next($request);
    }
}
