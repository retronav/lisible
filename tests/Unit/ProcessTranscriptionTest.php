<?php

use App\Jobs\ProcessTranscription;
use App\Models\Transcript;
use App\Services\GeminiService;
use Exception;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Mockery;

// Helper to provide sample transcript data
function sampleTranscriptData(): array {
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

afterEach(function () {
    Mockery::close();
});

it('job can be instantiated', function () {
    $transcript = Transcript::factory()->create();
    $job = new ProcessTranscription($transcript);

    expect($job)->toBeInstanceOf(ProcessTranscription::class);
    expect($job->queue)->toBe('transcription');
    expect($job->timeout)->toBe(300);
    expect($job->tries)->toBe(3);
    expect($job->backoff())->toBe([30, 60, 120]);
});

it('sets job tags correctly', function () {
    $transcript = Transcript::factory()->create();
    $job = new ProcessTranscription($transcript);

    $expectedTags = ['transcription', 'transcript:' . $transcript->id];
    expect($job->tags())->toBe($expectedTags);
});

it('updates status correctly on successful execution', function () {
    Log::shouldReceive('channel')->with('transcription')->andReturnSelf();
    Log::shouldReceive('info')->times(2);

    $transcript = Transcript::factory()->pending()->create();
    $job = new ProcessTranscription($transcript);

    $mockGeminiService = Mockery::mock(GeminiService::class);
    $mockGeminiService->shouldReceive('transcribeMedicalDocument')
        ->once()
        ->with($transcript)
        ->andReturn(sampleTranscriptData());

    expect($transcript->fresh()->status)->toBe('pending');

    $job->handle($mockGeminiService);

    $transcript = $transcript->fresh();
    expect($transcript->status)->toBe('completed');
    expect($transcript->processed_at)->not->toBeNull();
    expect($transcript->error_message)->toBeNull();
    expect($transcript->transcript)->toBeArray();
});

it('produces valid json structure after completion', function () {
    Log::shouldReceive('channel')->with('transcription')->andReturnSelf();
    Log::shouldReceive('info')->times(2);

    $transcript = Transcript::factory()->pending()->create();
    $job = new ProcessTranscription($transcript);

    $mockGeminiService = Mockery::mock(GeminiService::class);
    $mockGeminiService->shouldReceive('transcribeMedicalDocument')
        ->once()
        ->with($transcript)
        ->andReturn(sampleTranscriptData());

    $job->handle($mockGeminiService);

    $transcriptData = $transcript->fresh()->transcript;
    $requiredKeys = ['patient', 'date', 'prescriptions', 'diagnoses', 'observations', 'tests', 'instructions', 'doctor'];
    foreach ($requiredKeys as $key) {
        expect($transcriptData)->toHaveKey($key);
    }
    expect($transcriptData['patient'])->toHaveKeys(['name','age','gender']);
    expect($transcriptData['doctor'])->toHaveKeys(['name','signature']);
    expect($transcriptData['prescriptions'])->toBeArray();
    expect($transcriptData['diagnoses'])->toBeArray();
    expect($transcriptData['observations'])->toBeArray();
    expect($transcriptData['tests'])->toBeArray();
});

it('updates status on failure', function () {
    $transcript = Transcript::factory()->pending()->create();
    $job = new ProcessTranscription($transcript);

    $mockGeminiService = Mockery::mock(GeminiService::class);
    $mockGeminiService->shouldReceive('getUserFriendlyErrorMessage')
        ->once()
        ->andReturn('An unexpected error occurred during transcription. Our team has been notified. Please try again later.');

    $exception = new Exception('Test exception');
    $job->failed($exception, $mockGeminiService);

    $transcript = $transcript->fresh();
    expect($transcript->status)->toBe('failed');
    expect($transcript->error_message)->not->toBeNull();
    expect($transcript->error_message)->toContain('An unexpected error occurred');
});

it('handles gemini service exceptions', function () {
    Log::shouldReceive('channel')->with('transcription')->andReturnSelf();
    Log::shouldReceive('info')->once();
    Log::shouldReceive('error')->once();

    $transcript = Transcript::factory()->pending()->create();
    $job = new ProcessTranscription($transcript);

    $mockGeminiService = Mockery::mock(GeminiService::class);
    $mockGeminiService->shouldReceive('transcribeMedicalDocument')
        ->once()
        ->with($transcript)
        ->andThrow(new Exception('API connection failed'));

    $this->expectException(Exception::class);
    $this->expectExceptionMessage('API connection failed');

    $job->handle($mockGeminiService);

    $transcript = $transcript->fresh();
    expect($transcript->status)->toBe('processing');
});

it('counts data fields and completes', function () {
    $transcript = Transcript::factory()->pending()->create();
    $job = new ProcessTranscription($transcript);

    $mockGeminiService = Mockery::mock(GeminiService::class);
    $sampleData = sampleTranscriptData();
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

    expect($transcript->fresh()->status)->toBe('completed');
});

it('can be queued', function () {
    Queue::fake();

    $transcript = Transcript::factory()->pending()->create();
    $transcript->dispatchTranscriptionJob();

    Queue::assertPushed(ProcessTranscription::class, function ($job) use ($transcript) {
        return $job->queue === 'transcription';
    });
});

it('is not queued if not pending', function () {
    Queue::fake();

    Transcript::factory()->completed()->create()->dispatchTranscriptionJob();
    Transcript::factory()->processing()->create()->dispatchTranscriptionJob();
    Transcript::factory()->failed()->create()->dispatchTranscriptionJob();

    Queue::assertNotPushed(ProcessTranscription::class);
});

it('retry resets status and dispatches job', function () {
    Queue::fake();

    $transcript = Transcript::factory()->failed()->create();
    expect($transcript->status)->toBe('failed');

    $transcript->retryTranscription();

    $transcript = $transcript->fresh();
    expect($transcript->status)->toBe('pending');
    expect($transcript->error_message)->toBeNull();
    expect($transcript->processed_at)->toBeNull();
    expect($transcript->transcript)->toBeNull();

    Queue::assertPushed(ProcessTranscription::class);
});

it('retry only works on failed transcripts', function () {
    Queue::fake();

    $pendingTranscript = Transcript::factory()->pending()->create();
    $originalStatus = $pendingTranscript->status;
    $pendingTranscript->retryTranscription();
    expect($pendingTranscript->fresh()->status)->toBe($originalStatus);

    $completedTranscript = Transcript::factory()->completed()->create();
    $originalStatus = $completedTranscript->status;
    $completedTranscript->retryTranscription();
    expect($completedTranscript->fresh()->status)->toBe($originalStatus);

    Queue::assertNotPushed(ProcessTranscription::class);
});

it('creates processing logs', function () {
    Log::shouldReceive('channel')->with('transcription')->andReturnSelf();
    Log::shouldReceive('info')
        ->once()
        ->with('Starting transcription processing', Mockery::type('array'));

    Log::shouldReceive('info')
        ->once()
        ->with('Transcription processing completed successfully', Mockery::type('array'));

    // Add a few assertions implicit via logs and run
    $transcript = Transcript::factory()->pending()->create();
    $job = new ProcessTranscription($transcript);

    $mockGeminiService = Mockery::mock(GeminiService::class);
    $mockGeminiService->shouldReceive('transcribeMedicalDocument')
        ->once()
        ->andReturn(sampleTranscriptData());

    $job->handle($mockGeminiService);
    expect(true)->toBeTrue();
});

it('creates failure logs', function () {
    Log::shouldReceive('channel')->with('transcription')->andReturnSelf();
    Log::shouldReceive('error')
        ->once()
        ->with('Transcription job failed permanently', Mockery::type('array'));

    $transcript = Transcript::factory()->pending()->create();
    $job = new ProcessTranscription($transcript);

    $mockGeminiService = Mockery::mock(GeminiService::class);
    $mockGeminiService->shouldReceive('getUserFriendlyErrorMessage')
        ->once()
        ->andReturn('Test error message');

    $exception = new Exception('Test failure');
    $job->failed($exception, $mockGeminiService);
});

it('validates transcript with generated data', function () {
    Log::shouldReceive('channel')->with('transcription')->andReturnSelf();
    Log::shouldReceive('info')->times(2);

    $transcript = Transcript::factory()->pending()->create();
    $job = new ProcessTranscription($transcript);

    $mockGeminiService = Mockery::mock(GeminiService::class);
    $mockGeminiService->shouldReceive('transcribeMedicalDocument')
        ->once()
        ->andReturn(sampleTranscriptData());

    $job->handle($mockGeminiService);

    $transcript = $transcript->fresh();
    expect(Transcript::validateTranscriptSchema($transcript->transcript))->toBeTrue();
});
