<?php

use App\Http\Controllers\Api\InventoryController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::get('/health', fn () => response()->json(['data' => ['status' => 'ok']]));

    Route::get('/items', [InventoryController::class, 'items']);
    Route::post('/items', [InventoryController::class, 'createItem']);
    Route::get('/items/{itemId}', [InventoryController::class, 'item'])->whereNumber('itemId');
    Route::post('/items/{itemId}/transactions', [InventoryController::class, 'createStockTransaction'])->whereNumber('itemId');

    Route::get('/alerts', [InventoryController::class, 'alerts']);
    Route::post('/alerts/{alertId}/acknowledgement', [InventoryController::class, 'acknowledgeAlert'])->whereNumber('alertId');

    Route::get('/reorder-requests', [InventoryController::class, 'reorders']);
    Route::post('/reorder-requests/{requestId}/review', [InventoryController::class, 'reviewReorder'])->whereNumber('requestId');
    Route::patch('/reorder-requests/{requestId}/procurement-status', [InventoryController::class, 'updateProcurementStatus'])->whereNumber('requestId');
    Route::post('/reorder-requests/{requestId}/receipts', [InventoryController::class, 'receiveReorder'])->whereNumber('requestId');
    Route::post('/reorder-requests/{requestId}/close', [InventoryController::class, 'closeReorder'])->whereNumber('requestId');

    Route::get('/stock-risk/weekly', [InventoryController::class, 'weeklySummaries']);
    Route::get('/manual-actions', [InventoryController::class, 'manualActions']);
    Route::post('/manual-actions/{actionId}/resolve', [InventoryController::class, 'resolveManualAction'])->whereNumber('actionId');
});
