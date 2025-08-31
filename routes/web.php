<?php

use App\Http\Controllers\TranscriptController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('Welcome');
})->name('home');

Route::get('dashboard', function () {
    return Inertia::render('Dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');

// Transcript routes - protected by authentication
Route::middleware(['auth', 'verified'])->group(function () {
    // Standard resource routes
    Route::resource('transcripts', TranscriptController::class);

    // Additional custom routes
    Route::get('transcripts/{transcript}/status', [TranscriptController::class, 'status'])
        ->name('transcripts.status');

    Route::post('transcripts/{transcript}/retry', [TranscriptController::class, 'retry'])
        ->name('transcripts.retry');
});

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
