<?php

use App\Http\Controllers\AppointmentController;
use App\Http\Controllers\AppointmentEventController;
use App\Http\Controllers\AppointmentNoteController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\AvailabilityBlockController;
use App\Http\Controllers\CustomerReviewController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\GauloisController;
use App\Http\Controllers\HelixUserController;
use App\Http\Controllers\Livreo\OrderController as LivreoOrderController;
use App\Http\Controllers\Livreo\RmaController as LivreoRmaController;
use App\Http\Controllers\Livreo\ShipmentController as LivreoShipmentController;
use App\Http\Controllers\Livreo\SupplierMailController as LivreoSupplierMailController;
use App\Http\Controllers\Livreo\SupplierOrderController as LivreoSupplierOrderController;
use App\Http\Controllers\OptionCacheController;
use App\Http\Controllers\ScheduleController;
use App\Http\Controllers\SettingController;
use Illuminate\Support\Facades\Route;

Route::post('/auth/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function (): void {
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/me', [AuthController::class, 'me']);

    Route::apiResource('users', HelixUserController::class);

    Route::apiResource('appointments', AppointmentController::class);
    Route::get('appointments/{appointment}/notes', [AppointmentNoteController::class, 'index']);
    Route::post('appointments/{appointment}/notes', [AppointmentNoteController::class, 'store']);
    Route::delete('appointments/{appointment}/notes/{note}', [AppointmentNoteController::class, 'destroy']);
    Route::get('appointments/{appointment}/events', [AppointmentEventController::class, 'index']);

    Route::apiResource('availability-blocks', AvailabilityBlockController::class);

    Route::get('schedule/slots', [ScheduleController::class, 'index']);
    Route::post('schedule/slots/toggle', [ScheduleController::class, 'toggle']);

    Route::get('settings', [SettingController::class, 'index']);
    Route::put('settings/{key}', [SettingController::class, 'update']);

    Route::get('dashboard/summary', DashboardController::class);

    Route::post('options-cache/refresh', [OptionCacheController::class, 'refresh']);

    Route::get('reviews', [CustomerReviewController::class, 'index']);
    Route::patch('reviews/{review}', [CustomerReviewController::class, 'update']);

    Route::prefix('gaulois')->group(function (): void {
        Route::get('meta', [GauloisController::class, 'meta']);
        Route::get('logs', [GauloisController::class, 'logs']);
        Route::get('logs/{filename}', [GauloisController::class, 'showLog']);
        Route::post('seeds', [GauloisController::class, 'storeSeed']);
        Route::post('run', [GauloisController::class, 'run']);
        Route::post('run/stream', [GauloisController::class, 'stream']);
    });

    Route::prefix('livreo')->group(function (): void {
        Route::get('orders', [LivreoOrderController::class, 'index']);
        Route::get('orders/{id}', [LivreoOrderController::class, 'show']);
        Route::patch('orders/{id}', [LivreoOrderController::class, 'update']);
        Route::post('orders/{id}/boxtal/refresh', [LivreoOrderController::class, 'boxtalRefresh']);
        Route::get('orders/{id}/boxtal/validate', [LivreoOrderController::class, 'boxtalValidate']);
        Route::post('orders/{id}/boxtal/buy-label', [LivreoOrderController::class, 'boxtalBuyLabel']);
        Route::get('orders/{id}/boxtal/label', [LivreoOrderController::class, 'boxtalLabel']);

        Route::post('orders/{orderId}/shipments', [LivreoShipmentController::class, 'store']);

        Route::post('orders/{orderId}/supplier-orders', [LivreoSupplierOrderController::class, 'store']);
        Route::patch('supplier-orders/{supplierOrderId}', [LivreoSupplierOrderController::class, 'update']);
        Route::get('suppliers/mobilesentrix/shipments', [LivreoSupplierMailController::class, 'listMobileSentrixShipments']);
        Route::post('supplier-orders/{supplierOrderId}/assign-mobilesentrix', [LivreoSupplierMailController::class, 'assignMobileSentrixToSupplierOrder']);
        Route::post('suppliers/mobilesentrix/sync-mail', [LivreoSupplierMailController::class, 'syncMobileSentrix']);

        Route::get('sav', [LivreoRmaController::class, 'index']);
        Route::get('sav/{id}', [LivreoRmaController::class, 'show']);
        Route::patch('sav/{id}', [LivreoRmaController::class, 'update']);
        Route::post('sav/{id}/comments', [LivreoRmaController::class, 'comment']);
    });
});
