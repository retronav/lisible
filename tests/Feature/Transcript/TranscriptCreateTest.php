<?php

namespace Tests\Feature\Transcript;

use App\Jobs\ProcessTranscription;
use App\Models\Transcript;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class TranscriptCreateTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        // Create a user for authentication
        $this->user = User::factory()->create();

        // Set up storage for testing
        Storage::fake('public');
    }

    public function authenticated_user_can_view_create_form(): void
    {
        $this->actingAs($this->user);

        $response = $this->get(route('transcripts.create'));

        $response->assertOk()
            ->assertInertia(fn ($page) => $page->component('Transcripts/Create'));
    }

    public function user_can_create_transcript_with_valid_data(): void
    {
        $this->actingAs($this->user);
        Queue::fake();

        $file = UploadedFile::fake()->image('prescription.jpg', 800, 600);

        $response = $this->post(route('transcripts.store'), [
            'title' => 'Test Prescription',
            'description' => 'A test prescription for validation',
            'image' => $file,
        ]);

        $response->assertRedirect();

        $this->assertDatabaseHas('transcripts', [
            'user_id' => $this->user->id,
            'title' => 'Test Prescription',
            'description' => 'A test prescription for validation',
            'status' => Transcript::STATUS_PENDING,
        ]);

        // Verify file was stored
        $transcript = Transcript::where('title', 'Test Prescription')->first();
        $this->assertNotNull($transcript->image);

        // Verify job was dispatched
        Queue::assertPushed(ProcessTranscription::class, function ($job) use ($transcript) {
            return $job->getTranscript()->id === $transcript->id;
        });
    }

    public function transcript_creation_requires_valid_data(): void
    {
        $this->actingAs($this->user);

        // Test without title
        $response = $this->post(route('transcripts.store'), [
            'description' => 'Test description',
            'image' => UploadedFile::fake()->image('test.jpg'),
        ]);
        $response->assertSessionHasErrors(['title']);

        // Test without image
        $response = $this->post(route('transcripts.store'), [
            'title' => 'Test Title',
            'description' => 'Test description',
        ]);
        $response->assertSessionHasErrors(['image']);

        // Test with invalid file type
        $response = $this->post(route('transcripts.store'), [
            'title' => 'Test Title',
            'description' => 'Test description',
            'image' => UploadedFile::fake()->create('test.pdf', 1000),
        ]);
        $response->assertSessionHasErrors(['image']);

        // Test with oversized file
        $response = $this->post(route('transcripts.store'), [
            'title' => 'Test Title',
            'description' => 'Test description',
            'image' => UploadedFile::fake()->image('huge.jpg')->size(11000), // 11MB
        ]);
        $response->assertSessionHasErrors(['image']);
    }

    public function ajax_requests_return_json_responses(): void
    {
        $this->actingAs($this->user);
        Queue::fake();

        $file = UploadedFile::fake()->image('prescription.jpg');

        $response = $this->postJson(route('transcripts.store'), [
            'title' => 'AJAX Test',
            'description' => 'Test via AJAX',
            'image' => $file,
        ]);

        $response->assertStatus(201)
            ->assertJson([
                'success' => true,
                'transcript' => [
                    'status' => Transcript::STATUS_PENDING,
                    'title' => 'AJAX Test',
                ],
                'message' => 'Transcript created successfully. Processing will begin shortly.',
            ]);
    }
}
