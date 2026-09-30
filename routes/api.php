<?php

use App\Http\Controllers\Casino\CallbackController;
use Illuminate\Support\Facades\Route;

Route::post('/casino/{provider}/callback', CallbackController::class)
    ->middleware('throttle:120,1')
    ->name('casino.callback');

Route::post('/bridge/tipo', \App\Http\Controllers\Bridge\TipoBridgeController::class)->name('bridge.tipo');

Route::post('/casino/romaspin/{action}', \App\Http\Controllers\Casino\RomaSpinCallbackController::class)
    ->where('action', '(api/)?(balance|transaction|batch-transactions?)')
    ->middleware('throttle:1200,1')
    ->name('casino.romaspin.callback');
