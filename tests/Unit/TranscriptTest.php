<?php

use App\Models\Transcript;
use App\Models\User;

it('can be created with required attributes', function () {
    $transcript = Transcript::factory()->create([
        'title' => 'Test Transcript',
        'description' => 'Test Description',
        'image' => 'data:image/png;base64,test',
        'status' => Transcript::STATUS_PENDING,
    ]);

    expect($transcript)->toBeInstanceOf(Transcript::class);
    expect($transcript->user_id)->not->toBeNull();
    expect($transcript->title)->toBe('Test Transcript');
    expect($transcript->description)->toBe('Test Description');
    expect($transcript->image)->toBe('data:image/png;base64,test');
    expect($transcript->status)->toBe(Transcript::STATUS_PENDING);
    expect($transcript->transcript)->toBeNull();
    expect($transcript->error_message)->toBeNull();
    expect($transcript->processed_at)->toBeNull();
});

it('can be created without optional attributes', function () {
    $transcript = Transcript::factory()->create([
        'title' => 'Test Transcript',
        'description' => null,
        'image' => 'data:image/png;base64,test',
        'status' => Transcript::STATUS_PENDING,
    ]);

    expect($transcript)->toBeInstanceOf(Transcript::class);
    expect($transcript->user_id)->not->toBeNull();
    expect($transcript->title)->toBe('Test Transcript');
    expect($transcript->description)->toBeNull();
    expect($transcript->status)->toBe(Transcript::STATUS_PENDING);
});

it('casts transcript attribute as array', function () {
    $transcriptData = [
        'patient' => ['name' => 'John Doe', 'age' => 30, 'gender' => 'Male'],
        'date' => '2025-08-31',
        'prescriptions' => [],
        'diagnoses' => [],
        'observations' => [],
        'tests' => [],
        'instructions' => 'Follow up in 2 weeks',
        'doctor' => ['name' => 'Dr. Smith', 'signature' => 'S.Smith'],
    ];

    $transcript = Transcript::factory()->create([
        'title' => 'Test Transcript',
        'image' => 'data:image/png;base64,test',
        'status' => Transcript::STATUS_COMPLETED,
        'transcript' => $transcriptData,
    ]);

    expect($transcript->transcript)->toBeArray();
    expect($transcript->transcript['patient']['name'])->toBe('John Doe');
});

it('has soft deletes enabled', function () {
    $transcript = Transcript::factory()->create();
    $transcript->delete();

    expect($transcript->trashed())->toBeTrue();
    expect(Transcript::count())->toBe(0);
    expect(Transcript::withTrashed()->count())->toBe(1);
});

it('has correct status constants', function () {
    expect(Transcript::STATUS_PENDING)->toBe('pending');
    expect(Transcript::STATUS_PROCESSING)->toBe('processing');
    expect(Transcript::STATUS_COMPLETED)->toBe('completed');
    expect(Transcript::STATUS_FAILED)->toBe('failed');
});

it('returns all status options', function () {
    $statusOptions = Transcript::getStatusOptions();
    expect($statusOptions)->toBeArray()->and($statusOptions)->toHaveCount(4);
    expect($statusOptions)->toContain('pending', 'processing', 'completed', 'failed');
});

it('can check status methods', function () {
    $transcript = Transcript::factory()->create(['status' => Transcript::STATUS_PENDING]);

    expect($transcript->isPending())->toBeTrue();
    expect($transcript->isProcessing())->toBeFalse();
    expect($transcript->isCompleted())->toBeFalse();
    expect($transcript->isFailed())->toBeFalse();
});

it('can mark transcript as processing', function () {
    $transcript = Transcript::factory()->create(['status' => Transcript::STATUS_PENDING]);

    $result = $transcript->markAsProcessing();

    expect($result)->toBeTrue();
    expect($transcript->fresh()->status)->toBe(Transcript::STATUS_PROCESSING);
    expect($transcript->fresh()->isProcessing())->toBeTrue();
});

it('can mark transcript as completed with data', function () {
    $transcript = Transcript::factory()->create(['status' => Transcript::STATUS_PENDING]);
    $transcriptData = [
        'patient' => ['name' => 'Jane Doe', 'age' => 25, 'gender' => 'Female'],
        'date' => '2025-08-31',
        'prescriptions' => [],
        'diagnoses' => [],
        'observations' => [],
        'tests' => [],
        'instructions' => 'Take medication as prescribed',
        'doctor' => ['name' => 'Dr. Johnson', 'signature' => 'J.Johnson'],
    ];

    $result = $transcript->markAsCompleted($transcriptData);
    $updatedTranscript = $transcript->fresh();

    expect($result)->toBeTrue();
    expect($updatedTranscript->status)->toBe(Transcript::STATUS_COMPLETED);
    expect($updatedTranscript->transcript)->toBeArray();
    expect($updatedTranscript->transcript['patient']['name'])->toBe('Jane Doe');
    expect($updatedTranscript->processed_at)->not->toBeNull();
    expect($updatedTranscript->error_message)->toBeNull();
    expect($updatedTranscript->isCompleted())->toBeTrue();
});

