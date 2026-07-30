<?php

use App\Http\Controllers\Api\PaynamicsPaymentController;
use App\Http\Controllers\Api\SalesTransactionController;
use Illuminate\Support\Facades\Route;

/*
 * Add the authenticated checkout route beside your other customer routes.
 */
Route::middleware('auth:sanctum')->group(function () {
    Route::post(
        '/public/paynamics/checkout',
        [SalesTransactionController::class, 'checkoutWithPaynamics']
    )->name('paynamics.checkout');
});

/*
 * These Paynamics callbacks must remain public. The notification is protected
 * by SHA-512 response-signature validation rather than user authentication.
 */
Route::post(
    '/paynamics/notification',
    [PaynamicsPaymentController::class, 'notification']
)->name('paynamics.notification');

Route::match(
    ['get', 'post'],
    '/paynamics/return',
    [PaynamicsPaymentController::class, 'returnFromGateway']
)->name('paynamics.return');

Route::match(
    ['get', 'post'],
    '/paynamics/cancel',
    [PaynamicsPaymentController::class, 'cancel']
)->name('paynamics.cancel');
