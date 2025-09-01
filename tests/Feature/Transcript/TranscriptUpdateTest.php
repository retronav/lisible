<?php

use App\Jobs\ProcessTranscription;
use App\Models\Transcript;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('public');
});

it('shows edit form for a transcript', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $transcript = Transcript::factory()->for($user)->completed()->create();

    $response = $this->get(route('transcripts.edit', $transcript));

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Transcripts/Edit')
            ->where('transcript.id', $transcript->id)
        );
});

it('blocks editing while processing', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $transcript = Transcript::factory()->for($user)->processing()->create();

    $response = $this->get(route('transcripts.edit', $transcript));

    $response->assertRedirect(route('transcripts.show', $transcript))
        ->assertSessionHas('error', 'Cannot edit transcript while it is being processed.');
});

it('updates transcript metadata', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $transcript = Transcript::factory()->for($user)->completed()->create([
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
    expect($transcript->title)->toBe('New Title');
    expect($transcript->description)->toBe('New Description');
});

it('reprocesses when a new image is uploaded on update', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    Queue::fake();

    $transcript = Transcript::factory()->for($user)->completed()->create();
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
    expect($transcript->image)->not->toBe($oldImagePath);
    expect($transcript->image)->not->toBeNull();

    // Verify reprocessing was triggered
    expect($transcript->status)->toBe(Transcript::STATUS_PENDING);
    expect($transcript->transcript)->toBeNull();

    // Verify job was dispatched
    Queue::assertPushed(ProcessTranscription::class);
});

it('allows retrying a failed transcript', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    Queue::fake();

    $transcript = Transcript::factory()->for($user)->failed()->create();

    $response = $this->postJson(route('transcripts.retry', $transcript));

    $response->assertOk()
        ->assertJson([
            'success' => true,
            'status' => Transcript::STATUS_PENDING,
        ]);

    $transcript->refresh();
    expect($transcript->status)->toBe(Transcript::STATUS_PENDING);
    expect($transcript->error_message)->toBeNull();

    Queue::assertPushed(ProcessTranscription::class);
});

it('only allows retry for failed transcripts', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $transcript = Transcript::factory()->for($user)->completed()->create();

    $response = $this->postJson(route('transcripts.retry', $transcript));

    $response->assertStatus(400)
        ->assertJson([
            'success' => false,
            'message' => 'Only failed transcripts can be retried.',
        ]);
});
