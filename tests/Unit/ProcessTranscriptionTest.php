<?php

namespace Tests\Unit;

use App\Jobs\ProcessTranscription;
use App\Models\Transcript;
use App\Services\GeminiService;
use Exception;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class ProcessTranscriptionTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

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

        // Mock GeminiService
        $mockGeminiService = Mockery::mock(GeminiService::class);
        $sampleTranscriptData = $this->getSampleTranscriptData();

        $mockGeminiService->shouldReceive('transcribeMedicalDocument')
            ->once()
            ->with($transcript)
            ->andReturn($sampleTranscriptData);

        $this->assertEquals('pending', $transcript->fresh()->status);

        $job->handle($mockGeminiService);

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

        // Mock GeminiService
        $mockGeminiService = Mockery::mock(GeminiService::class);
        $sampleTranscriptData = $this->getSampleTranscriptData();

        $mockGeminiService->shouldReceive('transcribeMedicalDocument')
            ->once()
            ->with($transcript)
            ->andReturn($sampleTranscriptData);

        $job->handle($mockGeminiService);

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
        $transcript = Transcript::factory()->pending()->create();
        $job = new ProcessTranscription($transcript);

        // Mock GeminiService for error message generation
        $mockGeminiService = Mockery::mock(GeminiService::class);
        $mockGeminiService->shouldReceive('getUserFriendlyErrorMessage')
            ->once()
            ->andReturn('An unexpected error occurred during transcription. Our team has been notified. Please try again later.');

        // Force an exception during processing
        $exception = new Exception('Test exception');

        // Simulate job failure (this will log, but we don't need to mock it for this test)
        $job->failed($exception, $mockGeminiService);

        $transcript = $transcript->fresh();
        $this->assertEquals('failed', $transcript->status);
        $this->assertNotNull($transcript->error_message);
        $this->assertStringContainsString('An unexpected error occurred', $transcript->error_message);
    }

    /**
     * Test that job handles GeminiService exceptions correctly.
     */
    public function test_job_handles_gemini_service_exceptions(): void
    {
        Log::shouldReceive('channel')->with('transcription')->andReturnSelf();
        Log::shouldReceive('info')->once(); // Start processing log
        Log::shouldReceive('error')->once(); // Error log

        $transcript = Transcript::factory()->pending()->create();
        $job = new ProcessTranscription($transcript);

        // Mock GeminiService to throw an exception
        $mockGeminiService = Mockery::mock(GeminiService::class);
        $mockGeminiService->shouldReceive('transcribeMedicalDocument')
            ->once()
            ->with($transcript)
            ->andThrow(new Exception('API connection failed'));

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('API connection failed');

        $job->handle($mockGeminiService);

        // Transcript should still be in processing state since the exception is re-thrown
        $transcript = $transcript->fresh();
        $this->assertEquals('processing', $transcript->status);
    }

    /**
     * Test data fields counting function.
     */
    public function test_count_data_fields(): void
    {
        $transcript = Transcript::factory()->pending()->create();
        $job = new ProcessTranscription($transcript);

        // Mock GeminiService
        $mockGeminiService = Mockery::mock(GeminiService::class);
        $sampleData = $this->getSampleTranscriptData();

        // Add multiple items to test counting
        $sampleData['prescriptions'][] = [
            'drug_name' => 'Ibuprofen',
            'dosage' => '200mg',
            'route' => 'Oral',
            'frequency' => '2 times daily',
            'duration' => '5 days',
        ];
        $sampleData['observations'][] = 'Heart rate: 72 bpm';

        $mockGeminiService->shouldReceive('transcribeMedicalDocument')
            ->once()
            ->with($transcript)
            ->andReturn($sampleData);

        Log::shouldReceive('channel')->with('transcription')->andReturnSelf();
        Log::shouldReceive('info')->times(2);

        $job->handle($mockGeminiService);

        $transcript = $transcript->fresh();

        // The log should include data_fields_count
        // We can't easily test the private method directly, but the job should complete successfully
        $this->assertEquals('completed', $transcript->status);
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

        $this->addToAssertionCount(3);
        $transcript = Transcript::factory()->pending()->create();
        $job = new ProcessTranscription($transcript);

        // Mock GeminiService
        $mockGeminiService = Mockery::mock(GeminiService::class);
        $mockGeminiService->shouldReceive('transcribeMedicalDocument')
            ->once()
            ->andReturn($this->getSampleTranscriptData());

        $job->handle($mockGeminiService);
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

        // Mock GeminiService
        $mockGeminiService = Mockery::mock(GeminiService::class);
        $mockGeminiService->shouldReceive('getUserFriendlyErrorMessage')
            ->once()
            ->andReturn('Test error message');

        $exception = new Exception('Test failure');
        $job->failed($exception, $mockGeminiService);
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

        // Mock GeminiService
        $mockGeminiService = Mockery::mock(GeminiService::class);
        $mockGeminiService->shouldReceive('transcribeMedicalDocument')
            ->once()
            ->andReturn($this->getSampleTranscriptData());

        $job->handle($mockGeminiService);

        $transcript = $transcript->fresh();
        $this->assertTrue(Transcript::validateTranscriptSchema($transcript->transcript));
    }

    /**
     * Get sample transcript data for testing.
     */
    private function getSampleTranscriptData(): array
    {
        return [
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
    }
}
