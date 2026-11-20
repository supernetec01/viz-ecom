<?php

use App\Http\Controllers\AuthController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

foreach (['admin', 'staff', 'employer'] as $role) {
    Route::view('/khu-vuc/'.$role, 'areas.index', ['role' => $role])
        ->middleware(['auth', 'role:'.$role])->name('areas.'.$role);
}

Route::get('/bao-mat', [AuthController::class, 'security'])
    ->middleware(['auth', 'password.confirm'])->name('security');
