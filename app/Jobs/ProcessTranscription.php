<?php

namespace App\Jobs;

use App\Models\Transcript;
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
    public function handle(): void
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

            // Simulate transcription processing for now
            // This will be replaced with actual Gemini API integration in Sprint 8
            $this->simulateTranscriptionProcessing();

            // Update transcript with results
            $this->transcript->update([
                'status' => 'completed',
                'processed_at' => now(),
                'error_message' => null,
            ]);

            Log::channel('transcription')->info('Transcription processing completed successfully', [
                'transcript_id' => $this->transcript->id,
                'processing_time' => now()->diffInSeconds($this->transcript->updated_at),
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
    public function failed(Exception $exception): void
    {
        Log::channel('transcription')->error('Transcription job failed permanently', [
            'transcript_id' => $this->transcript->id,
            'error' => $exception->getMessage(),
            'attempts' => $this->attempts(),
        ]);

        // Update transcript status to failed with user-friendly error message
        $errorMessage = $this->getUserFriendlyErrorMessage($exception);

        $this->transcript->update([
            'status' => 'failed',
            'error_message' => $errorMessage,
        ]);
    }    /**
     * Simulate the transcription processing.
     * TODO: This will be replaced with actual Gemini API integration.
     */
    private function simulateTranscriptionProcessing(): void
    {
        // Simulate processing time
        sleep(2);

        // Generate sample structured transcript data matching the schema
        $sampleTranscript = [
            'patient' => [
                'name' => 'John Doe',
                'age' => 45,
                'gender' => 'Male',
            ],
            'date' => now()->format('Y-m-d'),
            'prescriptions' => [
                [
                    'drug_name' => 'Amoxicillin',
                    'dosage' => '500mg',
                    'route' => 'Oral',
                    'frequency' => '3 times daily',
                    'duration' => '7 days',
                    'notes' => 'Take with food',
                ],
            ],
            'diagnoses' => [
                [
                    'condition' => 'Upper Respiratory Infection',
                    'notes' => 'Mild symptoms',
                ],
            ],
            'observations' => [
                'Patient appears alert and oriented',
                'Temperature: 100.2°F',
                'Blood pressure: 120/80 mmHg',
            ],
            'tests' => [
                [
                    'test_name' => 'CBC',
                    'result' => 'Normal',
                    'normal_range' => '4.5-11.0 K/μL',
                    'notes' => 'All parameters within normal limits',
                ],
            ],
            'instructions' => 'Rest, increase fluid intake, return if symptoms worsen',
            'doctor' => [
                'name' => 'Dr. Smith',
                'signature' => 'Dr. J. Smith, MD',
            ],
        ];

        // Update the transcript with the sample data
        $this->transcript->update([
            'transcript' => $sampleTranscript, // Let the model cast this to JSON
        ]);
    }

    /**
     * Convert technical exceptions to user-friendly error messages.
     */
    private function getUserFriendlyErrorMessage(Exception $exception): string
    {
        $message = $exception->getMessage();

        // Map technical errors to user-friendly messages
        if (str_contains($message, 'timeout')) {
            return 'The transcription took too long to complete. Please try again.';
        }

        if (str_contains($message, 'network') || str_contains($message, 'connection')) {
            return 'Unable to connect to the transcription service. Please check your internet connection and try again.';
        }

        if (str_contains($message, 'file') || str_contains($message, 'image')) {
            return 'There was a problem reading your image file. Please ensure the file is not corrupted and try uploading again.';
        }

        if (str_contains($message, 'api') || str_contains($message, 'quota')) {
            return 'The transcription service is temporarily unavailable. Please try again later.';
        }

        // Generic fallback message
        return 'An unexpected error occurred during transcription. Our team has been notified. Please try again later.';
    }

    /**
     * Get the tags that should be assigned to the job.
     */
    public function tags(): array
    {
        return ['transcription', 'transcript:' . $this->transcript->id];
    }
}
