<?php

namespace App\Services;

use App\Exceptions\DomainException;
use App\Models\Approval;
use App\Models\AuditLog;
use App\Models\Notification;
use App\Models\Opportunity;
use App\Models\Participation;
use App\Models\Refund;
use App\Models\Transaction;
use App\Models\User;
use App\Payments\PaymentManager;
use App\Payments\SandboxProvider;
use App\Support\Ledger;
use App\Support\Money;
use App\Support\ParticipationRules;
use App\Support\References;
use Illuminate\Support\Facades\DB;

final class FundingService
{
    public function __construct(
        private readonly LedgerService $ledger,
        private readonly NotificationService $notifier,
    ) {}

    /**
     * Reserve a contribution. No money moves and no capacity is consumed until
     * the provider payment is verified server-side.
     *
     * @return array{transaction: Transaction, participation: Participation, authorizationUrl: ?string, sandboxSignature: ?string}
     */
    public function start(
        User $user,
        Opportunity $opportunity,
        ?int $amountKobo,
        ?int $units,
        string $idempotencyKey,
    ): array {
        $idempotencyKey = trim($idempotencyKey) ?: throw new DomainException('VALIDATION', 'An idempotency key is required.');

        $existing = Transaction::where('idempotency_key', 'txn:'.$idempotencyKey)->first();
        if ($existing) {
            return [
                'transaction' => $existing,
                'participation' => $existing->participation,
                'authorizationUrl' => $existing->metadata['authorizationUrl'] ?? null,
                'sandboxSignature' => $existing->metadata['sandboxSignature'] ?? null,
            ];
        }

        return DB::transaction(function () use ($user, $opportunity, $amountKobo, $units, $idempotencyKey) {
            $opportunity = Opportunity::whereKey($opportunity->id)->lockForUpdate()->firstOrFail();

            $existingConfirmedAmount = (int) Participation::where('opportunity_id', $opportunity->id)
                ->where('user_id', $user->id)
                ->whereIn('status', ['CONFIRMED', 'REFUND_PENDING'])
                ->sum('amount');

            $participantCount = (int) Participation::where('opportunity_id', $opportunity->id)
                ->whereIn('status', ['CONFIRMED', 'REFUND_PENDING'])
                ->distinct()
                ->count('user_id');

            $evaluation = ParticipationRules::evaluate(
                $opportunity,
                $amountKobo,
                $units,
                $existingConfirmedAmount,
                $participantCount,
            );

            $participation = Participation::create([
                'reference' => References::make('PAR'),
                'opportunity_id' => $opportunity->id,
                'user_id' => $user->id,
                'amount' => $evaluation['amount'],
                'units' => $evaluation['units'],
                'quantity' => $evaluation['quantity'],
                'status' => 'PENDING',
                'idempotency_key' => $idempotencyKey,
                'expires_at' => now()->addMinutes($opportunity->reservation_minutes ?: 30),
            ]);

            $provider = strtoupper((string) config('shareup.payment_provider'));
            $reference = References::make('TXN');
            $sandboxSignature = $provider === 'SANDBOX'
                ? (new SandboxProvider)->signature($reference, $evaluation['amount'])
                : null;

            $transaction = Transaction::create([
                'reference' => $reference,
                'user_id' => $user->id,
                'opportunity_id' => $opportunity->id,
                'participation_id' => $participation->id,
                'type' => 'CONTRIBUTION',
                'amount' => $evaluation['amount'],
                'currency' => 'NGN',
                'status' => 'PENDING',
                'provider' => $provider,
                'idempotency_key' => 'txn:'.$idempotencyKey,
                'metadata' => [
                    'authorizationUrl' => $provider === 'SANDBOX' ? '/pay/sandbox/'.$reference : null,
                    'sandboxSignature' => $sandboxSignature,
                    'units' => $evaluation['units'],
                ],
            ]);

            AuditLog::record($user, 'participation.started', 'participation', $participation->id, null, null, [
                'amount' => $evaluation['amount'],
                'opportunity' => $opportunity->slug,
            ]);

            return [
                'transaction' => $transaction,
                'participation' => $participation,
                'authorizationUrl' => $transaction->metadata['authorizationUrl'] ?? null,
                'sandboxSignature' => $sandboxSignature,
            ];
        });
    }

    /** Start a wallet top-up through the configured provider. */
    public function startDeposit(User $user, int $amountKobo, string $idempotencyKey): array
    {
        if ($amountKobo < 10000) {
            throw new DomainException('VALIDATION', 'The minimum wallet deposit is ₦100.');
        }

        $existing = Transaction::where('idempotency_key', 'txn:'.$idempotencyKey)->first();
        if ($existing) {
            return [
                'transaction' => $existing,
                'authorizationUrl' => $existing->metadata['authorizationUrl'] ?? null,
                'sandboxSignature' => $existing->metadata['sandboxSignature'] ?? null,
            ];
        }

        $provider = strtoupper((string) config('shareup.payment_provider'));
        $reference = References::make('TXN');
        $sandboxSignature = $provider === 'SANDBOX'
            ? (new SandboxProvider)->signature($reference, $amountKobo)
            : null;

        $transaction = Transaction::create([
            'reference' => $reference,
            'user_id' => $user->id,
            'type' => 'DEPOSIT',
            'amount' => $amountKobo,
            'currency' => 'NGN',
            'status' => 'PENDING',
            'provider' => $provider,
            'idempotency_key' => 'txn:'.$idempotencyKey,
            'metadata' => [
                'authorizationUrl' => $provider === 'SANDBOX' ? '/pay/sandbox/'.$reference : null,
                'sandboxSignature' => $sandboxSignature,
            ],
        ]);

        return [
            'transaction' => $transaction,
            'authorizationUrl' => $transaction->metadata['authorizationUrl'] ?? null,
            'sandboxSignature' => $sandboxSignature,
        ];
    }

