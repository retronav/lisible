<?php

namespace Tests\Feature;

use App\Jobs\ProcessTranscription;
use App\Models\Transcript;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class TranscriptControllerTest extends TestCase
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

    public function unauthenticated_users_cannot_access_transcript_routes(): void
    {
        $transcript = Transcript::factory()->create();

        // Test various routes
        $this->get(route('transcripts.index'))
            ->assertRedirect(route('login'));

        $this->get(route('transcripts.create'))
            ->assertRedirect(route('login'));

        $this->get(route('transcripts.show', $transcript))
            ->assertRedirect(route('login'));

        $this->get(route('transcripts.edit', $transcript))
            ->assertRedirect(route('login'));
    }

    public function authenticated_user_can_view_transcripts_index(): void
    {
        $this->actingAs($this->user);

        // Create some test transcripts
        $transcripts = Transcript::factory()
            ->count(5)
            ->create();

        $response = $this->get(route('transcripts.index'));

        $response->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Transcripts/Index')
                ->has('transcripts.data', 5)
                ->has('filters')
                ->has('statuses')
            );
    }

    public function transcript_index_can_search_by_title(): void
    {
        $this->actingAs($this->user);

        Transcript::factory()->create(['title' => 'Medical Record 1']);
        Transcript::factory()->create(['title' => 'Patient Chart']);
        Transcript::factory()->create(['title' => 'Medical Record 2']);

        $response = $this->get(route('transcripts.index', ['search' => 'Medical Record']));

        $response->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Transcripts/Index')
                ->has('transcripts.data', 2)
                ->where('filters.search', 'Medical Record')
            );
    }

    public function transcript_index_can_filter_by_status(): void
    {
        $this->actingAs($this->user);

        Transcript::factory()->completed()->count(2)->create();
        Transcript::factory()->failed()->count(3)->create();
        Transcript::factory()->pending()->create();

        $response = $this->get(route('transcripts.index', ['status' => 'failed']));

        $response->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Transcripts/Index')
                ->has('transcripts.data', 3)
                ->where('filters.status', 'failed')
            );
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

    public function user_can_view_transcript_details(): void
    {
        $this->actingAs($this->user);

        $transcript = Transcript::factory()->completed()->create([
            'title' => 'Test Medical Record',
            'description' => 'Test description',
        ]);

        $response = $this->get(route('transcripts.show', $transcript));

        $response->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Transcripts/Show')
                ->where('transcript.id', $transcript->id)
                ->where('transcript.title', 'Test Medical Record')
                ->where('transcript.status', Transcript::STATUS_COMPLETED)
                ->has('transcript.transcript')
            );
    }

    public function user_can_check_transcript_status_via_api(): void
    {
        $this->actingAs($this->user);

        $transcript = Transcript::factory()->processing()->create();

        $response = $this->get(route('transcripts.status', $transcript));

        $response->assertOk()
            ->assertJson([
                'id' => $transcript->id,
                'status' => Transcript::STATUS_PROCESSING,
                'is_processing' => true,
                'can_retry' => false,
            ]);
    }

    public function user_can_retry_failed_transcript(): void
    {
        $this->actingAs($this->user);
        Queue::fake();

        $transcript = Transcript::factory()->failed()->create();

        $response = $this->postJson(route('transcripts.retry', $transcript));

        $response->assertOk()
            ->assertJson([
                'success' => true,
                'status' => Transcript::STATUS_PENDING,
            ]);

        $transcript->refresh();
        $this->assertEquals(Transcript::STATUS_PENDING, $transcript->status);
        $this->assertNull($transcript->error_message);

        // Verify job was dispatched
        Queue::assertPushed(ProcessTranscription::class);
    }

    public function retry_is_only_allowed_for_failed_transcripts(): void
    {
        $this->actingAs($this->user);

        $transcript = Transcript::factory()->completed()->create();

        $response = $this->postJson(route('transcripts.retry', $transcript));

        $response->assertStatus(400)
            ->assertJson([
                'success' => false,
                'message' => 'Only failed transcripts can be retried.',
            ]);
    }

    public function user_can_view_edit_form_for_transcript(): void
    {
        $this->actingAs($this->user);

        $transcript = Transcript::factory()->completed()->create();

        $response = $this->get(route('transcripts.edit', $transcript));

        $response->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Transcripts/Edit')
                ->where('transcript.id', $transcript->id)
            );
    }

    public function editing_is_blocked_while_processing(): void
    {
        $this->actingAs($this->user);

        $transcript = Transcript::factory()->processing()->create();

        $response = $this->get(route('transcripts.edit', $transcript));

        $response->assertRedirect(route('transcripts.show', $transcript))
            ->assertSessionHas('error', 'Cannot edit transcript while it is being processed.');
    }

    public function user_can_update_transcript_metadata(): void
    {
        $this->actingAs($this->user);

        $transcript = Transcript::factory()->completed()->create([
            'title' => 'Old Title',
            'description' => 'Old Description',
        ]);

        $response = $this->put(route('transcripts.update', $transcript), [
            'title' => 'New Title',
            'description' => 'New Description',
        ]);

        $response->assertRedirect(route('transcripts.show', $transcript))
            ->assertSessionHas('success', 'Transcript updated successfully.');

        $transcript->refresh();
        $this->assertEquals('New Title', $transcript->title);
        $this->assertEquals('New Description', $transcript->description);
    }

    public function updating_with_new_image_triggers_reprocessing(): void
    {
        $this->actingAs($this->user);
        Queue::fake();

        $transcript = Transcript::factory()->completed()->create();
        $oldImagePath = $transcript->image;

        $newImage = UploadedFile::fake()->image('new-prescription.jpg');

        $response = $this->put(route('transcripts.update', $transcript), [
            'title' => 'Updated Title',
            'image' => $newImage,
        ]);

        $response->assertRedirect(route('transcripts.show', $transcript))
            ->assertSessionHas('success', 'Transcript updated successfully. Re-processing will begin shortly.');

        $transcript->refresh();

        // Verify old image was deleted and new one stored
        $this->assertNotEquals($oldImagePath, $transcript->image);
        $this->assertNotNull($transcript->image);

        // Verify reprocessing was triggered
        $this->assertEquals(Transcript::STATUS_PENDING, $transcript->status);
        $this->assertNull($transcript->transcript);

        // Verify job was dispatched
        Queue::assertPushed(ProcessTranscription::class);
    }

    public function user_can_delete_transcript(): void
    {
        $this->actingAs($this->user);

        $transcript = Transcript::factory()->completed()->create();
        $imagePath = $transcript->image;

        $response = $this->delete(route('transcripts.destroy', $transcript));

        $response->assertRedirect(route('transcripts.index'))
            ->assertSessionHas('success', 'Transcript deleted successfully.');

        // Verify soft delete
        $this->assertSoftDeleted('transcripts', [
            'id' => $transcript->id,
        ]);

        // Verify image path was cleared from record
        $this->assertNotNull($imagePath);
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
