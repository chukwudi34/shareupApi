<?php

namespace App\Services;

use App\Exceptions\DomainException;
use App\Models\Approval;
use App\Models\Asset;
use App\Models\AssetIncome;
use App\Models\AuditLog;
use App\Models\Distribution;
use App\Models\DistributionItem;
use App\Models\Fulfillment;
use App\Models\Notification;
use App\Models\Opportunity;
use App\Models\OwnershipInterest;
use App\Models\Participation;
use App\Models\Refund;
use App\Models\SupplierPayout;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Entitlements;
use App\Support\Ledger;
use App\Support\Money;
use App\Support\OpportunityLifecycle;
use App\Support\Permissions;
use App\Support\References;
use Illuminate\Support\Facades\DB;

final class OperationsService
{
    public function __construct(
        private readonly LedgerService $ledger,
        private readonly NotificationService $notifier,
    ) {}

    public function review(User $actor, Opportunity $opportunity, string $decision, ?string $notes): Opportunity
    {
        Permissions::assert($actor, 'opportunity.review');

        $decision = strtoupper($decision);
        $target = match ($decision) {
            'APPROVE' => OpportunityLifecycle::PUBLISHED,
            'REJECT' => OpportunityLifecycle::REJECTED,
            'CHANGES' => OpportunityLifecycle::DRAFT,
            default => throw new DomainException('VALIDATION', 'Decision must be APPROVE, REJECT or CHANGES.'),
        };

        if ($opportunity->status !== OpportunityLifecycle::PENDING_REVIEW) {
            throw new DomainException('STATE', 'This opportunity is not awaiting review.');
        }

        OpportunityLifecycle::assertTransition($opportunity->status, $target);

        DB::transaction(function () use ($actor, $opportunity, $decision, $notes, $target) {
            $opportunity->update([
                'status' => $target,
                'verified_at' => $target === OpportunityLifecycle::PUBLISHED ? now() : $opportunity->verified_at,
            ]);

            $opportunity->reviews()->create([
                'reviewer_id' => $actor->id,
                'decision' => $decision,
                'notes' => $notes,
                'created_at' => now(),
            ]);

            Notification::sendTo(
                $opportunity->creator_id,
                $decision === 'APPROVE' ? 'Opportunity approved' : 'Opportunity needs changes',
                $opportunity->title.' was '.($decision === 'APPROVE' ? 'approved. You can now publish it.' : 'returned with reviewer notes.'),
                $decision === 'APPROVE' ? 'SUCCESS' : 'WARNING',
                '/create',
            );

            AuditLog::record($actor, 'opportunity.reviewed', 'opportunity', $opportunity->id, $notes, ['status' => $opportunity->getOriginal('status')], ['status' => $target]);
        });

        return $opportunity->fresh();
    }

    public function publish(User $actor, Opportunity $opportunity): Opportunity
    {
        Permissions::assert($actor, 'opportunity.publish');

        if ($opportunity->status !== OpportunityLifecycle::PUBLISHED) {
            throw new DomainException('STATE', 'Only approved opportunities can be opened for funding.');
        }

        OpportunityLifecycle::assertPublishable($opportunity);
        OpportunityLifecycle::assertTransition($opportunity->status, OpportunityLifecycle::FUNDING);

        $opportunity->update([
            'status' => OpportunityLifecycle::FUNDING,
            'published_at' => now(),
        ]);

        $this->notifier->participants($opportunity, 'Funding is open', $opportunity->title.' is now accepting contributions.', 'INFO', '/opportunities/'.$opportunity->slug);

        AuditLog::record($actor, 'opportunity.published', 'opportunity', $opportunity->id, null, null, ['status' => 'FUNDING']);

        return $opportunity->fresh();
    }

    public function startAcquisition(User $actor, Opportunity $opportunity): Opportunity
    {
        Permissions::assert($actor, 'asset.manage');

        OpportunityLifecycle::assertTransition($opportunity->status, OpportunityLifecycle::ACQUIRING);

        $opportunity->update(['status' => OpportunityLifecycle::ACQUIRING]);

        AuditLog::record($actor, 'opportunity.acquiring', 'opportunity', $opportunity->id);

        return $opportunity->fresh();
    }

