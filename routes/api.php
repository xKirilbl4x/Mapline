<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\OrganizationController;
use Illuminate\Support\Facades\Route;

Route::middleware('web')->group(function (): void {
    Route::post('/login', [AuthController::class, 'login']);

    Route::middleware('auth:sanctum')->group(function (): void {
        Route::get('/me', [AuthController::class, 'me']);
        Route::post('/logout', [AuthController::class, 'logout']);

        Route::get('/organization', [OrganizationController::class, 'current']);
        Route::post('/organization', [OrganizationController::class, 'store']);
        Route::get('/organization/reviews', [OrganizationController::class, 'reviews']);
        Route::get('/organization/status', [OrganizationController::class, 'status']);
    });
});
