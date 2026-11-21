<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\CompanyController;
use Illuminate\Support\Facades\Route;

Route::view('/', 'public.home')->name('home');

foreach (['admin', 'staff', 'employer'] as $role) {
    Route::view('/khu-vuc/'.$role, 'areas.index', ['role' => $role])
        ->middleware(['auth', 'role:'.$role])->name('areas.'.$role);
}

Route::get('/noi-bo/cong-ty/{company}', [CompanyController::class, 'show'])
    ->middleware(['auth', 'role:admin,staff,employer']);

Route::get('/bao-mat', [AuthController::class, 'security'])
    ->middleware(['auth', 'password.confirm'])->name('security');
