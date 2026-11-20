<?php

use App\Http\Controllers\AuthController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/bao-mat', [AuthController::class, 'security'])
    ->middleware(['auth', 'password.confirm'])->name('security');
