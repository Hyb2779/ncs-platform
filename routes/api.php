<?php

use App\Http\Controllers\Casino\CallbackController;
use Illuminate\Support\Facades\Route;

Route::post('/casino/{provider}/callback', CallbackController::class)
    ->middleware('throttle:120,1')
    ->name('casino.callback');

Route::post('/bridge/tipo', \App\Http\Controllers\Bridge\TipoBridgeController::class)->name('bridge.tipo');
