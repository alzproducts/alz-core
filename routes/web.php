<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

// Health check for Railway/monitoring
Route::get('/up', static fn() => response()->json(['status' => 'ok']));

// Admin routes (Horizon/Telescope) will be added here later