    /** Record the acquisition and issue ownership interests. */
    public function recordAcquisition(User $actor, Opportunity $opportunity, array $data): Asset
    {
        Permissions::assert($actor, 'asset.manage');

        if ($opportunity->status !== OpportunityLifecycle::ACQUIRING) {
            throw new DomainException('STATE', 'Move the opportunity into acquisition before recording it.');
        }

        return DB::transaction(function () use ($actor, $opportunity, $data) {
            $participations = Participation::where('opportunity_id', $opportunity->id)
                ->where('status', 'CONFIRMED')
                ->get();

            if ($participations->isEmpty()) {
                throw new DomainException('STATE', 'An acquisition needs at least one confirmed participant.');
            }

            $totalUnits = (int) $participations->sum('units');
            $fee = Money::bps((int) $opportunity->target_amount, (int) $opportunity->fee_bps);
            $acquisitionAmount = max(0, (int) $opportunity->committed_amount - $fee);

            $asset = Asset::create([
                'reference' => References::make('AST'),
                'opportunity_id' => $opportunity->id,
                'name' => $data['name'] ?? $opportunity->title,
                'category' => $opportunity->category,
                'description' => $data['description'] ?? $opportunity->description,
                'cover_image' => $data['cover_image'] ?? $opportunity->cover_image,
                'acquisition_price' => $acquisitionAmount,
                'current_valuation' => $data['current_valuation'] ?? $acquisitionAmount,
                'valuation_source' => $data['valuation_source'] ?? 'Acquisition price',
                'valuation_at' => now(),
                'acquisition_date' => $data['acquisition_date'] ?? now(),
                'location' => $opportunity->location,
                'legal_structure' => $opportunity->legal_structure ?? 'DIRECT_CO_OWNERSHIP',
                'custodian' => $data['custodian'] ?? $opportunity->supplier_name,
                'status' => 'ACTIVE',
                'total_units' => $totalUnits,
                'documents_note' => $data['documents_note'] ?? null,
            ]);

            foreach ($participations as $participation) {
                OwnershipInterest::firstOrCreate(
                    ['participation_id' => $participation->id],
                    [
                        'asset_id' => $asset->id,
                        'user_id' => $participation->user_id,
                        'units' => $participation->units,
                        'cost_basis' => $participation->amount,
                        'ownership_type' => $opportunity->ownership_type ?? 'DIRECT_CO_OWNERSHIP',
                    ],
                );
            }

            $opportunity->update(['status' => OpportunityLifecycle::ACQUIRED, 'acquired_at' => now()]);

            $this->ledger->post(Ledger::acquisitionCommitted($acquisitionAmount), $opportunity, $actor);

            $this->notifier->participants(
                $opportunity,
                'Asset acquired',
                $asset->name.' was acquired. Your ownership interest is now recorded.',
                'SUCCESS',
                '/assets/'.$asset->id,
            );

            AuditLog::record($actor, 'asset.acquired', 'asset', $asset->id, null, null, [
                'opportunity_id' => $opportunity->id,
                'acquisition_amount' => $acquisitionAmount,
            ]);

            return $asset;
        });
    }