it('can mark transcript as failed with error message', function () {
    $transcript = Transcript::factory()->create(['status' => Transcript::STATUS_PENDING]);
    $errorMessage = 'API_ERROR: Failed to process image';

    $result = $transcript->markAsFailed($errorMessage);
    $updatedTranscript = $transcript->fresh();

    expect($result)->toBeTrue();
    expect($updatedTranscript->status)->toBe(Transcript::STATUS_FAILED);
    expect($updatedTranscript->error_message)->toBe($errorMessage);
    expect($updatedTranscript->isFailed())->toBeTrue();
});

it('can reset transcript to pending', function () {
    $transcript = Transcript::factory()->create(['status' => Transcript::STATUS_PENDING]);

    $transcript->markAsCompleted([
        'patient' => ['name' => 'Test', 'age' => 30, 'gender' => 'Male'],
        'date' => '2025-08-31',
        'prescriptions' => [],
        'diagnoses' => [],
        'observations' => [],
        'tests' => [],
        'instructions' => 'Test',
        'doctor' => ['name' => 'Dr. Test', 'signature' => 'Test'],
    ]);

    $result = $transcript->resetToPending();
    $updatedTranscript = $transcript->fresh();

    expect($result)->toBeTrue();
    expect($updatedTranscript->status)->toBe(Transcript::STATUS_PENDING);
    expect($updatedTranscript->transcript)->toBeNull();
    expect($updatedTranscript->error_message)->toBeNull();
    expect($updatedTranscript->processed_at)->toBeNull();
    expect($updatedTranscript->isPending())->toBeTrue();
});

it('scopes filter transcripts by status', function () {
    Transcript::factory()->count(3)->create(['status' => Transcript::STATUS_PENDING]);
    Transcript::factory()->count(2)->create(['status' => Transcript::STATUS_PROCESSING]);
    Transcript::factory()->completed()->count(4)->create();
    Transcript::factory()->failed()->count(1)->create();

    expect(Transcript::withStatus(Transcript::STATUS_PENDING)->count())->toBe(3);
    expect(Transcript::withStatus(Transcript::STATUS_PROCESSING)->count())->toBe(2);
    expect(Transcript::pending()->count())->toBe(3);
    expect(Transcript::processing()->count())->toBe(2);
    expect(Transcript::completed()->count())->toBe(4);
    expect(Transcript::failed()->count())->toBe(1);
});

it('validates complete valid transcript data', function () {
    $validData = [
        'patient' => [
            'name' => 'John Doe',
            'age' => 35,
            'gender' => 'Male',
        ],
        'date' => '2025-08-31',
        'prescriptions' => [
            [
                'drug_name' => 'Aspirin',
                'dosage' => '81mg',
                'route' => 'Oral',
                'frequency' => 'Once daily',
                'duration' => '30 days',
                'notes' => 'With food',
            ],
        ],
        'diagnoses' => [
            [
                'condition' => 'Hypertension',
                'notes' => 'Stage 1',
            ],
        ],
        'observations' => [
            'Patient appears well',
            'No acute distress',
        ],
        'tests' => [
            [
                'test_name' => 'Blood Pressure',
                'result' => '130/85',
                'normal_range' => '<120/80',
                'notes' => 'Elevated',
            ],
        ],
        'instructions' => 'Take medication as prescribed and return in 4 weeks',
        'doctor' => [
            'name' => 'Dr. Sarah Johnson',
            'signature' => 'S.Johnson',
        ],
    ];

    expect(Transcript::validateTranscriptSchema($validData))->toBeTrue();
});

it('rejects transcript data missing required top level keys', function () {
    $incompleteData = [
        'patient' => ['name' => 'John', 'age' => 30, 'gender' => 'Male'],
        'date' => '2025-08-31',
    ];

    expect(Transcript::validateTranscriptSchema($incompleteData))->toBeFalse();
});

