<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return response()->json([
        'app' => 'alma-api',
        'status' => 'ok',
    ]);
});