    /** @return array{distribution: Distribution, approval: Approval} */
    public function createDistribution(User $actor, array $data): array
    {
        Permissions::assert($actor, 'distribution.manage');

        $asset = isset($data['asset_id']) ? Asset::findOrFail($data['asset_id']) : null;
        $opportunityId = $data['opportunity_id'] ?? $asset?->opportunity_id;

        if (! $opportunityId) {
            throw new DomainException('VALIDATION', 'A distribution needs an asset or opportunity.');
        }

        $opportunity = Opportunity::findOrFail($opportunityId);
        $type = strtoupper($data['type'] ?? 'FINANCIAL');
        $gross = (int) ($data['gross_amount'] ?? 0);
        $expense = (int) ($data['expense_amount'] ?? 0);

        if ($type === 'FINANCIAL' && $gross <= 0) {
            throw new DomainException('VALIDATION', 'Enter the gross amount received.');
        }

        if ($expense > $gross) {
            throw new DomainException('VALIDATION', 'Expenses cannot exceed the gross amount.');
        }

        $net = $gross - $expense;

        $holders = $asset
            ? OwnershipInterest::where('asset_id', $asset->id)->get(['user_id', 'units'])->map(fn ($o) => ['user_id' => $o->user_id, 'units' => $o->units])->all()
            : Participation::where('opportunity_id', $opportunity->id)->where('status', 'CONFIRMED')->get(['user_id', 'units'])->map(fn ($p) => ['user_id' => $p->user_id, 'units' => $p->units])->all();

        if (empty($holders)) {
            throw new DomainException('STATE', 'There are no confirmed holders to distribute to.');
        }

        return DB::transaction(function () use ($actor, $data, $type, $gross, $expense, $net, $holders, $opportunity, $asset) {
            $distribution = Distribution::create([
                'reference' => References::make('DST'),
                'type' => $type,
                'status' => 'DRAFT',
                'opportunity_id' => $opportunity->id,
                'asset_id' => $asset?->id,
                'title' => $data['title'],
                'gross_amount' => $gross,
                'expense_amount' => $expense,
                'net_amount' => $net,
                'notes' => $data['notes'] ?? null,
                'created_by_id' => $actor->id,
            ]);

            if ($type === 'PHYSICAL') {
                $totalQuantity = (int) ($data['total_quantity'] ?? 0);
                if ($totalQuantity <= 0) {
                    throw new DomainException('VALIDATION', 'Enter the total quantity to distribute.');
                }

                foreach (Entitlements::physical($totalQuantity, $holders) as $allocation) {
                    DistributionItem::create([
                        'distribution_id' => $distribution->id,
                        'user_id' => $allocation['user_id'],
                        'units' => $allocation['units'],
                        'quantity' => $allocation['quantity'],
                        'amount' => 0,
                        'status' => 'PENDING',
                    ]);
                }
            } else {
                $allocation = Entitlements::proRata($net, $holders);

                foreach ($allocation['items'] as $item) {
                    DistributionItem::create([
                        'distribution_id' => $distribution->id,
                        'user_id' => $item['user_id'],
                        'units' => $item['units'],
                        'amount' => $item['amount'],
                        'status' => 'PENDING',
                    ]);
                }
            }

            $approval = Approval::create([
                'type' => 'DISTRIBUTION',
                'entity_id' => (string) $distribution->id,
                'summary' => $type === 'FINANCIAL'
                    ? 'Distribute '.Money::format($net).' to '.count($holders).' holders'
                    : 'Distribute '.(int) ($data['total_quantity'] ?? 0).' units physically',
                'amount' => $net,
                'status' => 'PENDING',
                'requested_by_id' => $actor->id,
            ]);

            AuditLog::record($actor, 'distribution.created', 'distribution', $distribution->id, null, null, [
                'type' => $type,
                'net_amount' => $net,
            ]);

            return ['distribution' => $distribution->fresh('items'), 'approval' => $approval];
        });
    }

    public function approveDistribution(User $actor, Distribution $distribution): Distribution
    {
        Permissions::assert($actor, 'distribution.approve');

        if ($distribution->status !== 'DRAFT') {
            throw new DomainException('STATE', 'Only draft distributions can be approved.');
        }

        Permissions::assertMakerChecker($actor, $distribution->created_by_id);

        DB::transaction(function () use ($actor, $distribution) {
            $distribution->update([
                'status' => 'APPROVED',
                'approved_by_id' => $actor->id,
                'approved_at' => now(),
            ]);

            Approval::where('type', 'DISTRIBUTION')
                ->where('entity_id', (string) $distribution->id)
                ->where('status', 'PENDING')
                ->update([
                    'status' => 'APPROVED',
                    'approved_by_id' => $actor->id,
                    'decided_at' => now(),
                ]);

            AuditLog::record($actor, 'distribution.approved', 'distribution', $distribution->id);
        });

        return $distribution->fresh();
    }

