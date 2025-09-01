<?php

namespace App\Jobs;

use App\Models\Transcript;
use App\Services\GeminiService;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessTranscription implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The maximum execution time for this job in seconds.
     */
    public $timeout = 300;

    /**
     * The number of times the job may be attempted.
     */
    public $tries = 3;

    /**
     * Calculate the number of seconds to wait before retrying the job.
     */
    public function backoff(): array
    {
        return [30, 60, 120];
    }

    /**
     * The transcript instance to process.
     */
    protected Transcript $transcript;

    /**
     * Create a new job instance.
     */
    public function __construct(Transcript $transcript)
    {
        $this->transcript = $transcript;
        $this->onQueue('transcription');
    }

    /**
     * Get the transcript instance for testing purposes.
     */
    public function getTranscript(): Transcript
    {
        return $this->transcript;
    }

    /**
     * Execute the job.
     */
    public function handle(GeminiService $geminiService): void
    {
        try {
            Log::channel('transcription')->info('Starting transcription processing', [
                'transcript_id' => $this->transcript->id,
                'attempt' => $this->attempts(),
            ]);

            // Update status to processing
            $this->transcript->update([
                'status' => 'processing',
            ]);

            // Use GeminiService to transcribe the document
            $transcriptData = $geminiService->transcribeMedicalDocument($this->transcript);

            // Update transcript with results
            $this->transcript->update([
                'transcript' => $transcriptData, // Let the model cast this to JSON
                'status' => 'completed',
                'processed_at' => now(),
                'error_message' => null,
            ]);

            Log::channel('transcription')->info('Transcription processing completed successfully', [
                'transcript_id' => $this->transcript->id,
                'processing_time' => now()->diffInSeconds($this->transcript->updated_at),
                'data_fields_count' => $this->countDataFields($transcriptData),
            ]);

        } catch (Exception $exception) {
            Log::channel('transcription')->error('Transcription processing failed', [
                'transcript_id' => $this->transcript->id,
                'error' => $exception->getMessage(),
                'attempt' => $this->attempts(),
            ]);

            throw $exception; // Let Laravel's retry mechanism handle this
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(Exception $exception, GeminiService $geminiService): void
    {
        Log::channel('transcription')->error('Transcription job failed permanently', [
            'transcript_id' => $this->transcript->id,
            'error' => $exception->getMessage(),
            'attempts' => $this->attempts(),
        ]);

        // Get user-friendly error message from GeminiService
        $errorMessage = $geminiService->getUserFriendlyErrorMessage($exception);

        // Update transcript status to failed with user-friendly error message
        $this->transcript->update([
            'status' => 'failed',
            'error_message' => $errorMessage,
        ]);
    }

    /**
     * Count the number of data fields in the transcript data for metrics.
     */
    private function countDataFields(array $transcriptData): int
    {
        $count = 0;
        $count += count($transcriptData['prescriptions'] ?? []);
        $count += count($transcriptData['diagnoses'] ?? []);
        $count += count($transcriptData['observations'] ?? []);
        $count += count($transcriptData['tests'] ?? []);
        $count += isset($transcriptData['patient']) ? 1 : 0;
        $count += isset($transcriptData['doctor']) ? 1 : 0;
        $count += isset($transcriptData['instructions']) ? 1 : 0;

        return $count;
    }

    /**
     * Get the tags that should be assigned to the job.
     */
    public function tags(): array
    {
        return ['transcription', 'transcript:'.$this->transcript->id];
    }
}
