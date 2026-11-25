<?php

namespace Tests\Feature;

use App\Models\ApiToken;
use App\Models\Opportunity;
use App\Models\OpportunityDocument;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class OpportunityDocumentUploadTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_upload_a_document_to_a_draft(): void
    {
        Storage::fake('public');

        [$user, $token] = $this->creator();
        $opportunity = $this->opportunity($user);

        $response = $this->withToken($token)->post("/api/opportunities/{$opportunity->id}/documents", [
            'file' => UploadedFile::fake()->create('supplier-quote.pdf', 120, 'application/pdf'),
            'kind' => 'SUPPLIER_QUOTE',
            'title' => 'Supplier quote',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.title', 'Supplier quote')
            ->assertJsonPath('data.kind', 'SUPPLIER_QUOTE')
            ->assertJsonPath('data.verified', false);

        $document = OpportunityDocument::firstOrFail();

        $this->assertSame($opportunity->id, $document->opportunity_id);
        $this->assertNotNull($document->uploaded_at);
        $path = ltrim(str_replace('/storage/', '', (string) parse_url($document->file_url, PHP_URL_PATH)), '/');
        Storage::disk('public')->assertExists($path);
    }

    public function test_unsupported_file_types_are_rejected(): void
    {
        Storage::fake('public');

        [$user, $token] = $this->creator();
        $opportunity = $this->opportunity($user);

        $response = $this->withToken($token)->post("/api/opportunities/{$opportunity->id}/documents", [
            'file' => UploadedFile::fake()->create('script.php', 10, 'application/x-php'),
        ]);

        $response->assertUnprocessable();
        $this->assertSame(0, OpportunityDocument::count());
    }

    public function test_documents_cannot_be_added_after_submission_by_the_creator(): void
    {
        Storage::fake('public');

        [$user, $token] = $this->creator();
        $opportunity = $this->opportunity($user, ['status' => 'PENDING_REVIEW']);

        $response = $this->withToken($token)->post("/api/opportunities/{$opportunity->id}/documents", [
            'file' => UploadedFile::fake()->create('quote.pdf', 120, 'application/pdf'),
        ]);

        $response->assertStatus(422)->assertJsonPath('code', 'STATE');
        $this->assertSame(0, OpportunityDocument::count());
    }

    /** @return array{0: User, 1: string} */
    private function creator(): array
    {
        $user = User::factory()->create(['role' => 'CREATOR']);
        [, $token] = ApiToken::issue($user);

        return [$user, $token];
    }

    /** @param array<string, mixed> $overrides */
    private function opportunity(User $user, array $overrides = []): Opportunity
    {
        return Opportunity::create(array_merge([
            'slug' => 'upload-test-'.uniqid(),
            'title' => 'Upload test opportunity',
            'summary' => 'Testing document uploads.',
            'description' => 'Testing.',
            'cover_image' => '/images/cover-rice-bags.webp',
            'category' => 'Agriculture',
            'model' => 'COLLECTIVE_PURCHASE',
            'participation_method' => 'AMOUNT',
            'status' => 'DRAFT',
            'creator_id' => $user->id,
            'creator_source' => 'USER',
            'supplier_name' => 'Test Supplier',
            'target_amount' => 1_000_000,
            'committed_amount' => 0,
            'min_contribution' => 10_000,
            'participant_count' => 0,
            'funding_deadline' => now()->addDays(10),
            'location' => 'Lagos',
            'fee_bps' => 350,
            'risk_notes' => 'Testing risk.',
            'acquisition_conditions' => 'Testing conditions.',
        ], $overrides));
    }
}
