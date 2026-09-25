<?php

use App\Http\Controllers\Casino\CallbackController;
use Illuminate\Support\Facades\Route;

Route::post('/casino/{provider}/callback', CallbackController::class)
    ->middleware('throttle:120,1')
    ->name('casino.callback');
