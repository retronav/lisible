<?php

use App\Http\Controllers\TranscriptController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('Welcome');
})->name('home');

Route::get('dashboard', function (Request $request) {
    $user = $request->user();

    // Get recent transcripts (last 5)
    $recentTranscripts = $user->transcripts()
        ->latest()
        ->limit(5)
        ->get();

    // Get processing statistics
    $totalTranscripts = $user->transcripts()->count();
    $completedTranscripts = $user->transcripts()->where('status', 'completed')->count();
    $processingTranscripts = $user->transcripts()->where('status', 'processing')->count();
    $failedTranscripts = $user->transcripts()->where('status', 'failed')->count();

    return Inertia::render('Dashboard', [
        'recentTranscripts' => $recentTranscripts,
        'stats' => [
            'total' => $totalTranscripts,
            'completed' => $completedTranscripts,
            'processing' => $processingTranscripts,
            'failed' => $failedTranscripts,
        ],
    ]);
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

    // API endpoint for real-time processing count
    Route::get('api/transcripts/processing-count', function (Request $request) {
        return response()->json([
            'count' => $request->user()->transcripts()->where('status', 'processing')->count()
        ]);
    });
});

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
