<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreTranscriptRequest;
use App\Http\Requests\UpdateTranscriptRequest;
use App\Jobs\ProcessTranscription;
use App\Models\Transcript;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class TranscriptController extends Controller
{
    /**
     * Display a listing of the transcripts.
     *
     * Lists all transcripts with pagination, search, and filtering capabilities.
     */
    public function index(Request $request): Response
    {
        $query = Transcript::query();

        // Search functionality
        if ($search = $request->get('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('title', 'LIKE', "%{$search}%")
                  ->orWhere('description', 'LIKE', "%{$search}%");
            });
        }

        // Filter by status
        if ($status = $request->get('status')) {
            $query->where('status', $status);
        }

        // Order by most recent first
        $query->orderBy('created_at', 'desc');

        $transcripts = $query->paginate(15)->withQueryString();

        return Inertia::render('Transcripts/Index', [
            'transcripts' => $transcripts,
            'filters' => [
                'search' => $search,
                'status' => $status,
            ],
            'statuses' => Transcript::getStatusOptions(),
        ]);
    }

    /**
     * Show the form for creating a new transcript.
     */
    public function create(): Response
    {
        return Inertia::render('Transcripts/Create');
    }

    /**
     * Store a newly created transcript in storage.
     *
     * Validates uploaded image and form data, creates new transcript,
     * and dispatches transcription job.
     */
    public function store(StoreTranscriptRequest $request): JsonResponse|RedirectResponse
    {
        $validated = $request->validated();

        // Store the uploaded image
        $imagePath = $request->file('image')->store('transcripts', 'public');

        // Create the transcript record
        $transcript = Transcript::create([
            'title' => $validated['title'],
            'description' => $validated['description'],
            'image' => $imagePath,
            'status' => Transcript::STATUS_PENDING,
        ]);

        // Dispatch the transcription job
        ProcessTranscription::dispatch($transcript);

        // Return JSON for AJAX requests, redirect for regular form submissions
        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'transcript' => [
                    'id' => $transcript->id,
                    'status' => $transcript->status,
                    'title' => $transcript->title,
                ],
                'message' => 'Transcript created successfully. Processing will begin shortly.',
            ], 201);
        }

        return redirect()->route('transcripts.show', $transcript)
            ->with('success', 'Transcript created successfully. Processing will begin shortly.');
    }

    /**
     * Display the specified transcript.
     *
     * Shows transcript details, original image, and transcribed content.
     */
    public function show(Transcript $transcript): Response
    {
        return Inertia::render('Transcripts/Show', [
            'transcript' => [
                'id' => $transcript->id,
                'title' => $transcript->title,
                'description' => $transcript->description,
                'image' => $transcript->image ? Storage::url($transcript->image) : null,
                'transcript' => $transcript->transcript,
                'status' => $transcript->status,
                'error_message' => $transcript->error_message,
                'created_at' => $transcript->created_at,
                'processed_at' => $transcript->processed_at,
                'can_retry' => $transcript->status === Transcript::STATUS_FAILED,
                'is_processing' => $transcript->status === Transcript::STATUS_PROCESSING,
            ],
        ]);
    }

    /**
     * Get the current processing status of a transcript.
     *
     * API endpoint for AJAX polling to show real-time updates.
     */
    public function status(Transcript $transcript): JsonResponse
    {
        return response()->json([
            'id' => $transcript->id,
            'status' => $transcript->status,
            'error_message' => $transcript->error_message,
            'processed_at' => $transcript->processed_at,
            'has_transcript' => !is_null($transcript->transcript),
            'can_retry' => $transcript->status === Transcript::STATUS_FAILED,
            'is_processing' => $transcript->status === Transcript::STATUS_PROCESSING,
            'transcript_data' => $transcript->transcript,
        ]);
    }

    /**
     * Retry processing a failed transcript.
     *
     * Resets failed transcript status and dispatches new job.
     */
    public function retry(Transcript $transcript): JsonResponse|RedirectResponse
    {
        if ($transcript->status !== Transcript::STATUS_FAILED) {
            if (request()->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Only failed transcripts can be retried.',
                ], 400);
            }

            return redirect()->back()
                ->with('error', 'Only failed transcripts can be retried.');
        }

        // Reset transcript for retry
        $transcript->update([
            'status' => Transcript::STATUS_PENDING,
            'error_message' => null,
            'transcript' => null,
            'processed_at' => null,
        ]);

        // Dispatch new transcription job
        ProcessTranscription::dispatch($transcript);

        if (request()->expectsJson()) {
            return response()->json([
                'success' => true,
                'message' => 'Transcript retry initiated successfully.',
                'status' => $transcript->status,
            ]);
        }

        return redirect()->back()
            ->with('success', 'Transcript retry initiated successfully.');
    }

    /**
     * Show the form for editing the specified transcript.
     */
    public function edit(Transcript $transcript): Response|RedirectResponse
    {
        // Don't allow editing while processing
        if ($transcript->status === Transcript::STATUS_PROCESSING) {
            return redirect()->route('transcripts.show', $transcript)
                ->with('error', 'Cannot edit transcript while it is being processed.');
        }

        return Inertia::render('Transcripts/Edit', [
            'transcript' => [
                'id' => $transcript->id,
                'title' => $transcript->title,
                'description' => $transcript->description,
                'image' => $transcript->image ? Storage::url($transcript->image) : null,
                'status' => $transcript->status,
                'created_at' => $transcript->created_at,
            ],
        ]);
    }

    /**
     * Update the specified transcript in storage.
     *
     * Updates metadata and handles image re-upload with re-transcription.
     */
    public function update(UpdateTranscriptRequest $request, Transcript $transcript): RedirectResponse
    {
        // Don't allow updates while processing
        if ($transcript->status === Transcript::STATUS_PROCESSING) {
            return redirect()->route('transcripts.show', $transcript)
                ->with('error', 'Cannot update transcript while it is being processed.');
        }

        $validated = $request->validated();
        $updateData = [];

        // Update title if provided
        if (isset($validated['title'])) {
            $updateData['title'] = $validated['title'];
        }

        // Update description if provided (can be null)
        if (array_key_exists('description', $validated)) {
            $updateData['description'] = $validated['description'];
        }

        // Handle image re-upload
        if ($request->hasFile('image')) {
            // Delete old image if it exists
            if ($transcript->image) {
                Storage::disk('public')->delete($transcript->image);
            }

            // Store new image
            $updateData['image'] = $request->file('image')->store('transcripts', 'public');

            // Reset transcription data for re-processing
            $updateData['transcript'] = null;
            $updateData['status'] = Transcript::STATUS_PENDING;
            $updateData['error_message'] = null;
            $updateData['processed_at'] = null;

            // Update the transcript
            $transcript->update($updateData);

            // Dispatch new transcription job
            ProcessTranscription::dispatch($transcript);

            return redirect()->route('transcripts.show', $transcript)
                ->with('success', 'Transcript updated successfully. Re-processing will begin shortly.');
        }

        // Regular update without image change
        if (!empty($updateData)) {
            $transcript->update($updateData);
        }

        return redirect()->route('transcripts.show', $transcript)
            ->with('success', 'Transcript updated successfully.');
    }

    /**
     * Remove the specified transcript from storage.
     *
     * Soft deletes transcript and cleans up associated files.
     */
    public function destroy(Transcript $transcript): RedirectResponse
    {
        // Delete associated image file
        if ($transcript->image) {
            Storage::disk('public')->delete($transcript->image);
        }

        // Soft delete the transcript
        $transcript->delete();

        return redirect()->route('transcripts.index')
            ->with('success', 'Transcript deleted successfully.');
    }
}
