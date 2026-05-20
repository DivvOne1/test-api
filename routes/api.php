<?php

use App\Http\Controllers\Api\NotificationController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'throttle:api'])->group(function () {
    Route::post('/notifications/bulk', [NotificationController::class, 'store']);
    Route::get('/subscribers/notifications', [NotificationController::class, 'subscriberHistory']);
});