    public function executeDistribution(User $actor, Distribution $distribution): Distribution
    {
        Permissions::assert($actor, 'distribution.manage');

        if ($distribution->status !== 'APPROVED') {
            throw new DomainException('STATE', 'The distribution must be approved before execution.');
        }

        return DB::transaction(function () use ($actor, $distribution) {
            $distribution->load('items');

            if ($distribution->type === 'PHYSICAL') {
                foreach ($distribution->items as $item) {
                    Fulfillment::create([
                        'reference' => References::make('FUL'),
                        'distribution_id' => $distribution->id,
                        'user_id' => $item->user_id,
                        'method' => 'PICKUP',
                        'quantity_units' => $item->quantity,
                        'status' => 'PENDING',
                    ]);

                    $item->update(['status' => 'FULFILLED']);
                }

                $distribution->update(['status' => 'EXECUTED', 'executed_at' => now()]);
            } else {
                if ($distribution->net_amount > 0) {
                    $this->ledger->post(Ledger::distributionDeclared((int) $distribution->net_amount), $distribution->opportunity);
                }

                foreach ($distribution->items as $item) {
                    if ($item->amount <= 0) {
                        continue;
                    }

                    $user = User::whereKey($item->user_id)->lockForUpdate()->firstOrFail();
                    $user->wallet_balance = (int) $user->wallet_balance + (int) $item->amount;
                    $user->save();

                    $this->ledger->post(
                        Ledger::distributionPaid((int) $item->amount, true),
                        $distribution->opportunity,
                        $actor,
                    );

                    $item->update(['status' => 'PAID']);

                    Notification::sendTo(
                        $user->id,
                        'Distribution paid',
                        Money::format((int) $item->amount).' was added to your wallet from '.$distribution->title.'.',
                        'SUCCESS',
                        '/wallet',
                    );

                    Transaction::create([
                        'reference' => References::make('TXN'),
                        'user_id' => $user->id,
                        'opportunity_id' => $distribution->opportunity_id,
                        'type' => 'DISTRIBUTION',
                        'amount' => (int) $item->amount,
                        'status' => 'PAID',
                        'provider' => 'WALLET',
                        'idempotency_key' => 'dist:'.$distribution->id.':'.$item->id,
                        'completed_at' => now(),
                    ]);
                }

                $distribution->update(['status' => 'EXECUTED', 'executed_at' => now()]);
            }

            AuditLog::record($actor, 'distribution.executed', 'distribution', $distribution->id);

            return $distribution->fresh('items');
        });
    }

    public function requestRefund(User $actor, Participation $participation, string $reason, ?string $detail): Refund
    {
        $refundable = (int) $participation->amount - (int) $participation->refunded_amount;

        if ($refundable <= 0 || $participation->status !== 'CONFIRMED') {
            throw new DomainException('REFUND', 'There is no refundable balance on this contribution.');
        }

        return DB::transaction(function () use ($actor, $participation, $reason, $detail, $refundable) {
            $refund = Refund::create([
                'reference' => References::make('REF'),
                'user_id' => $participation->user_id,
                'opportunity_id' => $participation->opportunity_id,
                'participation_id' => $participation->id,
                'amount' => $refundable,
                'reason' => $reason,
                'detail' => $detail,
                'status' => 'REQUESTED',
                'requested_by_id' => $actor->id,
            ]);

            $participation->update(['status' => 'REFUND_PENDING']);

            Approval::create([
                'type' => 'REFUND',
                'entity_id' => (string) $refund->id,
                'summary' => 'Refund '.Money::format($refundable).' to '.$participation->user->name,
                'amount' => $refundable,
                'status' => 'PENDING',
                'requested_by_id' => $actor->id,
            ]);

            $this->notifier->staff('Refund requested', $refund->reference.' is awaiting approval.', 'WARNING', '/admin/refunds');
            AuditLog::record($actor, 'refund.requested', 'refund', $refund->id, $reason);

            return $refund;
        });
    }

