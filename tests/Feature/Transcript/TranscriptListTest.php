<?php

use App\Models\Transcript;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('public');
});

it('prevents unauthenticated access to transcript routes', function () {
    $user = User::factory()->create();
    $transcript = Transcript::factory()->for($user)->create();

    $this->get(route('transcripts.index'))
        ->assertRedirect(route('login'));

    $this->get(route('transcripts.create'))
        ->assertRedirect(route('login'));

    $this->get(route('transcripts.show', $transcript))
        ->assertRedirect(route('login'));

    $this->get(route('transcripts.edit', $transcript))
        ->assertRedirect(route('login'));
});

it('shows transcripts index for authenticated user', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    Transcript::factory()->for($user)->count(5)->create();

    $response = $this->get(route('transcripts.index'));

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Transcripts/Index')
            ->has('transcripts.data', 5)
            ->has('search')
            ->has('status')
            ->has('statuses')
        );
});

it('can search the index by title', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    Transcript::factory()->for($user)->create(['title' => 'Medical Record 1']);
    Transcript::factory()->for($user)->create(['title' => 'Patient Chart']);
    Transcript::factory()->for($user)->create(['title' => 'Medical Record 2']);

    $response = $this->get(route('transcripts.index', ['search' => 'Medical Record']));

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Transcripts/Index')
            ->has('transcripts.data', 2)
            ->where('search', 'Medical Record')
        );
});

it('can filter the index by status', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    Transcript::factory()->for($user)->completed()->count(2)->create();
    Transcript::factory()->for($user)->failed()->count(3)->create();
    Transcript::factory()->for($user)->pending()->create();

    $response = $this->get(route('transcripts.index', ['status' => 'failed']));

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Transcripts/Index')
            ->has('transcripts.data', 3)
            ->where('status', 'failed')
        );
});

it('shows transcript details to the owner', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $transcript = Transcript::factory()->for($user)->completed()->create([
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
});

it('allows checking transcript status via api', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $transcript = Transcript::factory()->for($user)->processing()->create();

    $response = $this->get(route('transcripts.status', $transcript));

    $response->assertOk()
        ->assertJsonPath('transcript.id', $transcript->id)
        ->assertJsonPath('transcript.status', Transcript::STATUS_PROCESSING)
        ->assertJsonPath('transcript.is_processing', true)
        ->assertJsonPath('transcript.can_retry', false);
});

it("prevents access to other users' transcripts", function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $otherUser = User::factory()->create();
    $otherTranscript = Transcript::factory()->for($otherUser)->create();

    $this->get(route('transcripts.show', $otherTranscript))->assertStatus(403);
    $this->get(route('transcripts.edit', $otherTranscript))->assertStatus(403);
    $this->get(route('transcripts.status', $otherTranscript))->assertStatus(403);
    $this->post(route('transcripts.retry', $otherTranscript))->assertStatus(403);
    $this->put(route('transcripts.update', $otherTranscript), [ 'title' => 'Hacked Title' ])->assertStatus(403);
    $this->delete(route('transcripts.destroy', $otherTranscript))->assertStatus(403);
});

it('shows only the users own transcripts in the index', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    Transcript::factory()->for($user)->count(3)->create();

    $otherUser = User::factory()->create();
    Transcript::factory()->for($otherUser)->count(2)->create();

    $response = $this->get(route('transcripts.index'));

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Transcripts/Index')
            ->has('transcripts.data', 3)
        );
});
