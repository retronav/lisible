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

class TranscriptUpdateTest extends TestCase
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

    public function user_can_view_edit_form_for_transcript(): void
    {
        $this->actingAs($this->user);

        $transcript = Transcript::factory()->for($this->user)->completed()->create();

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

        $transcript = Transcript::factory()->for($this->user)->processing()->create();

        $response = $this->get(route('transcripts.edit', $transcript));

        $response->assertRedirect(route('transcripts.show', $transcript))
            ->assertSessionHas('error', 'Cannot edit transcript while it is being processed.');
    }

    public function user_can_update_transcript_metadata(): void
    {
        $this->actingAs($this->user);

        $transcript = Transcript::factory()->for($this->user)->completed()->create([
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

        $transcript = Transcript::factory()->for($this->user)->completed()->create();
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

    public function user_can_retry_failed_transcript(): void
    {
        $this->actingAs($this->user);
        Queue::fake();

        $transcript = Transcript::factory()->for($this->user)->failed()->create();

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

        $transcript = Transcript::factory()->for($this->user)->completed()->create();

        $response = $this->postJson(route('transcripts.retry', $transcript));

        $response->assertStatus(400)
            ->assertJson([
                'success' => false,
                'message' => 'Only failed transcripts can be retried.',
            ]);
    }
}
