<?php

use App\Http\Controllers\AvailabilityController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::prefix('monitoring')->name('api.monitoring.')->group(function () {
    Route::get('/availability/stats', [AvailabilityController::class, 'stats'])->name('availability.stats');
    Route::get('/availability/realtime', [AvailabilityController::class, 'realtime'])->name('availability.realtime');
    Route::get('/availability/compare', [AvailabilityController::class, 'compare'])->name('availability.compare');
    Route::get('/availability/incidents', [AvailabilityController::class, 'incidents'])->name('availability.incidents');
    Route::get('/availability/export', [AvailabilityController::class, 'export'])->name('availability.export');
});
