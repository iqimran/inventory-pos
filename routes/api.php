<?php

use App\Http\Controllers\Api\V1\CurrentUserController;
use App\Http\Controllers\Api\V1\ProductController;
use App\Http\Controllers\Api\V1\TokenController;
use App\Http\Controllers\Api\V1\UserController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->name('api.v1.')->group(function () {
    Route::post('auth/token', [TokenController::class, 'store'])
        ->middleware('throttle:6,1')
        ->name('auth.token.store');

    Route::middleware(['auth:sanctum', 'active'])->group(function () {
        Route::delete('auth/token', [TokenController::class, 'destroy'])->name('auth.token.destroy');
        Route::get('user', CurrentUserController::class)->name('user');
        Route::get('users', [UserController::class, 'index'])->name('users.index');

        Route::get('products', [ProductController::class, 'index'])->name('products.index');
        Route::get('products/lookup/{code}', [ProductController::class, 'lookup'])
            ->where('code', '[A-Za-z0-9\-._\/]+')
            ->name('products.lookup');
    });
});
