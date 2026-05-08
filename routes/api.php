<?php

use App\Http\Controllers\Api\V1\Student\AuthController;
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

    // Protected — requires Sanctum token belonging to a Student
    Route::middleware(['auth:sanctum', 'ensure.student'])->group(function () {
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::get('/profile', [ProfileController::class, 'show']);
        Route::get('/vehicle', [VehicleController::class, 'state']);
        Route::post('/vehicle-requests', [VehicleController::class, 'store']);
        Route::get('/vehicle-requests/history', [VehicleController::class, 'history']);
    });
});

/*
|--------------------------------------------------------------------------
| Gate API Routes — v1
|--------------------------------------------------------------------------
*/

Route::prefix('v1/gate')->middleware('ensure.gate')->group(function () {
    Route::post('/vehicle-access/check', [\App\Http\Controllers\Api\V1\Gate\VehicleAccessController::class, 'check'])->middleware('throttle:60,1');
});
