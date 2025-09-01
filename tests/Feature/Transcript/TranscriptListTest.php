<?php

namespace Tests\Feature\Transcript;

use App\Models\Transcript;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class TranscriptListTest extends TestCase
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

    public function test_unauthenticated_users_cannot_access_transcript_routes(): void
    {
        $transcript = Transcript::factory()->for($this->user)->create();

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

    public function test_authenticated_user_can_view_transcripts_index(): void
    {
        $this->actingAs($this->user);

        // Create some test transcripts for this user
        Transcript::factory()
            ->for($this->user)
            ->count(5)
            ->create();

        $response = $this->get(route('transcripts.index'));

        $response->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Transcripts/Index')
                ->has('transcripts.data', 5)
                ->has('search')
                ->has('status')
                ->has('statuses')
            );
    }

    public function test_transcript_index_can_search_by_title(): void
    {
        $this->actingAs($this->user);

        Transcript::factory()->for($this->user)->create(['title' => 'Medical Record 1']);
        Transcript::factory()->for($this->user)->create(['title' => 'Patient Chart']);
        Transcript::factory()->for($this->user)->create(['title' => 'Medical Record 2']);

        $response = $this->get(route('transcripts.index', ['search' => 'Medical Record']));

        $response->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Transcripts/Index')
                ->has('transcripts.data', 2)
                ->where('search', 'Medical Record')
            );
    }

    public function test_transcript_index_can_filter_by_status(): void
    {
        $this->actingAs($this->user);

        Transcript::factory()->for($this->user)->completed()->count(2)->create();
        Transcript::factory()->for($this->user)->failed()->count(3)->create();
        Transcript::factory()->for($this->user)->pending()->create();

        $response = $this->get(route('transcripts.index', ['status' => 'failed']));

        $response->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Transcripts/Index')
                ->has('transcripts.data', 3)
                ->where('status', 'failed')
            );
    }

    public function test_user_can_view_transcript_details(): void
    {
        $this->actingAs($this->user);

        $transcript = Transcript::factory()->for($this->user)->completed()->create([
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

    public function test_user_can_check_transcript_status_via_api(): void
    {
        $this->actingAs($this->user);

        $transcript = Transcript::factory()->for($this->user)->processing()->create();

        $response = $this->get(route('transcripts.status', $transcript));

        $response->assertOk()
            ->assertJsonPath('transcript.id', $transcript->id)
            ->assertJsonPath('transcript.status', Transcript::STATUS_PROCESSING)
            ->assertJsonPath('transcript.is_processing', true)
            ->assertJsonPath('transcript.can_retry', false);
    }

    public function test_users_cannot_view_other_users_transcripts(): void
    {
        $this->actingAs($this->user);

        // Create another user and their transcript
        $otherUser = User::factory()->create();
        $otherTranscript = Transcript::factory()->for($otherUser)->create();

        // Try to access the other user's transcript
        $response = $this->get(route('transcripts.show', $otherTranscript));
        $response->assertStatus(403);

        $response = $this->get(route('transcripts.edit', $otherTranscript));
        $response->assertStatus(403);

        $response = $this->get(route('transcripts.status', $otherTranscript));
        $response->assertStatus(403);

        $response = $this->post(route('transcripts.retry', $otherTranscript));
        $response->assertStatus(403);

        $response = $this->put(route('transcripts.update', $otherTranscript), [
            'title' => 'Hacked Title',
        ]);
        $response->assertStatus(403);

        $response = $this->delete(route('transcripts.destroy', $otherTranscript));
        $response->assertStatus(403);
    }

    public function test_user_index_only_shows_their_own_transcripts(): void
    {
        $this->actingAs($this->user);

        // Create transcripts for this user
        Transcript::factory()->for($this->user)->count(3)->create();

        // Create transcripts for another user
        $otherUser = User::factory()->create();
        Transcript::factory()->for($otherUser)->count(2)->create();

        $response = $this->get(route('transcripts.index'));

        $response->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Transcripts/Index')
                ->has('transcripts.data', 3) // Should only see their own 3 transcripts
            );
    }
}
