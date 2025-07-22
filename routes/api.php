<?php

use App\Http\Controllers\Api\AuthController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/

// Public authentication routes
Route::prefix('auth')->group(function () {
    Route::post('/register', [AuthController::class, 'register'])
        ->name('auth.register');
    
    Route::post('/login', [AuthController::class, 'login'])
        ->name('auth.login')
        ->middleware('throttle:5,1'); // 5 attempts per minute
});

// Protected authentication routes
Route::middleware('auth:sanctum')->prefix('auth')->group(function () {
    Route::get('/user', [AuthController::class, 'user'])
        ->name('auth.user');
    
    Route::post('/logout', [AuthController::class, 'logout'])
        ->name('auth.logout');
    
    Route::post('/logout-all', [AuthController::class, 'logoutAll'])
        ->name('auth.logout-all');
    
    Route::post('/refresh', [AuthController::class, 'refresh'])
        ->name('auth.refresh');
    
    Route::put('/profile', [AuthController::class, 'updateProfile'])
        ->name('auth.profile.update');
    
    Route::put('/password', [AuthController::class, 'changePassword'])
        ->name('auth.password.change');
    
    // Token management routes
    Route::prefix('tokens')->group(function () {
        Route::get('/', [AuthController::class, 'tokens'])
            ->name('auth.tokens.index');
        
        Route::delete('/{token_id}', [AuthController::class, 'revokeToken'])
            ->name('auth.tokens.revoke')
            ->where('token_id', '[0-9]+');
    });
});

// Health check route
Route::get('/health', function () {
    return response()->json([
        'status' => 'ok',
        'timestamp' => now()->toISOString(),
        'environment' => app()->environment(),
    ]);
})->name('api.health');

// Legacy user route for backward compatibility
Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum')->name('api.user.legacy');