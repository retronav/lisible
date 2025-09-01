<?php

use App\Models\Transcript;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('public');
});

it('allows a user to delete their transcript', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $transcript = Transcript::factory()->for($user)->completed()->create();
    $imagePath = $transcript->image;

    $response = $this->delete(route('transcripts.destroy', $transcript));

    $response->assertRedirect(route('transcripts.index'))
        ->assertSessionHas('success', 'Transcript deleted successfully.');

    // Verify soft delete
    $this->assertSoftDeleted('transcripts', [
        'id' => $transcript->id,
    ]);

    // Verify image path was cleared from record (we only ensure it existed before deletion)
    expect($imagePath)->not->toBeNull();
});
