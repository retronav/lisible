<?php

namespace Tests\Unit;

use App\Jobs\ProcessTranscription;
use App\Models\Transcript;
use Exception;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ProcessTranscriptionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test that the job can be instantiated correctly.
     */
    public function test_job_can_be_instantiated(): void
    {
        $transcript = Transcript::factory()->create();
        $job = new ProcessTranscription($transcript);

        $this->assertInstanceOf(ProcessTranscription::class, $job);
        $this->assertEquals('transcription', $job->queue);
        $this->assertEquals(300, $job->timeout);
        $this->assertEquals(3, $job->tries);
        $this->assertEquals([30, 60, 120], $job->backoff());
    }

    /**
     * Test job tags are set correctly.
     */
    public function test_job_tags_are_set_correctly(): void
    {
        $transcript = Transcript::factory()->create();
        $job = new ProcessTranscription($transcript);

        $expectedTags = ['transcription', 'transcript:' . $transcript->id];
        $this->assertEquals($expectedTags, $job->tags());
    }

    /**
     * Test successful job execution updates transcript status to processing then completed.
     */
    public function test_successful_job_execution_updates_status_correctly(): void
    {
        Log::shouldReceive('channel')->with('transcription')->andReturnSelf();
        Log::shouldReceive('info')->times(2);

        $transcript = Transcript::factory()->pending()->create();
        $job = new ProcessTranscription($transcript);

        $this->assertEquals('pending', $transcript->fresh()->status);

        $job->handle();

        $transcript = $transcript->fresh();
        $this->assertEquals('completed', $transcript->status);
        $this->assertNotNull($transcript->processed_at);
        $this->assertNull($transcript->error_message);
        $this->assertNotNull($transcript->transcript);
        $this->assertIsArray($transcript->transcript);
    }

    /**
     * Test that completed transcript has valid JSON structure.
     */
    public function test_completed_transcript_has_valid_json_structure(): void
    {
        Log::shouldReceive('channel')->with('transcription')->andReturnSelf();
        Log::shouldReceive('info')->times(2);

        $transcript = Transcript::factory()->pending()->create();
        $job = new ProcessTranscription($transcript);

        $job->handle();

        $transcript = $transcript->fresh();
        $transcriptData = $transcript->transcript;

        // Check required top-level keys
        $requiredKeys = ['patient', 'date', 'prescriptions', 'diagnoses', 'observations', 'tests', 'instructions', 'doctor'];
        foreach ($requiredKeys as $key) {
            $this->assertArrayHasKey($key, $transcriptData);
        }

        // Check patient structure
        $this->assertArrayHasKey('name', $transcriptData['patient']);
        $this->assertArrayHasKey('age', $transcriptData['patient']);
        $this->assertArrayHasKey('gender', $transcriptData['patient']);

        // Check doctor structure
        $this->assertArrayHasKey('name', $transcriptData['doctor']);
        $this->assertArrayHasKey('signature', $transcriptData['doctor']);

        // Check arrays exist
        $this->assertIsArray($transcriptData['prescriptions']);
        $this->assertIsArray($transcriptData['diagnoses']);
        $this->assertIsArray($transcriptData['observations']);
        $this->assertIsArray($transcriptData['tests']);
    }

    /**
     * Test job failure handling updates transcript status correctly.
     */
    public function test_job_failure_updates_transcript_status(): void
    {
        // Don't set up any log expectations - just test the behavior
        $transcript = Transcript::factory()->pending()->create();
        $job = new ProcessTranscription($transcript);

        // Force an exception during processing
        $exception = new Exception('Test exception');

        // Simulate job failure (this will log, but we don't need to mock it for this test)
        $job->failed($exception);

        $transcript = $transcript->fresh();
        $this->assertEquals('failed', $transcript->status);
        $this->assertNotNull($transcript->error_message);
        $this->assertStringContainsString('An unexpected error occurred', $transcript->error_message);
    }

    /**
     * Test user-friendly error message conversion.
     */
    public function test_user_friendly_error_messages(): void
    {
        $transcript = Transcript::factory()->pending()->create();
        $job = new ProcessTranscription($transcript);

        // Test timeout error
        $timeoutException = new Exception('Connection timeout occurred');
        $job->failed($timeoutException);
        $transcript = $transcript->fresh();
        $this->assertStringContainsString('took too long to complete', $transcript->error_message);

        // Test network error
        $transcript = Transcript::factory()->pending()->create();
        $job = new ProcessTranscription($transcript);
        $networkException = new Exception('Network connection failed');
        $job->failed($networkException);
        $transcript = $transcript->fresh();
        $this->assertStringContainsString('Unable to connect', $transcript->error_message);

        // Test file error
        $transcript = Transcript::factory()->pending()->create();
        $job = new ProcessTranscription($transcript);
        $fileException = new Exception('Image file corrupted');
        $job->failed($fileException);
        $transcript = $transcript->fresh();
        $this->assertStringContainsString('problem reading your image file', $transcript->error_message);

        // Test API error
        $transcript = Transcript::factory()->pending()->create();
        $job = new ProcessTranscription($transcript);
        $apiException = new Exception('API quota exceeded');
        $job->failed($apiException);
        $transcript = $transcript->fresh();
        $this->assertStringContainsString('temporarily unavailable', $transcript->error_message);
    }

    /**
     * Test job can be queued correctly.
     */
    public function test_job_can_be_queued(): void
    {
        Queue::fake();

        $transcript = Transcript::factory()->pending()->create();

        $transcript->dispatchTranscriptionJob();

        Queue::assertPushed(ProcessTranscription::class, function ($job) use ($transcript) {
            return $job->queue === 'transcription';
        });
    }

    /**
     * Test job is not dispatched if transcript is not pending.
     */
    public function test_job_not_dispatched_if_not_pending(): void
    {
        Queue::fake();

        // Test with completed transcript
        $completedTranscript = Transcript::factory()->completed()->create();
        $completedTranscript->dispatchTranscriptionJob();
        Queue::assertNotPushed(ProcessTranscription::class);

        // Test with processing transcript
        $processingTranscript = Transcript::factory()->processing()->create();
        $processingTranscript->dispatchTranscriptionJob();
        Queue::assertNotPushed(ProcessTranscription::class);

        // Test with failed transcript
        $failedTranscript = Transcript::factory()->failed()->create();
        $failedTranscript->dispatchTranscriptionJob();
        Queue::assertNotPushed(ProcessTranscription::class);
    }

    /**
     * Test retry functionality resets status and dispatches job.
     */
    public function test_retry_functionality(): void
    {
        Queue::fake();

        $transcript = Transcript::factory()->failed()->create();
        $this->assertEquals('failed', $transcript->status);

        $transcript->retryTranscription();

        $transcript = $transcript->fresh();
        $this->assertEquals('pending', $transcript->status);
        $this->assertNull($transcript->error_message);
        $this->assertNull($transcript->processed_at);
        $this->assertNull($transcript->transcript);

        Queue::assertPushed(ProcessTranscription::class);
    }

    /**
     * Test retry only works on failed transcripts.
     */
    public function test_retry_only_works_on_failed_transcripts(): void
    {
        Queue::fake();

        // Test with pending transcript
        $pendingTranscript = Transcript::factory()->pending()->create();
        $originalStatus = $pendingTranscript->status;
        $pendingTranscript->retryTranscription();
        $this->assertEquals($originalStatus, $pendingTranscript->fresh()->status);

        // Test with completed transcript
        $completedTranscript = Transcript::factory()->completed()->create();
        $originalStatus = $completedTranscript->status;
        $completedTranscript->retryTranscription();
        $this->assertEquals($originalStatus, $completedTranscript->fresh()->status);

        Queue::assertNotPushed(ProcessTranscription::class);
    }

    /**
     * Test job processing logs are created correctly.
     */
    public function test_job_processing_logs(): void
    {
        Log::shouldReceive('channel')->with('transcription')->andReturnSelf();
        Log::shouldReceive('info')
            ->once()
            ->with('Starting transcription processing', \Mockery::type('array'));

        Log::shouldReceive('info')
            ->once()
            ->with('Transcription processing completed successfully', \Mockery::type('array'));

        $transcript = Transcript::factory()->pending()->create();
        $job = new ProcessTranscription($transcript);

        $job->handle();
    }

    /**
     * Test job failure logs are created correctly.
     */
    public function test_job_failure_logs(): void
    {
        Log::shouldReceive('channel')->with('transcription')->andReturnSelf();
        Log::shouldReceive('error')
            ->once()
            ->with('Transcription job failed permanently', \Mockery::type('array'));

        $transcript = Transcript::factory()->pending()->create();
        $job = new ProcessTranscription($transcript);

        $exception = new Exception('Test failure');
        $job->failed($exception);
    }

    /**
     * Test transcript validation with the generated data.
     */
    public function test_transcript_validation_with_generated_data(): void
    {
        Log::shouldReceive('channel')->with('transcription')->andReturnSelf();
        Log::shouldReceive('info')->times(2);

        $transcript = Transcript::factory()->pending()->create();
        $job = new ProcessTranscription($transcript);

        $job->handle();

        $transcript = $transcript->fresh();
        $this->assertTrue(Transcript::validateTranscriptSchema($transcript->transcript));
    }
}
