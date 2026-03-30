<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CustomerActivityController;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\LeadSourceController;
use App\Http\Controllers\Api\PropertyController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\ViewingController;
use App\Http\Controllers\Api\CustomerDealController;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthController::class, 'login']);
Route::get('/lead-sources', [LeadSourceController::class, 'index']);

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);

    Route::get('/customers', [CustomerController::class, 'index']);
    Route::post('/customers', [CustomerController::class, 'store']);
    Route::get('/customers/{customer}', [CustomerController::class, 'show']);
    Route::put('/customers/{customer}', [CustomerController::class, 'update']);
    Route::put('/customers/{customer}/status', [CustomerController::class, 'updateStatus']);
    Route::post('/customers/{customer}/assign', [CustomerController::class, 'assign']);
    Route::put('/customers/{customer}/requirement', [CustomerController::class, 'updateRequirement']);

    Route::post('/customers/{customer}/activities', [CustomerActivityController::class, 'store']);
    Route::post('/customers/{customer}/viewings', [ViewingController::class, 'store']);
    Route::put('/customers/{customer}/toggle-priority', [CustomerController::class, 'togglePriority']);
    Route::post('/customers/{customer}/close-deal', [CustomerDealController::class, 'store']);

    Route::get('/properties', [PropertyController::class, 'index']);
    Route::post('/properties', [PropertyController::class, 'store']);

    Route::get('/users', [UserController::class, 'index']);
    Route::post('/users', [UserController::class, 'store']);
    Route::put('/users/{user}', [UserController::class, 'update']);
    Route::put('/users/{user}/toggle-status', [UserController::class, 'toggleStatus']);

    Route::get('/profile', [UserController::class, 'profile']);
    Route::post('/profile', [UserController::class, 'updateProfile']);
});