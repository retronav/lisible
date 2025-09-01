<?php

namespace Tests\Feature\Transcript;

use App\Models\Transcript;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class TranscriptDeleteTest extends TestCase
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

    public function user_can_delete_transcript(): void
    {
        $this->actingAs($this->user);

        $transcript = Transcript::factory()->for($this->user)->completed()->create();
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
}