    public function approveRefund(User $actor, Refund $refund): Refund
    {
        Permissions::assert($actor, 'refund.approve');

        if ($refund->status !== 'REQUESTED') {
            throw new DomainException('STATE', 'This refund is not awaiting approval.');
        }

        Permissions::assertMakerChecker($actor, $refund->requested_by_id);

        DB::transaction(function () use ($actor, $refund) {
            $refund->update([
                'status' => 'APPROVED',
                'approved_by_id' => $actor->id,
            ]);

            Approval::where('type', 'REFUND')
                ->where('entity_id', (string) $refund->id)
                ->where('status', 'PENDING')
                ->update([
                    'status' => 'APPROVED',
                    'approved_by_id' => $actor->id,
                    'decided_at' => now(),
                ]);

            $this->ledger->post(Ledger::refundApproved((int) $refund->amount), $refund->opportunity, $actor);

            Notification::sendTo(
                $refund->user_id,
                'Refund approved',
                'Your refund of '.Money::format((int) $refund->amount).' was approved and is queued for payout.',
                'SUCCESS',
                '/wallet',
            );

            AuditLog::record($actor, 'refund.approved', 'refund', $refund->id);
        });

        return $refund->fresh();
    }

    public function payRefund(User $actor, Refund $refund): Refund
    {
        Permissions::assert($actor, 'refund.payout');

        if ($refund->status !== 'APPROVED') {
            throw new DomainException('STATE', 'The refund must be approved before payout.');
        }

        return DB::transaction(function () use ($actor, $refund) {
            $refund->update(['status' => 'PAID', 'completed_at' => now()]);

            $this->ledger->post(Ledger::refundPaid((int) $refund->amount), $refund->opportunity, $actor);

            $participation = $refund->participation;
            if ($participation) {
                $refunded = (int) $participation->refunded_amount + (int) $refund->amount;
                $participation->update([
                    'refunded_amount' => $refunded,
                    'status' => $refunded >= (int) $participation->amount ? 'REFUNDED' : 'CONFIRMED',
                ]);
            }

            Transaction::create([
                'reference' => References::make('TXN'),
                'user_id' => $refund->user_id,
                'opportunity_id' => $refund->opportunity_id,
                'participation_id' => $refund->participation_id,
                'type' => 'REFUND',
                'amount' => (int) $refund->amount,
                'status' => 'PAID',
                'provider' => 'BANK_TRANSFER',
                'idempotency_key' => 'refund:'.$refund->id,
                'completed_at' => now(),
            ]);

            Notification::sendTo(
                $refund->user_id,
                'Refund paid',
                Money::format((int) $refund->amount).' was paid out for '.$refund->reference.'.',
                'SUCCESS',
                '/wallet',
            );

            AuditLog::record($actor, 'refund.paid', 'refund', $refund->id);

            return $refund->fresh();
        });
    }

    public function requestSupplierPayout(User $actor, Opportunity $opportunity, int $amount, ?string $note): SupplierPayout
    {
        Permissions::assert($actor, 'payout.request');

        if ($amount <= 0) {
            throw new DomainException('VALIDATION', 'Enter a positive payout amount.');
        }

        if ($amount > $opportunity->committed_amount) {
            throw new DomainException('VALIDATION', 'The payout exceeds the funds committed by participants.');
        }

        return DB::transaction(function () use ($actor, $opportunity, $amount, $note) {
            $payout = SupplierPayout::create([
                'reference' => References::make('PAY'),
                'opportunity_id' => $opportunity->id,
                'amount' => $amount,
                'status' => 'AWAITING_APPROVAL',
                'requested_by_id' => $actor->id,
                'note' => $note,
            ]);

            Approval::create([
                'type' => 'SUPPLIER_PAYOUT',
                'entity_id' => (string) $payout->id,
                'summary' => 'Pay '.$opportunity->supplier_name.' '.Money::format($amount),
                'amount' => $amount,
                'status' => 'PENDING',
                'requested_by_id' => $actor->id,
            ]);

            $this->notifier->staff('Payout awaiting approval', $payout->reference.' needs finance approval.', 'WARNING', '/admin/payouts');

            return $payout;
        });
    }

    public function approveSupplierPayout(User $actor, SupplierPayout $payout): SupplierPayout
    {
        Permissions::assert($actor, 'supplier.approve');

        if ($payout->status !== 'AWAITING_APPROVAL') {
            throw new DomainException('STATE', 'This payout is not awaiting approval.');
        }

        Permissions::assertMakerChecker($actor, $payout->requested_by_id);

        DB::transaction(function () use ($actor, $payout) {
            $payout->update(['status' => 'APPROVED', 'approved_by_id' => $actor->id]);

            Approval::where('type', 'SUPPLIER_PAYOUT')
                ->where('entity_id', (string) $payout->id)
                ->where('status', 'PENDING')
                ->update([
                    'status' => 'APPROVED',
                    'approved_by_id' => $actor->id,
                    'decided_at' => now(),
                ]);

            AuditLog::record($actor, 'payout.approved', 'supplier_payout', $payout->id);
        });

        return $payout->fresh();
    }

