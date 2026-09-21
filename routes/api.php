<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Merchant\Http\Controllers\MerchantLogoutController;
use Modules\Payments\Http\Controllers\InitiatePaymentController;
use Modules\Payments\Http\Controllers\QueryPaymentController;

Route::middleware('auth:api')->group(function (): void {
    Route::post('/auth/logout', MerchantLogoutController::class)->name('auth.logout');

    Route::post('/payments', InitiatePaymentController::class)->name('payments.initiate');

    // No whereUuid(): a malformed id must get payment_not_found, not the generic route 404.
    Route::get('/payments/{paymentId}', QueryPaymentController::class)->name('payments.show');
});
