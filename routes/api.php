<?php

use App\Http\Controllers\Api\V1\Gate\VehicleAccessController;
use App\Http\Controllers\Api\V1\Guest\GuestChatController;
use App\Http\Controllers\Api\V1\Student\AuthController;
use App\Http\Controllers\Api\V1\Student\ChatController;
use App\Http\Controllers\Api\V1\Student\OtpController;
use App\Http\Controllers\Api\V1\Student\ProfileController;
use App\Http\Controllers\Api\V1\Student\VehicleController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Student API Routes — v1
|--------------------------------------------------------------------------
*/

Route::prefix('v1/student')->group(function () {

    // Public
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1');
    Route::post('/login/verify-otp', [OtpController::class, 'verify'])->middleware('throttle:10,1');

    // Protected — requires Sanctum token belonging to a Student
    Route::middleware(['auth:sanctum', 'ensure.student'])->group(function () {
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::post('/change-password', [\App\Http\Controllers\Api\V1\Student\ChangePasswordController::class, 'update']);
        Route::get('/profile', [ProfileController::class, 'show']);
        Route::get('/vehicle', [VehicleController::class, 'state']);
        Route::post('/vehicle-requests', [VehicleController::class, 'store']);
        Route::get('/vehicle-requests/history', [VehicleController::class, 'history']);

        Route::prefix('chats')->group(function () {
            Route::post('', [ChatController::class, 'store']);
            Route::get('', [ChatController::class, 'index']);
            Route::get('{chatUuid}', [ChatController::class, 'show']);
            Route::patch('{chatUuid}', [ChatController::class, 'update']);
            Route::delete('{chatUuid}', [ChatController::class, 'destroy']);
            Route::post('{chatUuid}/messages', [ChatController::class, 'sendMessage']);
            Route::get('{chatUuid}/messages/{messageUuid}/status', [ChatController::class, 'messageStatus']);
            Route::post('{chatUuid}/messages/{messageUuid}/retry', [ChatController::class, 'retryMessage']);
        });
    });
});

/*
|--------------------------------------------------------------------------
| Gate API Routes — v1
|--------------------------------------------------------------------------
*/

Route::prefix('v1/gate')->middleware('ensure.gate')->group(function () {
    Route::post('/vehicle-access/check', [VehicleAccessController::class, 'check'])->middleware('throttle:60,1');
});

/*
|--------------------------------------------------------------------------
| Guest Chat API Routes — v1
|--------------------------------------------------------------------------
*/

Route::prefix('v1/guest/chat')->group(function () {
    Route::post('messages', [GuestChatController::class, 'send'])
        ->middleware('throttle:guest-chat-submit');
    Route::get('messages/{requestId}/status', [GuestChatController::class, 'status']);
    Route::get('history', [GuestChatController::class, 'history']);
});
