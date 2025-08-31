<?php

namespace Database\Seeders;

use App\Models\Transcript;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class TranscriptSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Create transcripts in various states for development and testing

        // Create 10 pending transcripts
        Transcript::factory()
            ->count(10)
            ->create();

        // Create 5 processing transcripts
        Transcript::factory()
            ->processing()
            ->count(5)
            ->create();

        // Create 15 completed transcripts
        Transcript::factory()
            ->completed()
            ->count(15)
            ->create();

        // Create 3 failed transcripts
        Transcript::factory()
            ->failed()
            ->count(3)
            ->create();

        // Create a specific example transcript for demonstration
        Transcript::create([
            'title' => 'Sample Medical Prescription - Dr. Smith',
            'description' => 'Sample prescription for patient John Doe with hypertension diagnosis',
            'image' => 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==',
            'transcript' => [
                'patient' => [
                    'name' => 'John Doe',
                    'age' => 45,
                    'gender' => 'Male',
                ],
                'date' => '2025-08-31',
                'prescriptions' => [
                    [
                        'drug_name' => 'Lisinopril',
                        'dosage' => '10mg',
                        'route' => 'Oral',
                        'frequency' => 'Once daily',
                        'duration' => '30 days',
                        'notes' => 'Take with food to reduce stomach upset',
                    ],
                    [
                        'drug_name' => 'Aspirin',
                        'dosage' => '81mg',
                        'route' => 'Oral',
                        'frequency' => 'Once daily',
                        'duration' => '30 days',
                        'notes' => 'Low-dose aspirin for cardioprotection',
                    ],
                ],
                'diagnoses' => [
                    [
                        'condition' => 'Essential Hypertension',
                        'notes' => 'Stage 1 hypertension, well controlled with medication',
                    ],
                ],
                'observations' => [
                    'Patient reports feeling well with current medication regimen',
                    'Blood pressure readings have been stable over past 3 months',
                ],
                'tests' => [
                    [
                        'test_name' => 'Blood Pressure',
                        'result' => '128/82 mmHg',
                        'normal_range' => '<120/80 mmHg',
                        'notes' => 'Slightly elevated but improved from previous visit',
                    ],
                    [
                        'test_name' => 'Basic Metabolic Panel',
                        'result' => 'Normal',
                        'normal_range' => 'Within normal limits',
                        'notes' => null,
                    ],
                ],
                'instructions' => 'Continue current medications as prescribed. Monitor blood pressure at home daily. Return for follow-up in 3 months or sooner if symptoms develop. Maintain low-sodium diet and regular exercise.',
                'doctor' => [
                    'name' => 'Dr. Sarah Smith',
                    'signature' => 'S.Smith',
                ],
            ],
            'status' => Transcript::STATUS_COMPLETED,
            'processed_at' => now()->subDays(2),
        ]);
    }
}
