<?php

use App\Http\Controllers\Web\AuthController;
use App\Http\Controllers\Web\DashboardController;
use App\Http\Controllers\Web\EntryController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect('/projects'));

Route::get('/login', [AuthController::class, 'show'])->name('login');
Route::post('/login', [AuthController::class, 'login']);
Route::post('/logout', [AuthController::class, 'logout']);

Route::middleware('auth')->group(function () {
    Route::get('/projects', [DashboardController::class, 'index'])->name('dashboard.index');
    Route::get('/projects/{project}', [DashboardController::class, 'show'])->name('dashboard.show');

    Route::post('/entries/transactions', [EntryController::class, 'storeTransaction']);
    Route::post('/entries/labour-agreements', [EntryController::class, 'storeAgreement']);
});