    /**
     * Verify a provider payment and, if genuine, claim capacity atomically.
     * Verification happens outside the write transaction on purpose.
     */
    public function confirm(Transaction $transaction, ?string $signature = null): Transaction
    {
        $provider = PaymentManager::driver($transaction->provider);

        if (! $provider->verify($transaction->reference, (int) $transaction->amount, $signature)) {
            throw new DomainException('PAYMENT', 'The payment could not be verified with the provider.', 402);
        }

        $transaction = DB::transaction(function () use ($transaction) {
            $transaction = Transaction::whereKey($transaction->id)->lockForUpdate()->firstOrFail();

            if ($transaction->status === 'PAID') {
                return $transaction;
            }

            if ($transaction->status !== 'PENDING') {
                throw new DomainException('PAYMENT', 'This payment can no longer be confirmed.');
            }

            if ($transaction->type === 'DEPOSIT') {
                return $this->confirmDeposit($transaction);
            }

            return $this->confirmContribution($transaction);
        });

        return $transaction->fresh(['participation', 'opportunity']);
    }

    private function confirmDeposit(Transaction $transaction): Transaction
    {
        $user = User::whereKey($transaction->user_id)->lockForUpdate()->firstOrFail();
        $user->wallet_balance = (int) $user->wallet_balance + (int) $transaction->amount;
        $user->save();

        $transaction->update(['status' => 'PAID', 'completed_at' => now()]);

        $this->ledger->post(Ledger::walletDeposit((int) $transaction->amount), null, $user);

        Notification::sendTo($user->id, 'Wallet funded', Money::format((int) $transaction->amount).' was added to your wallet.', 'SUCCESS', '/wallet');

        return $transaction;
    }

    private function confirmContribution(Transaction $transaction): Transaction
    {
        $participation = Participation::whereKey($transaction->participation_id)->lockForUpdate()->firstOrFail();
        $opportunity = Opportunity::whereKey($transaction->opportunity_id)->lockForUpdate()->firstOrFail();

        if (in_array($participation->status, ['CANCELLED', 'REFUNDED'], true)) {
            $this->recordLatePayment($transaction, $participation, $opportunity);

            return $transaction;
        }

        if ($participation->status !== 'PENDING') {
            return $transaction;
        }

        $alreadyParticipating = Participation::where('opportunity_id', $opportunity->id)
            ->where('user_id', $participation->user_id)
            ->where('id', '!=', $participation->id)
            ->whereIn('status', ['CONFIRMED', 'REFUND_PENDING'])
            ->exists();

        $attributes = [
            'committed_amount' => DB::raw('committed_amount + '.(int) $transaction->amount),
            'units_committed' => DB::raw('units_committed + '.(int) $participation->units),
        ];

        if (! $alreadyParticipating) {
            $attributes['participant_count'] = DB::raw('participant_count + 1');
        }

        $claim = Opportunity::whereKey($opportunity->id)
            ->where('status', 'FUNDING')
            ->whereRaw('committed_amount + ? <= target_amount', [$transaction->amount])
            ->when($opportunity->total_units, fn ($query) => $query->whereRaw('units_committed + ? <= total_units', [$participation->units]))
            ->update($attributes);

        if ($claim === 0) {
            $this->recordLatePayment($transaction, $participation, $opportunity, 'CAPACITY_UNAVAILABLE');

            return $transaction;
        }

        $participation->update([
            'status' => 'CONFIRMED',
            'confirmed_at' => now(),
            'entitlement_units' => $participation->units,
        ]);

        $transaction->update(['status' => 'PAID', 'completed_at' => now()]);

        $this->ledger->post(Ledger::contributionReceived((int) $transaction->amount), $opportunity, $participation->user);

        $opportunity->refresh();

        if ($opportunity->committed_amount >= $opportunity->target_amount) {
            $opportunity->update(['status' => 'FUNDED', 'funded_at' => now()]);
            $fee = Money::bps((int) $opportunity->target_amount, (int) $opportunity->fee_bps);
            $this->ledger->post(Ledger::platformFee($fee), $opportunity);
            $this->notifier->participants(
                $opportunity,
                'Funding complete',
                $opportunity->title.' reached its target. You now own a share.',
                'SUCCESS',
                '/opportunities/'.$opportunity->slug,
            );
            $this->notifier->staff(
                'Opportunity funded',
                $opportunity->title.' is fully funded and ready for acquisition.',
                'SUCCESS',
                '/admin/opportunities',
            );
        }

        Notification::sendTo(
            $participation->user_id,
            'Contribution confirmed',
            Money::format((int) $transaction->amount).' was confirmed for '.$opportunity->title.'.',
            'SUCCESS',
            '/participations',
        );

        AuditLog::record($participation->user, 'participation.confirmed', 'participation', $participation->id, null, null, [
            'amount' => $transaction->amount,
        ]);

        return $transaction;
    }

