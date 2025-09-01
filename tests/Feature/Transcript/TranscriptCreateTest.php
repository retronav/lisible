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

it('allows authenticated user to view create form', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $response = $this->get(route('transcripts.create'));

    $response->assertOk()
        ->assertInertia(fn ($page) => $page->component('Transcripts/Create'));
});

it('allows creating a transcript with valid data', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    Queue::fake();

    $file = UploadedFile::fake()->image('prescription.jpg', 800, 600);

    $response = $this->post(route('transcripts.store'), [
        'title' => 'Test Prescription',
        'description' => 'A test prescription for validation',
        'image' => $file,
    ]);

    $response->assertRedirect();

    expect(
        Transcript::query()
            ->where('user_id', $user->id)
            ->where('title', 'Test Prescription')
            ->where('description', 'A test prescription for validation')
            ->where('status', Transcript::STATUS_PENDING)
            ->exists()
    )->toBeTrue();

    // Verify file was stored
    $transcript = Transcript::where('title', 'Test Prescription')->first();
    expect($transcript->image)->not->toBeNull();

    // Verify job was dispatched
    Queue::assertPushed(ProcessTranscription::class, function ($job) use ($transcript) {
        return $job->getTranscript()->id === $transcript->id;
    });
});

it('validates transcript creation input', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

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
});

it('returns json responses for ajax requests', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
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
});
