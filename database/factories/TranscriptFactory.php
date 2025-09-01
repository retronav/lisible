<?php

namespace Database\Factories;

use App\Models\Transcript;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Transcript>
 */
class TranscriptFactory extends Factory
{
    protected $model = Transcript::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'title' => $this->faker->sentence(3),
            'description' => $this->faker->optional()->paragraph(),
            'image' => $this->generateSampleImageData(),
            'transcript' => null,
            'status' => Transcript::STATUS_PENDING,
            'error_message' => null,
            'processed_at' => null,
        ];
    }

    /**
     * Indicate that the transcript is processing.
     */
    public function processing(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => Transcript::STATUS_PROCESSING,
        ]);
    }

    /**
     * Indicate that the transcript is pending (default state).
     */
    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => Transcript::STATUS_PENDING,
        ]);
    }

    /**
     * Indicate that the transcript is completed.
     */
    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => Transcript::STATUS_COMPLETED,
            'transcript' => $this->generateSampleTranscriptData(),
            'processed_at' => $this->faker->dateTimeBetween('-1 week', 'now'),
        ]);
    }

    /**
     * Indicate that the transcript has failed.
     */
    public function failed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => Transcript::STATUS_FAILED,
            'error_message' => $this->faker->randomElement([
                'API_ERROR: Failed to process image with Gemini API',
                'NETWORK_ERROR: Network connectivity issues during processing',
                'VALIDATION_ERROR: Image format not supported',
                'TIMEOUT_ERROR: Processing exceeded timeout limit',
                'IMAGE_ERROR: Image file corrupted or unreadable',
            ]),
        ]);
    }

    /**
     * Generate sample image data (base64 placeholder).
     */
    private function generateSampleImageData(): string
    {
        // This is a small 1x1 pixel transparent PNG in base64
        return 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';
    }

    /**
     * Generate sample transcript data following the defined JSON schema.
     */
    private function generateSampleTranscriptData(): array
    {
        return [
            'patient' => [
                'name' => $this->faker->name(),
                'age' => $this->faker->numberBetween(18, 90),
                'gender' => $this->faker->randomElement(['Male', 'Female', 'Other']),
            ],
            'date' => $this->faker->date('Y-m-d'),
            'prescriptions' => [
                [
                    'drug_name' => $this->faker->randomElement(['Amoxicillin', 'Ibuprofen', 'Paracetamol', 'Aspirin']),
                    'dosage' => $this->faker->randomElement(['500mg', '250mg', '100mg', '200mg']),
                    'route' => $this->faker->randomElement(['Oral', 'IV', 'IM', 'Topical']),
                    'frequency' => $this->faker->randomElement(['Once daily', 'Twice daily', 'Three times daily', 'As needed']),
                    'duration' => $this->faker->randomElement(['5 days', '7 days', '10 days', '14 days']),
                    'notes' => $this->faker->optional()->sentence(),
                ],
            ],
            'diagnoses' => [
                [
                    'condition' => $this->faker->randomElement(['Hypertension', 'Diabetes Type 2', 'Upper Respiratory Infection', 'Migraine']),
                    'notes' => $this->faker->optional()->sentence(),
                ],
            ],
            'observations' => [
                $this->faker->sentence(),
                $this->faker->optional()->sentence(),
            ],
            'tests' => [
                [
                    'test_name' => $this->faker->randomElement(['Blood Pressure', 'Blood Sugar', 'CBC', 'Urine Test']),
                    'result' => $this->faker->optional()->randomElement(['Normal', 'Elevated', 'Low']),
                    'normal_range' => $this->faker->optional()->randomElement(['120/80', '70-100 mg/dL', '4.5-11.0 x10³/µL']),
                    'notes' => $this->faker->optional()->sentence(),
                ],
            ],
            'instructions' => $this->faker->paragraph(),
            'doctor' => [
                'name' => 'Dr. '.$this->faker->lastName(),
                'signature' => $this->faker->word(),
            ],
        ];
    }
}
