<?php

use Illuminate\Support\Facades\Route;

// Health check for Railway/monitoring
Route::get('/up', fn() => response()->json(['status' => 'ok']));

// Admin routes (Horizon/Telescope) will be added here later
