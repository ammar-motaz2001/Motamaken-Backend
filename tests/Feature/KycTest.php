<?php

namespace Tests\Feature;

use App\Models\KycSubmission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\HandlesMoney;
use Tests\TestCase;

class KycTest extends TestCase
{
    use HandlesMoney, RefreshDatabase;

    private User $user;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->user = User::factory()->create(['first_name' => 'Kay', 'last_name' => 'Yee']);
        $this->admin = User::factory()->create(['is_admin' => true]);
    }

    public function test_a_user_submits_documents_and_the_admins_are_told(): void
    {
        $this->as($this->user)->getJson('/api/me/kyc')->assertOk()->assertExactJson(['status' => 'none', 'submission' => null]);

        $this->as($this->user)->post('/api/me/kyc', $this->documents(), ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.documentNumber', '••••7890')
            ->assertJsonPath('data.fullName', 'Kay Yee')
            ->assertJsonMissingPath('data.front');

        $this->assertSame('pending', $this->user->fresh()->kyc_status);
        $this->assertCount(2, Storage::disk('local')->allFiles("kyc/{$this->user->id}"));
        $this->assertStringNotContainsString('1234567890', DB::table('kyc_submissions')->value('document_number'), 'the number is stored encrypted');

        $this->as($this->user)->getJson('/api/me/kyc')->assertJsonPath('status', 'pending')->assertJsonPath('submission.status', 'pending');
        $this->as($this->admin)->getJson('/api/notifications')->assertJsonPath('data.0.type', 'admin.kyc_submitted')
            ->assertJsonPath('data.0.message', 'Kay Yee submitted identity documents for review.');

        $this->as($this->user)->post('/api/me/kyc', $this->documents(), ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonPath('code', 'kyc_pending');
    }

    public function test_the_documents_are_validated(): void
    {
        $post = fn (array $override) => $this->as($this->user)->post('/api/me/kyc', [...$this->documents(), ...$override], ['Accept' => 'application/json']);

        $this->as($this->user)->post('/api/me/kyc', [], ['Accept' => 'application/json'])
            ->assertJsonValidationErrors(['documentType', 'documentNumber', 'fullName', 'dateOfBirth', 'front', 'selfie']);
        $post(['documentType' => 'library_card'])->assertJsonValidationErrors('documentType');
        $post(['dateOfBirth' => now()->subYears(17)->toDateString()])->assertJsonValidationErrors('dateOfBirth')
            ->assertJsonPath('errors.dateOfBirth.0', 'You must be at least 18 years old.');
        $post(['selfie' => UploadedFile::fake()->create('selfie.pdf', 10, 'application/pdf')])->assertJsonValidationErrors('selfie');
        $post(['front' => UploadedFile::fake()->create('id.exe', 10, 'application/x-msdownload')])->assertJsonValidationErrors('front');
        $post(['front' => UploadedFile::fake()->create('id.pdf', 20000, 'application/pdf')])->assertJsonValidationErrors('front');

        $this->assertDatabaseCount('kyc_submissions', 0);
        $this->assertSame('none', $this->user->fresh()->kyc_status);
    }

    public function test_an_admin_reviews_the_documents_through_signed_links(): void
    {
        $id = $this->submit();

        $this->as($this->user)->getJson('/api/admin/kyc')->assertNotFound();
        $this->as($this->admin)->getJson('/api/admin/kyc')->assertOk()->assertJsonPath('meta.total', 1);
        $this->as($this->admin)->getJson('/api/admin/kyc?status=approved')->assertJsonPath('data', []);

        $files = $this->as($this->admin)->getJson("/api/admin/kyc/{$id}")->assertOk()
            ->assertJsonPath('data.documentNumberFull', '1234567890')
            ->assertJsonPath('data.user.fullName', 'Kay Yee')
            ->assertJsonPath('data.files.back', null)
            ->json('data.files');

        $this->get($files['front'])->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $this->get($files['selfie'])->assertOk();
        $this->get($files['front'].'x')->assertForbidden();
        $this->get(route('kyc.file', [$id, 'front']))->assertForbidden();
        $this->get(route('kyc.file', [$id, 'secret']))->assertForbidden();
    }

    public function test_approving_unlocks_withdrawals(): void
    {
        $id = $this->submit();
        $this->fund($this->user, 100);
        $withdrawal = ['amount' => 50, 'currency' => 'USD', 'method' => 'paypal', 'destination' => ['email' => 'kay@example.com']];

        $this->as($this->user)->postJson('/api/wallet/withdrawals', $withdrawal)->assertStatus(422)->assertJsonPath('code', 'kyc_required');

        $this->as($this->admin)->postJson("/api/admin/kyc/{$id}/approve")->assertOk()->assertJsonPath('data.status', 'approved');
        $this->assertSame('approved', $this->user->fresh()->kyc_status);
        $this->as($this->user)->getJson('/api/notifications')->assertJsonPath('data.0.message', 'Your identity was verified. You can now withdraw money.');
        $this->as($this->user)->postJson('/api/wallet/withdrawals', $withdrawal)->assertCreated();

        $this->as($this->admin)->postJson("/api/admin/kyc/{$id}/approve")->assertStatus(422)->assertJsonPath('code', 'kyc_not_pending');
        $this->as($this->user)->post('/api/me/kyc', $this->documents(), ['Accept' => 'application/json'])->assertJsonPath('code', 'kyc_approved');
    }

    public function test_a_rejected_user_can_try_again(): void
    {
        $id = $this->submit();

        $this->as($this->admin)->postJson("/api/admin/kyc/{$id}/reject", [])->assertJsonValidationErrors('reason');
        $this->as($this->admin)->postJson("/api/admin/kyc/{$id}/reject", ['reason' => 'The photo is blurry'])
            ->assertOk()->assertJsonPath('data.status', 'rejected')->assertJsonPath('data.rejectionReason', 'The photo is blurry');

        $this->as($this->user)->getJson('/api/me/kyc')->assertJsonPath('status', 'rejected')->assertJsonPath('submission.rejectionReason', 'The photo is blurry');
        $this->as($this->user)->getJson('/api/notifications')
            ->assertJsonPath('data.0.message', 'Your identity check was rejected: The photo is blurry. You can submit new documents.');

        $this->as($this->user)->post('/api/me/kyc', $this->documents(), ['Accept' => 'application/json'])->assertCreated();
        $this->assertSame(2, KycSubmission::count());
        $this->assertSame('pending', $this->user->fresh()->kyc_status);
    }

    private function as(User $user): static
    {
        // A fresh copy, as a real request would load: the status changes while the tests run.
        return $this->actingAs($user->fresh(), 'sanctum');
    }

    /**
     * @return array<string, mixed>
     */
    private function documents(): array
    {
        return [
            'documentType' => 'national_id', 'documentNumber' => '1234567890', 'fullName' => 'Kay Yee',
            'dateOfBirth' => '1990-05-17',
            'front' => UploadedFile::fake()->create('front.jpg', 20, 'image/jpeg'),
            'selfie' => UploadedFile::fake()->create('selfie.png', 20, 'image/png'),
        ];
    }

    private function submit(): int
    {
        return $this->as($this->user)->post('/api/me/kyc', $this->documents(), ['Accept' => 'application/json'])->assertCreated()->json('data.id');
    }
}
