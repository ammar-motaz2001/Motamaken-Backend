<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AvatarTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_photo_replaces_the_previous_one_and_can_be_removed(): void
    {
        Storage::fake('public');
        $user = User::factory()->create();

        $first = $this->actingAs($user, 'sanctum')->post('/api/me/avatar', ['avatar' => $this->png(400, 400)], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('data.avatarUrl', fn ($url) => str_contains($url, '/storage/avatars/'));
        $path = $user->fresh()->avatar_path;
        Storage::disk('public')->assertExists($path);

        $this->actingAs($user, 'sanctum')->post('/api/me/avatar', ['avatar' => $this->png(300, 300)], ['Accept' => 'application/json'])->assertOk();
        Storage::disk('public')->assertMissing($path);

        $this->actingAs($user, 'sanctum')->deleteJson('/api/me/avatar')->assertOk()->assertJsonPath('data.avatarUrl', null);
        $this->assertNotNull($first);
    }

    public function test_only_reasonable_images_are_accepted(): void
    {
        Storage::fake('public');
        $user = User::factory()->create();
        $post = fn ($file) => $this->actingAs($user, 'sanctum')->post('/api/me/avatar', ['avatar' => $file], ['Accept' => 'application/json']);

        $post(UploadedFile::fake()->create('cv.pdf', 100, 'application/pdf'))->assertJsonValidationErrors('avatar');
        $post($this->png(50, 50))->assertJsonValidationErrors('avatar');
        $post($this->png(400, 400)->size(6000))->assertJsonValidationErrors('avatar');
    }

    /**
     * A real PNG built by hand: the container has no GD for UploadedFile::fake()->image().
     */
    private function png(int $width, int $height): UploadedFile
    {
        $chunk = fn (string $type, string $data) => pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));
        $rows = str_repeat("\0".str_repeat("\x18\x8d\x4a", $width), $height);
        $bytes = "\x89PNG\r\n\x1a\n".$chunk('IHDR', pack('NNCCCCC', $width, $height, 8, 2, 0, 0, 0)).$chunk('IDAT', gzcompress($rows)).$chunk('IEND', '');

        return UploadedFile::fake()->createWithContent("photo-{$width}.png", $bytes);
    }
}
