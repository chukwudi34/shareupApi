<?php

namespace Tests\Feature;

use App\Exceptions\DomainException;
use App\Models\Opportunity;
use App\Models\Participation;
use App\Models\Refund;
use App\Models\Transaction;
use App\Models\User;
use App\Payments\SandboxProvider;
use App\Services\FundingService;
use App\Services\LedgerService;
use App\Services\OperationsService;
use App\Support\Ledger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FundingFlowTest extends TestCase
{
    use RefreshDatabase;

    private FundingService $funding;

    private LedgerService $ledger;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'shareup.payment_provider' => 'SANDBOX',
            'shareup.sandbox.secret' => 'test-secret',
        ]);

        $this->funding = app(FundingService::class);
        $this->ledger = app(LedgerService::class);
        $this->ledger->seedChart();
    }

    public function test_verified_payment_confirms_participation_and_posts_balanced_journal(): void
    {
        $user = User::factory()->create();
        $opportunity = $this->opportunity(['target_amount' => 100_000, 'total_units' => 10, 'unit_price' => 10_000]);

        $started = $this->funding->start($user, $opportunity, 100_000, null, 'key-1');
        $signature = (new SandboxProvider)->signature(
            $started['transaction']->reference,
            $started['transaction']->amount,
        );

        $confirmed = $this->funding->confirm($started['transaction'], $signature);

        $this->assertSame('PAID', $confirmed->status);
        $this->assertSame('CONFIRMED', $started['participation']->fresh()->status);
        $this->assertSame('FUNDED', $opportunity->fresh()->status);
        $this->assertSame(100_000, $opportunity->fresh()->committed_amount);
        $this->assertSame(3_500, $this->ledger->balance('4000'));
        $this->assertSame(96_500, $this->ledger->balance('2000'));

        $debits = \DB::table('ledger_lines')->where('direction', 'DEBIT')->sum('amount');
        $credits = \DB::table('ledger_lines')->where('direction', 'CREDIT')->sum('amount');
        $this->assertSame($debits, $credits);
    }

    public function test_wrong_signature_is_rejected_without_moving_money(): void
    {
        $user = User::factory()->create();
        $opportunity = $this->opportunity();

        $started = $this->funding->start($user, $opportunity, 50_000, null, 'key-2');

        try {
            $this->funding->confirm($started['transaction'], 'forged-signature');
            $this->fail('A forged signature must not be accepted.');
        } catch (DomainException $exception) {
            $this->assertSame('PAYMENT', $exception->errorCode);
        }

        $this->assertSame('PENDING', $started['transaction']->fresh()->status);
        $this->assertSame(0, $opportunity->fresh()->committed_amount);
    }

    public function test_capacity_race_loser_is_refunded_instead_of_overfilling(): void
    {
        $first = User::factory()->create();
        $second = User::factory()->create();

        $opportunity = $this->opportunity([
            'target_amount' => 100_000,
            'total_units' => 10,
            'unit_price' => 10_000,
            'allows_overfunding' => false,
        ]);

        $a = $this->funding->start($first, $opportunity, 90_000, null, 'race-a');
        $b = $this->funding->start($second, $opportunity, 20_000, null, 'race-b');

        $this->funding->confirm($a['transaction'], (new SandboxProvider)->signature($a['transaction']->reference, 90_000));
        $this->funding->confirm($b['transaction'], (new SandboxProvider)->signature($b['transaction']->reference, 20_000));

        $this->assertSame(90_000, $opportunity->fresh()->committed_amount);
        $this->assertSame(9, $opportunity->fresh()->units_committed);
        $this->assertSame('REFUND_PENDING', $b['participation']->fresh()->status);
        $this->assertSame(1, Refund::where('user_id', $second->id)->where('status', 'REQUESTED')->count());
        $this->assertSame('PAID', $b['transaction']->fresh()->status);
    }

    public function test_start_is_idempotent_for_the_same_key(): void
    {
        $user = User::factory()->create();
        $opportunity = $this->opportunity();

        $first = $this->funding->start($user, $opportunity, 50_000, null, 'same-key');
        $second = $this->funding->start($user, $opportunity, 50_000, null, 'same-key');

        $this->assertSame($first['transaction']->id, $second['transaction']->id);
        $this->assertSame(1, Transaction::where('idempotency_key', 'txn:same-key')->count());
        $this->assertSame(1, Participation::where('user_id', $user->id)->count());
    }

    public function test_confirming_the_same_payment_twice_does_not_double_credit(): void
    {
        $user = User::factory()->create();
        $opportunity = $this->opportunity(['target_amount' => 500_000, 'total_units' => 50]);

        $started = $this->funding->start($user, $opportunity, 100_000, null, 'repeat-key');
        $signature = (new SandboxProvider)->signature($started['transaction']->reference, 100_000);

        $this->funding->confirm($started['transaction'], $signature);
        $this->funding->confirm($started['transaction'], $signature);

        $this->assertSame(100_000, $opportunity->fresh()->committed_amount);
        $this->assertSame(1, $opportunity->fresh()->participant_count);
        $this->assertSame(1, Participation::where('user_id', $user->id)->where('status', 'CONFIRMED')->count());
    }

    public function test_refund_requires_a_different_finance_approver(): void
    {
        $requester = User::factory()->create(['role' => 'ADMIN']);
        $finance = User::factory()->create(['role' => 'FINANCE']);
        $participant = User::factory()->create();

        $opportunity = $this->opportunity();
        $started = $this->funding->start($participant, $opportunity, 50_000, null, 'refund-key');
        $this->funding->confirm($started['transaction'], (new SandboxProvider)->signature($started['transaction']->reference, 50_000));

        $participation = $started['participation']->fresh();
        $refund = app(OperationsService::class)->requestRefund($requester, $participation, 'PARTICIPANT_REQUEST', 'Testing maker-checker.');

        try {
            app(OperationsService::class)->approveRefund($requester, $refund);
            $this->fail('The requester must not approve their own refund.');
        } catch (DomainException $exception) {
            $this->assertSame('MAKER_CHECKER', $exception->errorCode);
        }

        $approved = app(OperationsService::class)->approveRefund($finance, $refund);
        $this->assertSame('APPROVED', $approved->status);

        $paid = app(OperationsService::class)->payRefund($finance, $approved);
        $this->assertSame('PAID', $paid->status);
        $this->assertSame('REFUNDED', $participation->fresh()->status);
    }

    public function test_wallet_deposit_is_credited_only_after_verification(): void
    {
        $user = User::factory()->create(['wallet_balance' => 0]);

        $deposit = $this->funding->startDeposit($user, 250_000, 'deposit-key');

        $this->assertSame(0, $user->fresh()->wallet_balance);

        $this->funding->confirm($deposit['transaction'], (new SandboxProvider)->signature($deposit['transaction']->reference, 250_000));

        $this->assertSame(250_000, $user->fresh()->wallet_balance);
        $this->assertSame(250_000, $this->ledger->balance('2400'));
    }

    private function opportunity(array $overrides = []): Opportunity
    {
        return Opportunity::create(array_merge([
            'slug' => 'test-opportunity-'.uniqid(),
            'title' => 'Test Opportunity',
            'summary' => 'Testing collective purchase logic.',
            'description' => 'Testing.',
            'cover_image' => '/images/cover-rice-bags.webp',
            'category' => 'Agriculture',
            'model' => 'COLLECTIVE_PURCHASE',
            'participation_method' => 'AMOUNT',
            'status' => 'FUNDING',
            'creator_id' => User::factory()->create(['role' => 'ADMIN'])->id,
            'creator_source' => 'PLATFORM',
            'supplier_name' => 'Test Supplier',
            'supplier_verified' => true,
            'target_amount' => 1_000_000,
            'committed_amount' => 0,
            'unit_price' => 10_000,
            'total_units' => 100,
            'min_contribution' => 10_000,
            'max_participants' => 100,
            'participant_count' => 0,
            'funding_deadline' => now()->addDays(10),
            'location' => 'Lagos',
            'fee_bps' => 350,
            'allows_partial_funding' => true,
            'allow_cancellation' => true,
            'risk_notes' => 'Testing risk.',
            'acquisition_conditions' => 'Testing conditions.',
            'legal_structure' => 'CONTRACTUAL_ECONOMIC',
            'ownership_type' => 'PHYSICAL_QUANTITY',
        ], $overrides));
    }
}
