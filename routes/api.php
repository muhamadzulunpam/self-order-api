
<?php

use App\Http\Controllers\Api\Admin\CategoryController;
use App\Http\Controllers\Api\Admin\DiningTableController;
use App\Http\Controllers\Api\Admin\MenuController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\Customer\MenuController as CustomerMenuController;
use App\Http\Controllers\Api\Customer\OrderController as CustomerOrderController;
use App\Http\Controllers\Api\Customer\PaymentController as CustomerPaymentController;
use App\Http\Controllers\Api\MidtransWebhookController;
use App\Http\Controllers\Api\Staff\OrderController as StaffOrderController;
use Illuminate\Support\Facades\Route;

// Authentication
Route::prefix('auth')->group(function () {
    Route::post('login', [AuthController::class, 'login'])
        ->middleware('throttle:5,1');

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('logout', [AuthController::class, 'logout']);
        Route::get('me', [AuthController::class, 'me']);
    });
});

// Admin routes
Route::middleware(['auth:sanctum', 'role:admin'])
    ->prefix('admin')
    ->group(function () {
        Route::apiResource('categories', CategoryController::class);
        Route::apiResource('menus', MenuController::class);
        Route::apiResource('dining-tables', DiningTableController::class);
        Route::post('dining-tables/{diningTable}/regenerate-token', [DiningTableController::class, 'regenerateToken']);
        Route::get('dining-tables/{diningTable}/qr', [DiningTableController::class, 'qr']);
    });

Route::prefix('public')
    ->middleware('throttle:120,1')
    ->group(function () {
        Route::get('tables/{qrToken}/menu', [CustomerMenuController::class, 'index']);
        Route::post('tables/{qrToken}/orders', [CustomerOrderController::class, 'store'])
            ->middleware('throttle:create-order');
        Route::get('tables/{qrToken}/orders/{orderCode}', [CustomerOrderController::class, 'show']);
        Route::post('tables/{qrToken}/orders/{orderCode}/pay', [CustomerPaymentController::class, 'store'])
            ->middleware('throttle:create-order');
    });

Route::middleware(['auth:sanctum', 'role:admin,cashier,kitchen'])
    ->prefix('staff')
    ->group(function () {
        Route::get('orders', [StaffOrderController::class, 'index']);
        Route::patch('orders/{order}/status', [StaffOrderController::class, 'updateStatus']);
    });

Route::post('webhooks/midtrans', MidtransWebhookController::class);
