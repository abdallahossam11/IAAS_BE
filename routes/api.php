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
    Route::post('/login', [AuthController::class, 'login']);

    // Protected — requires Sanctum token belonging to a Student
    Route::middleware(['auth:sanctum', 'ensure.student'])->group(function () {
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::get('/profile', [ProfileController::class, 'show']);
        Route::get('/vehicle', [VehicleController::class, 'state']);
        Route::post('/vehicle-requests', [VehicleController::class, 'store']);
        Route::get('/vehicle-requests/history', [VehicleController::class, 'history']);
    });
});
