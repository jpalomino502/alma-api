<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\EpaycoController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\UserController;

Route::get('/ping', fn() => ['ok' => true]);
Route::apiResource('products', ProductController::class);
Route::apiResource('categories', \App\Http\Controllers\CategoryController::class);
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);
Route::get('/me', [AuthController::class, 'me']);
Route::post('/logout', [AuthController::class, 'logout']);
Route::get('/profile', [ProfileController::class, 'show']);
Route::put('/profile', [ProfileController::class, 'update']);
Route::get('/orders', [OrderController::class, 'index']);
Route::get('/orders/{order}', [OrderController::class, 'show']);
Route::post('/orders/checkout', [OrderController::class, 'checkout']);
Route::post('/orders/epayco/callback', [OrderController::class, 'epaycoCallback']);
Route::post('/orders/{order}/epayco/ref', [OrderController::class, 'updateRef']);
Route::post('/orders/sync-status', [OrderController::class, 'syncStatus']);
Route::patch('/orders/{order}', [OrderController::class, 'update']);
Route::post('/epayco/session', [EpaycoController::class, 'createSession']);

// Admin: users management
Route::get('/users', [UserController::class, 'index']);
Route::patch('/users/{user}', [UserController::class, 'update']);

// Authenticated user: my orders
Route::get('/my/orders', [OrderController::class, 'my']);