    /** Money arrived after release/cancel: never silently keep it. Queue a refund. */
    private function recordLatePayment(Transaction $transaction, Participation $participation, Opportunity $opportunity, string $reason = 'OPPORTUNITY_CANCELLED'): void
    {
        $transaction->update(['status' => 'PAID', 'completed_at' => now()]);

        $participation->update(['status' => 'REFUND_PENDING', 'cancelled_at' => now()]);

        $this->ledger->post(Ledger::contributionReceived((int) $transaction->amount), $opportunity, $participation->user);
        $this->ledger->post(Ledger::refundApproved((int) $transaction->amount), $opportunity, $participation->user);

        Refund::create([
            'reference' => References::make('REF'),
            'user_id' => $participation->user_id,
            'opportunity_id' => $opportunity->id,
            'participation_id' => $participation->id,
            'transaction_id' => $transaction->id,
            'amount' => (int) $transaction->amount,
            'reason' => $reason,
            'detail' => 'Automatically queued: the contribution could not be accepted.',
            'status' => 'REQUESTED',
        ]);

        Notification::sendTo(
            $participation->user_id,
            'Refund queued',
            'Your payment arrived after capacity closed. A full refund of '.Money::format((int) $transaction->amount).' has been queued.',
            'WARNING',
            '/wallet',
        );

        $this->notifier->staff(
            'Refund needs approval',
            'A late payment for '.$opportunity->title.' has been queued for refund.',
            'WARNING',
            '/admin/refunds',
        );
    }

    /** Participant-initiated cancellation before acquisition. */
    public function cancel(User $user, Participation $participation): Participation
    {
        return DB::transaction(function () use ($user, $participation) {
            $participation = Participation::whereKey($participation->id)->lockForUpdate()->firstOrFail();
            $opportunity = Opportunity::whereKey($participation->opportunity_id)->lockForUpdate()->firstOrFail();

            if ($participation->user_id !== $user->id && ! $user->isStaff()) {
                throw new DomainException('FORBIDDEN', 'You can only cancel your own participation.', 403);
            }

            if ($participation->status === 'PENDING') {
                $participation->update(['status' => 'CANCELLED', 'cancelled_at' => now()]);
                Transaction::where('participation_id', $participation->id)
                    ->where('status', 'PENDING')
                    ->update(['status' => 'CANCELLED', 'failure_reason' => 'Participation cancelled before payment.']);

                return $participation;
            }

            if ($participation->status !== 'CONFIRMED') {
                throw new DomainException('STATE', 'This participation cannot be cancelled.');
            }

            if (! $opportunity->allow_cancellation) {
                throw new DomainException('STATE', 'This opportunity does not allow cancellation.');
            }

            if (in_array($opportunity->status, ['ACQUIRED', 'DISTRIBUTING', 'COMPLETED'], true)) {
                throw new DomainException('STATE', 'Contributions can no longer be cancelled after acquisition.');
            }

            $refundable = (int) $participation->amount - (int) $participation->refunded_amount;

            if ($refundable <= 0) {
                throw new DomainException('REFUND', 'There is no refundable balance on this contribution.');
            }

            if ($opportunity->status === 'FUNDING') {
                $opportunity->update([
                    'committed_amount' => max(0, $opportunity->committed_amount - (int) $participation->amount),
                    'units_committed' => max(0, $opportunity->units_committed - (int) $participation->units),
                ]);
            }

            $participation->update(['status' => 'REFUND_PENDING', 'cancelled_at' => now()]);

            $refund = Refund::create([
                'reference' => References::make('REF'),
                'user_id' => $participation->user_id,
                'opportunity_id' => $opportunity->id,
                'participation_id' => $participation->id,
                'amount' => $refundable,
                'reason' => 'PARTICIPANT_REQUEST',
                'detail' => 'Participant cancelled before acquisition.',
                'status' => 'REQUESTED',
                'requested_by_id' => $user->id,
            ]);

            Approval::create([
                'type' => 'REFUND',
                'entity_id' => (string) $refund->id,
                'summary' => 'Refund '.Money::format($refundable).' for '.$opportunity->title,
                'amount' => $refundable,
                'status' => 'PENDING',
                'requested_by_id' => $user->id,
            ]);

            $this->notifier->staff(
                'Refund requested',
                $user->name.' requested a refund for '.$opportunity->title.'.',
                'WARNING',
                '/admin/refunds',
            );

            AuditLog::record($user, 'refund.requested', 'refund', $refund->id, 'Participant cancellation');

            return $participation->fresh();
        });
    }
}