    public function paySupplierPayout(User $actor, SupplierPayout $payout): SupplierPayout
    {
        Permissions::assert($actor, 'payout.pay');

        if ($payout->status !== 'APPROVED') {
            throw new DomainException('STATE', 'The payout must be approved before payment.');
        }

        DB::transaction(function () use ($actor, $payout) {
            $payout->update(['status' => 'PAID', 'paid_at' => now()]);

            $this->ledger->post(Ledger::supplierPayout((int) $payout->amount), $payout->opportunity, $actor);

            AuditLog::record($actor, 'payout.paid', 'supplier_payout', $payout->id);
        });

        return $payout->fresh();
    }

    public function recordAssetIncome(User $actor, Asset $asset, array $data): AssetIncome
    {
        Permissions::assert($actor, 'asset.manage');

        $amount = (int) ($data['amount'] ?? 0);
        if ($amount <= 0) {
            throw new DomainException('VALIDATION', 'Enter the income amount.');
        }

        return DB::transaction(function () use ($actor, $asset, $data, $amount) {
            $income = AssetIncome::create([
                'asset_id' => $asset->id,
                'source' => $data['source'],
                'category' => $data['category'] ?? 'OTHER',
                'amount' => $amount,
                'verified' => true,
                'received_at' => $data['received_at'] ?? now(),
                'recorded_by_id' => $actor->id,
                'notes' => $data['notes'] ?? null,
            ]);

            $this->ledger->post(Ledger::unallocatedProceeds($amount), $asset->opportunity, $actor);

            AuditLog::record($actor, 'asset.income_recorded', 'asset', $asset->id, null, null, ['amount' => $amount]);

            return $income;
        });
    }

    /** Cancel funding and queue refunds for everyone who paid. */
    public function cancelOpportunity(User $actor, Opportunity $opportunity, string $reason): Opportunity
    {
        Permissions::assert($actor, 'opportunity.publish');

        if (! in_array($opportunity->status, ['PUBLISHED', 'FUNDING', 'FUNDED', 'PARTIALLY_FUNDED', 'EXPIRED'], true)) {
            throw new DomainException('STATE', 'This opportunity can no longer be cancelled.');
        }

        return DB::transaction(function () use ($actor, $opportunity, $reason) {
            $opportunity->update(['status' => 'REFUNDING']);

            $participations = Participation::where('opportunity_id', $opportunity->id)
                ->where('status', 'CONFIRMED')
                ->get();

            foreach ($participations as $participation) {
                $refundable = (int) $participation->amount - (int) $participation->refunded_amount;
                if ($refundable <= 0) {
                    continue;
                }

                $refund = Refund::create([
                    'reference' => References::make('REF'),
                    'user_id' => $participation->user_id,
                    'opportunity_id' => $opportunity->id,
                    'participation_id' => $participation->id,
                    'amount' => $refundable,
                    'reason' => 'OPPORTUNITY_CANCELLED',
                    'detail' => $reason,
                    'status' => 'APPROVED',
                    'approved_by_id' => $actor->id,
                ]);

                $participation->update(['status' => 'REFUND_PENDING']);
                $this->ledger->post(Ledger::refundApproved($refundable), $opportunity, $actor);

                Notification::sendTo(
                    $participation->user_id,
                    'Opportunity cancelled',
                    $opportunity->title.' was cancelled. Your refund of '.Money::format($refundable).' is queued.',
                    'WARNING',
                    '/wallet',
                );
            }

            $opportunity->update(['status' => 'CANCELLED', 'cancelled_at' => now()]);

            AuditLog::record($actor, 'opportunity.cancelled', 'opportunity', $opportunity->id, $reason);

            return $opportunity->fresh();
        });
    }
}
