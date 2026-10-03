<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\LabourAgreementController;
use App\Http\Controllers\Api\LookupController;
use App\Http\Controllers\Api\TransactionController;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1');

Route::middleware(['auth:sanctum', 'active-account'])->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);

    Route::get('/lookups', [LookupController::class, 'index']);

    Route::get('/transactions', [TransactionController::class, 'index']);
    Route::post('/transactions', [TransactionController::class, 'store']);
    Route::patch('/transactions/{transaction}/approve', [TransactionController::class, 'approve']);
    Route::patch('/transactions/{transaction}/record-payment', [TransactionController::class, 'recordPayment']);

    Route::get('/labour-agreements', [LabourAgreementController::class, 'index']);
    Route::post('/labour-agreements', [LabourAgreementController::class, 'store']);

    Route::get('/dashboard', [DashboardController::class, 'index']);
    Route::get('/dashboard/bills', [DashboardController::class, 'bills']);
    Route::get('/dashboard/projects/{project}', [DashboardController::class, 'show']);
});
