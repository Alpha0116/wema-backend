<?php

use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\MessageController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\TransactionController;
use App\Http\Controllers\Api\WhatsAppWebhookController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Wemá REST API Routes
|--------------------------------------------------------------------------
*/

// 1. Dashboard & Accueil (Écran 1)
Route::prefix('dashboard')->group(function () {
    Route::get('/overview', [DashboardController::class, 'overview']);
});

// 2. Transactions & Journal (Écran 2)
Route::prefix('transactions')->group(function () {
    Route::get('/', [TransactionController::class, 'index']);
    Route::post('/', [TransactionController::class, 'store']);
    Route::get('/{id}', [TransactionController::class, 'show']);
    Route::put('/{id}', [TransactionController::class, 'update']);
    Route::post('/{id}/confirm', [TransactionController::class, 'confirm']);
    Route::post('/{id}/reject', [TransactionController::class, 'reject']);
});

// 3. Clients & Créances (Écran 3)
Route::prefix('customers')->group(function () {
    Route::get('/', [CustomerController::class, 'index']);
    Route::post('/', [CustomerController::class, 'store']);
    Route::get('/{id}', [CustomerController::class, 'show']);
    Route::put('/{id}', [CustomerController::class, 'update']);
    Route::delete('/{id}', [CustomerController::class, 'destroy']);
});

// 4. Produits & Stock (Écran 4)
Route::prefix('products')->group(function () {
    Route::get('/', [ProductController::class, 'index']);
    Route::post('/', [ProductController::class, 'store']);
    Route::get('/{id}', [ProductController::class, 'show']);
    Route::put('/{id}', [ProductController::class, 'update']);
    Route::delete('/{id}', [ProductController::class, 'destroy']);
});

// 5. Messages & Traçabilité vocale (Écran 5)
Route::prefix('messages')->group(function () {
    Route::get('/', [MessageController::class, 'index']);
    Route::get('/{id}', [MessageController::class, 'show']);
});

// 6. Meta WhatsApp Webhooks & Vocal Simulator
Route::get('/webhook/whatsapp', [WhatsAppWebhookController::class, 'verify']);
Route::post('/webhook/whatsapp', [WhatsAppWebhookController::class, 'handle']);
Route::post('/simulate-voice', [WhatsAppWebhookController::class, 'simulate']);
