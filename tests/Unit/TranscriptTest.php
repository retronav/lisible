<?php

namespace Tests\Unit;

use App\Models\Transcript;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TranscriptTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_be_created_with_required_attributes()
    {
        $transcript = Transcript::factory()->create([
            'title' => 'Test Transcript',
            'description' => 'Test Description',
            'image' => 'data:image/png;base64,test',
            'status' => Transcript::STATUS_PENDING,
        ]);

        $this->assertInstanceOf(Transcript::class, $transcript);
        $this->assertNotNull($transcript->user_id);
        $this->assertEquals('Test Transcript', $transcript->title);
        $this->assertEquals('Test Description', $transcript->description);
        $this->assertEquals('data:image/png;base64,test', $transcript->image);
        $this->assertEquals(Transcript::STATUS_PENDING, $transcript->status);
        $this->assertNull($transcript->transcript);
        $this->assertNull($transcript->error_message);
        $this->assertNull($transcript->processed_at);
    }

    public function test_can_be_created_without_optional_attributes()
    {
        $transcript = Transcript::factory()->create([
            'title' => 'Test Transcript',
            'description' => null, // Explicitly set to null
            'image' => 'data:image/png;base64,test',
            'status' => Transcript::STATUS_PENDING,
        ]);

        $this->assertInstanceOf(Transcript::class, $transcript);
        $this->assertNotNull($transcript->user_id);
        $this->assertEquals('Test Transcript', $transcript->title);
        $this->assertNull($transcript->description);
        $this->assertEquals(Transcript::STATUS_PENDING, $transcript->status);
    }

    public function test_casts_transcript_attribute_as_array()
    {
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

        $this->assertIsArray($transcript->transcript);
        $this->assertEquals('John Doe', $transcript->transcript['patient']['name']);
    }

    public function test_has_soft_deletes_enabled()
    {
        $transcript = Transcript::factory()->create();

        $transcript->delete();

        $this->assertTrue($transcript->trashed());
        $this->assertEquals(0, Transcript::count());
        $this->assertEquals(1, Transcript::withTrashed()->count());
    }

    public function test_has_correct_status_constants()
    {
        $this->assertEquals('pending', Transcript::STATUS_PENDING);
        $this->assertEquals('processing', Transcript::STATUS_PROCESSING);
        $this->assertEquals('completed', Transcript::STATUS_COMPLETED);
        $this->assertEquals('failed', Transcript::STATUS_FAILED);
    }

    public function test_returns_all_status_options()
    {
        $statusOptions = Transcript::getStatusOptions();

        $this->assertIsArray($statusOptions);
        $this->assertCount(4, $statusOptions);
        $this->assertContains('pending', $statusOptions);
        $this->assertContains('processing', $statusOptions);
        $this->assertContains('completed', $statusOptions);
        $this->assertContains('failed', $statusOptions);
    }

    public function test_can_check_status_methods()
    {
        $transcript = Transcript::factory()->create(['status' => Transcript::STATUS_PENDING]);

        $this->assertTrue($transcript->isPending());
        $this->assertFalse($transcript->isProcessing());
        $this->assertFalse($transcript->isCompleted());
        $this->assertFalse($transcript->isFailed());
    }

    public function test_can_mark_transcript_as_processing()
    {
        $transcript = Transcript::factory()->create(['status' => Transcript::STATUS_PENDING]);

        $result = $transcript->markAsProcessing();

        $this->assertTrue($result);
        $this->assertEquals(Transcript::STATUS_PROCESSING, $transcript->fresh()->status);
        $this->assertTrue($transcript->fresh()->isProcessing());
    }

    public function test_can_mark_transcript_as_completed_with_data()
    {
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

        $this->assertTrue($result);
        $this->assertEquals(Transcript::STATUS_COMPLETED, $updatedTranscript->status);
        $this->assertIsArray($updatedTranscript->transcript);
        $this->assertEquals('Jane Doe', $updatedTranscript->transcript['patient']['name']);
        $this->assertNotNull($updatedTranscript->processed_at);
        $this->assertNull($updatedTranscript->error_message);
        $this->assertTrue($updatedTranscript->isCompleted());
    }

    public function test_can_mark_transcript_as_failed_with_error_message()
    {
        $transcript = Transcript::factory()->create(['status' => Transcript::STATUS_PENDING]);
        $errorMessage = 'API_ERROR: Failed to process image';

        $result = $transcript->markAsFailed($errorMessage);
        $updatedTranscript = $transcript->fresh();

        $this->assertTrue($result);
        $this->assertEquals(Transcript::STATUS_FAILED, $updatedTranscript->status);
        $this->assertEquals($errorMessage, $updatedTranscript->error_message);
        $this->assertTrue($updatedTranscript->isFailed());
    }

    public function test_can_reset_transcript_to_pending()
    {
        $transcript = Transcript::factory()->create(['status' => Transcript::STATUS_PENDING]);

        // First mark as completed with data
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

        // Then reset to pending
        $result = $transcript->resetToPending();
        $updatedTranscript = $transcript->fresh();

        $this->assertTrue($result);
        $this->assertEquals(Transcript::STATUS_PENDING, $updatedTranscript->status);
        $this->assertNull($updatedTranscript->transcript);
        $this->assertNull($updatedTranscript->error_message);
        $this->assertNull($updatedTranscript->processed_at);
        $this->assertTrue($updatedTranscript->isPending());
    }

    public function test_scopes_filter_transcripts_by_status()
    {
        // Create transcripts in different states
        Transcript::factory()->count(3)->create(['status' => Transcript::STATUS_PENDING]);
        Transcript::factory()->count(2)->create(['status' => Transcript::STATUS_PROCESSING]);
        Transcript::factory()->completed()->count(4)->create();
        Transcript::factory()->failed()->count(1)->create();

        $this->assertEquals(3, Transcript::withStatus(Transcript::STATUS_PENDING)->count());
        $this->assertEquals(2, Transcript::withStatus(Transcript::STATUS_PROCESSING)->count());
        $this->assertEquals(3, Transcript::pending()->count());
        $this->assertEquals(2, Transcript::processing()->count());
        $this->assertEquals(4, Transcript::completed()->count());
        $this->assertEquals(1, Transcript::failed()->count());
    }

    public function test_validates_complete_valid_transcript_data()
    {
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

        $this->assertTrue(Transcript::validateTranscriptSchema($validData));
    }

    public function test_rejects_transcript_data_missing_required_top_level_keys()
    {
        $incompleteData = [
            'patient' => ['name' => 'John', 'age' => 30, 'gender' => 'Male'],
            'date' => '2025-08-31',
            // Missing other required keys
        ];

        $this->assertFalse(Transcript::validateTranscriptSchema($incompleteData));
    }

    public function test_rejects_transcript_data_with_invalid_patient_structure()
    {
        $invalidPatientData = [
            'patient' => [
                'name' => 'John Doe',
                // Missing age and gender
            ],
            'date' => '2025-08-31',
            'prescriptions' => [],
            'diagnoses' => [],
            'observations' => [],
            'tests' => [],
            'instructions' => 'Test',
            'doctor' => ['name' => 'Dr. Test', 'signature' => 'Test'],
        ];

        $this->assertFalse(Transcript::validateTranscriptSchema($invalidPatientData));
    }

    public function test_rejects_transcript_data_with_invalid_doctor_structure()
    {
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
                // Missing signature
            ],
        ];

        $this->assertFalse(Transcript::validateTranscriptSchema($invalidDoctorData));
    }

    public function test_rejects_transcript_data_with_invalid_prescription_structure()
    {
        $invalidPrescriptionData = [
            'patient' => ['name' => 'John', 'age' => 30, 'gender' => 'Male'],
            'date' => '2025-08-31',
            'prescriptions' => [
                [
                    'drug_name' => 'Aspirin',
                    // Missing required fields
                ],
            ],
            'diagnoses' => [],
            'observations' => [],
            'tests' => [],
            'instructions' => 'Test',
            'doctor' => ['name' => 'Dr. Test', 'signature' => 'Test'],
        ];

        $this->assertFalse(Transcript::validateTranscriptSchema($invalidPrescriptionData));
    }

    public function test_rejects_transcript_data_with_invalid_diagnoses_structure()
    {
        $invalidDiagnosesData = [
            'patient' => ['name' => 'John', 'age' => 30, 'gender' => 'Male'],
            'date' => '2025-08-31',
            'prescriptions' => [],
            'diagnoses' => [
                [
                    // Missing condition
                    'notes' => 'Some notes',
                ],
            ],
            'observations' => [],
            'tests' => [],
            'instructions' => 'Test',
            'doctor' => ['name' => 'Dr. Test', 'signature' => 'Test'],
        ];

        $this->assertFalse(Transcript::validateTranscriptSchema($invalidDiagnosesData));
    }

    public function test_rejects_transcript_data_with_invalid_tests_structure()
    {
        $invalidTestsData = [
            'patient' => ['name' => 'John', 'age' => 30, 'gender' => 'Male'],
            'date' => '2025-08-31',
            'prescriptions' => [],
            'diagnoses' => [],
            'observations' => [],
            'tests' => [
                [
                    // Missing test_name
                    'result' => 'Normal',
                ],
            ],
            'instructions' => 'Test',
            'doctor' => ['name' => 'Dr. Test', 'signature' => 'Test'],
        ];

        $this->assertFalse(Transcript::validateTranscriptSchema($invalidTestsData));
    }

    public function test_returns_null_for_formatted_transcript_when_not_completed()
    {
        $transcript = Transcript::factory()->create(['status' => Transcript::STATUS_PENDING]);

        $this->assertNull($transcript->getFormattedTranscript());
    }

    public function test_returns_null_for_formatted_transcript_when_transcript_data_is_null()
    {
        $transcript = Transcript::factory()->create([
            'status' => Transcript::STATUS_COMPLETED,
            'transcript' => null,
        ]);

        $this->assertNull($transcript->getFormattedTranscript());
    }

    public function test_returns_transcript_data_when_completed_with_valid_data()
    {
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

        $this->assertIsArray($transcript->getFormattedTranscript());
        $this->assertEquals('Test Patient', $transcript->getFormattedTranscript()['patient']['name']);
    }

    public function test_transcript_belongs_to_user()
    {
        $user = \App\Models\User::factory()->create();
        $transcript = Transcript::factory()->for($user)->create();

        $this->assertEquals($user->id, $transcript->user_id);
        $this->assertInstanceOf(\App\Models\User::class, $transcript->user);
        $this->assertEquals($user->name, $transcript->user->name);
    }

    public function test_user_has_many_transcripts()
    {
        $user = \App\Models\User::factory()->create();
        $transcripts = Transcript::factory()->for($user)->count(3)->create();

        $this->assertCount(3, $user->transcripts);
        $this->assertInstanceOf(Transcript::class, $user->transcripts->first());
    }
}