it('rejects transcript data with invalid patient structure', function () {
    $invalidPatientData = [
        'patient' => [
            'name' => 'John Doe',
        ],
        'date' => '2025-08-31',
        'prescriptions' => [],
        'diagnoses' => [],
        'observations' => [],
        'tests' => [],
        'instructions' => 'Test',
        'doctor' => ['name' => 'Dr. Test', 'signature' => 'Test'],
    ];

    expect(Transcript::validateTranscriptSchema($invalidPatientData))->toBeFalse();
});

it('rejects transcript data with invalid doctor structure', function () {
    $invalidDoctorData = [
        'patient' => ['name' => 'John', 'age' => 30, 'gender' => 'Male'],
        'date' => '2025-08-31',
        'prescriptions' => [],
        'diagnoses' => [],
        'observations' => [],
        'tests' => [],
        'instructions' => 'Test',
        'doctor' => [
            'name' => 'Dr. Test',
        ],
    ];

    expect(Transcript::validateTranscriptSchema($invalidDoctorData))->toBeFalse();
});

it('rejects transcript data with invalid prescription structure', function () {
    $invalidPrescriptionData = [
        'patient' => ['name' => 'John', 'age' => 30, 'gender' => 'Male'],
        'date' => '2025-08-31',
        'prescriptions' => [
            [
                'drug_name' => 'Aspirin',
            ],
        ],
        'diagnoses' => [],
        'observations' => [],
        'tests' => [],
        'instructions' => 'Test',
        'doctor' => ['name' => 'Dr. Test', 'signature' => 'Test'],
    ];

    expect(Transcript::validateTranscriptSchema($invalidPrescriptionData))->toBeFalse();
});

it('rejects transcript data with invalid diagnoses structure', function () {
    $invalidDiagnosesData = [
        'patient' => ['name' => 'John', 'age' => 30, 'gender' => 'Male'],
        'date' => '2025-08-31',
        'prescriptions' => [],
        'diagnoses' => [
            [
                'notes' => 'Some notes',
            ],
        ],
        'observations' => [],
        'tests' => [],
        'instructions' => 'Test',
        'doctor' => ['name' => 'Dr. Test', 'signature' => 'Test'],
    ];

    expect(Transcript::validateTranscriptSchema($invalidDiagnosesData))->toBeFalse();
});

it('rejects transcript data with invalid tests structure', function () {
    $invalidTestsData = [
        'patient' => ['name' => 'John', 'age' => 30, 'gender' => 'Male'],
        'date' => '2025-08-31',
        'prescriptions' => [],
        'diagnoses' => [],
        'observations' => [],
        'tests' => [
            [
                'result' => 'Normal',
            ],
        ],
        'instructions' => 'Test',
        'doctor' => ['name' => 'Dr. Test', 'signature' => 'Test'],
    ];

    expect(Transcript::validateTranscriptSchema($invalidTestsData))->toBeFalse();
});

it('returns null for formatted transcript when not completed', function () {
    $transcript = Transcript::factory()->create(['status' => Transcript::STATUS_PENDING]);
    expect($transcript->getFormattedTranscript())->toBeNull();
});

it('returns null for formatted transcript when transcript data is null', function () {
    $transcript = Transcript::factory()->create([
        'status' => Transcript::STATUS_COMPLETED,
        'transcript' => null,
    ]);

    expect($transcript->getFormattedTranscript())->toBeNull();
});

it('returns transcript data when completed with valid data', function () {
    $transcriptData = [
        'patient' => ['name' => 'Test Patient', 'age' => 30, 'gender' => 'Male'],
        'date' => '2025-08-31',
        'prescriptions' => [],
        'diagnoses' => [],
        'observations' => [],
        'tests' => [],
        'instructions' => 'Test instructions',
        'doctor' => ['name' => 'Dr. Test', 'signature' => 'Test'],
    ];

    $transcript = Transcript::factory()->create([
        'status' => Transcript::STATUS_COMPLETED,
        'transcript' => $transcriptData,
    ]);

    expect($transcript->getFormattedTranscript())->toBeArray();
    expect($transcript->getFormattedTranscript()['patient']['name'])->toBe('Test Patient');
});

it('transcript belongs to user', function () {
    $user = User::factory()->create();
    $transcript = Transcript::factory()->for($user)->create();

    expect($transcript->user_id)->toBe($user->id);
    expect($transcript->user)->toBeInstanceOf(User::class);
    expect($transcript->user->name)->toBe($user->name);
});

it('user has many transcripts', function () {
    $user = User::factory()->create();
    Transcript::factory()->for($user)->count(3)->create();

    expect($user->transcripts)->toHaveCount(3);
    expect($user->transcripts->first())->toBeInstanceOf(Transcript::class);
});
