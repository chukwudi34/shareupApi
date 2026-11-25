<?php

namespace App\Support;

use App\Models\Asset;
use App\Models\Distribution;
use App\Models\Opportunity;
use App\Models\OwnershipInterest;
use App\Models\Participation;
use App\Models\Refund;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Carbon;

final class Presenter
{
    public static function user(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'role' => $user->role,
            'status' => $user->status,
            'kycStatus' => $user->kyc_status,
            'avatarUrl' => $user->avatar_url,
            'walletBalance' => (int) $user->wallet_balance,
            'initials' => $user->initials(),
            'isStaff' => $user->isStaff(),
            'permissions' => Permissions::forRole($user->role),
            'createdAt' => self::date($user->created_at),
        ];
    }

    public static function opportunity(Opportunity $opportunity, bool $detail = false): array
    {
        $payload = [
            'id' => $opportunity->id,
            'slug' => $opportunity->slug,
            'title' => $opportunity->title,
            'summary' => $opportunity->summary,
            'coverImage' => $opportunity->cover_image,
            'category' => $opportunity->category,
            'model' => $opportunity->model,
            'status' => $opportunity->status,
            'participationMethod' => $opportunity->participation_method,
            'targetAmount' => (int) $opportunity->target_amount,
            'committedAmount' => (int) $opportunity->committed_amount,
            'percentFunded' => $opportunity->percentFunded(),
            'minContribution' => (int) $opportunity->min_contribution,
            'maxContribution' => $opportunity->max_contribution,
            'minUnits' => $opportunity->min_units,
            'unitPrice' => $opportunity->unit_price,
            'totalUnits' => $opportunity->total_units,
            'unitsCommitted' => (int) $opportunity->units_committed,
            'percentUnits' => $opportunity->percentOfUnits(),
            'unitsPerPackage' => $opportunity->units_per_package,
            'packageLabel' => $opportunity->package_label,
            'quantityLabel' => $opportunity->quantity_label,
            'targetQuantity' => $opportunity->target_quantity,
            'participantCount' => (int) $opportunity->participant_count,
            'maxParticipants' => $opportunity->max_participants,
            'fundingDeadline' => self::date($opportunity->funding_deadline),
            'estimatedDeliveryDate' => self::date($opportunity->estimated_delivery_date),
            'location' => $opportunity->location,
            'supplierName' => $opportunity->supplier_name,
            'supplierVerified' => (bool) $opportunity->supplier_verified,
            'allowsPartialFunding' => (bool) $opportunity->allows_partial_funding,
            'allowCancellation' => (bool) $opportunity->allow_cancellation,
            'feeBps' => (int) $opportunity->fee_bps,
            'publishedAt' => self::date($opportunity->published_at),
            'fundedAt' => self::date($opportunity->funded_at),
            'remainingAmount' => $opportunity->remainingAmount(),
            'creatorName' => $opportunity->relationLoaded('creator') ? $opportunity->creator?->name : null,
        ];

        if (! $detail) {
            return $payload;
        }

        return $payload + [
            'description' => $opportunity->description,
            'riskNotes' => $opportunity->risk_notes,
            'acquisitionConditions' => $opportunity->acquisition_conditions,
            'legalStructure' => $opportunity->legal_structure,
            'ownershipType' => $opportunity->ownership_type,
            'regulatoryStatus' => $opportunity->regulatory_status,
            'policyVersion' => (int) $opportunity->policy_version,
            'policyTerms' => $opportunity->policy_terms ? json_decode($opportunity->policy_terms, true) : null,
            'expectedAcquisitionDate' => self::date($opportunity->expected_acquisition_date),
            'creatorSource' => $opportunity->creator_source,
            'supplierContact' => $opportunity->supplier_contact,
            'createdAt' => self::date($opportunity->created_at),
            'documents' => $opportunity->relationLoaded('documents')
                ? $opportunity->documents->map(fn ($document) => [
                    'id' => $document->id,
                    'title' => $document->title,
                    'kind' => $document->kind,
                    'verified' => (bool) $document->verified,
                    'fileUrl' => $document->file_url,
                    'uploadedAt' => self::date($document->uploaded_at),
                ])->all()
                : [],
            'evidence' => $opportunity->relationLoaded('evidence')
                ? $opportunity->evidence->map(fn ($evidence) => [
                    'id' => $evidence->id,
                    'title' => $evidence->title,
                    'vendor' => $evidence->vendor,
                    'reference' => $evidence->reference,
                    'amount' => (int) $evidence->amount,
                    'verified' => $evidence->verified_at !== null,
                ])->all()
                : [],
            'assetId' => $opportunity->relationLoaded('assets') ? $opportunity->assets->first()?->id : null,
        ];
    }

    public static function participation(Participation $participation): array
    {
        return [
            'id' => $participation->id,
            'reference' => $participation->reference,
            'amount' => (int) $participation->amount,
            'units' => (int) $participation->units,
            'quantity' => (int) $participation->quantity,
            'status' => $participation->status,
            'entitlementUnits' => (int) $participation->entitlement_units,
            'refundedAmount' => (int) $participation->refunded_amount,
            'confirmedAt' => self::date($participation->confirmed_at),
            'createdAt' => self::date($participation->created_at),
            'opportunity' => $participation->relationLoaded('opportunity')
                ? self::opportunity($participation->opportunity)
                : null,
        ];
    }

    public static function transaction(Transaction $transaction): array
    {
        return [
            'id' => $transaction->id,
            'reference' => $transaction->reference,
            'type' => $transaction->type,
            'amount' => (int) $transaction->amount,
            'status' => $transaction->status,
            'provider' => $transaction->provider,
            'providerRef' => $transaction->provider_ref,
            'opportunityTitle' => $transaction->relationLoaded('opportunity') ? $transaction->opportunity?->title : null,
            'metadata' => $transaction->metadata,
            'failureReason' => $transaction->failure_reason,
            'createdAt' => self::date($transaction->created_at),
            'completedAt' => self::date($transaction->completed_at),
        ];
    }

    public static function asset(Asset $asset, ?OwnershipInterest $ownership = null): array
    {
        return [
            'id' => $asset->id,
            'reference' => $asset->reference,
            'name' => $asset->name,
            'category' => $asset->category,
            'description' => $asset->description,
            'coverImage' => $asset->cover_image,
            'acquisitionPrice' => (int) $asset->acquisition_price,
            'currentValuation' => (int) ($asset->current_valuation ?? $asset->acquisition_price),
            'valuationSource' => $asset->valuation_source,
            'valuationAt' => self::date($asset->valuation_at),
            'acquisitionDate' => self::date($asset->acquisition_date),
            'location' => $asset->location,
            'legalStructure' => $asset->legal_structure,
            'custodian' => $asset->custodian,
            'status' => $asset->status,
            'totalUnits' => (int) $asset->total_units,
            'ownership' => $ownership ? [
                'units' => (int) $ownership->units,
                'costBasis' => (int) $ownership->cost_basis,
                'sharePercent' => $asset->total_units > 0
                    ? round(($ownership->units / $asset->total_units) * 100, 2)
                    : 0,
                'ownershipType' => $ownership->ownership_type,
            ] : null,
            'incomes' => $asset->relationLoaded('incomes')
                ? $asset->incomes->map(fn ($income) => [
                    'id' => $income->id,
                    'source' => $income->source,
                    'category' => $income->category,
                    'amount' => (int) $income->amount,
                    'receivedAt' => self::date($income->received_at),
                ])->all()
                : [],
            'reports' => $asset->relationLoaded('reports')
                ? $asset->reports->map(fn ($report) => [
                    'id' => $report->id,
                    'title' => $report->title,
                    'kind' => $report->kind,
                    'body' => $report->body,
                    'publishedAt' => self::date($report->published_at),
                ])->all()
                : [],
        ];
    }

    public static function refund(Refund $refund): array
    {
        return [
            'id' => $refund->id,
            'reference' => $refund->reference,
            'amount' => (int) $refund->amount,
            'reason' => $refund->reason,
            'reasonLabel' => RefundPolicy::label($refund->reason),
            'detail' => $refund->detail,
            'status' => $refund->status,
            'userName' => $refund->relationLoaded('user') ? $refund->user?->name : null,
            'opportunityTitle' => $refund->relationLoaded('opportunity') ? $refund->opportunity?->title : null,
            'requestedById' => $refund->requested_by_id,
            'providerRef' => $refund->provider_ref,
            'createdAt' => self::date($refund->created_at),
            'completedAt' => self::date($refund->completed_at),
        ];
    }

    public static function distribution(Distribution $distribution): array
    {
        return [
            'id' => $distribution->id,
            'reference' => $distribution->reference,
            'type' => $distribution->type,
            'status' => $distribution->status,
            'title' => $distribution->title,
            'grossAmount' => (int) $distribution->gross_amount,
            'expenseAmount' => (int) $distribution->expense_amount,
            'netAmount' => (int) $distribution->net_amount,
            'notes' => $distribution->notes,
            'createdById' => $distribution->created_by_id,
            'approvedById' => $distribution->approved_by_id,
            'itemCount' => $distribution->relationLoaded('items') ? $distribution->items->count() : null,
            'assetName' => $distribution->relationLoaded('asset') ? $distribution->asset?->name : null,
            'opportunityTitle' => $distribution->relationLoaded('opportunity') ? $distribution->opportunity?->title : null,
            'createdAt' => self::date($distribution->created_at),
            'executedAt' => self::date($distribution->executed_at),
        ];
    }

    public static function date($value): ?string
    {
        return $value ? Carbon::parse($value)->toIso8601String() : null;
    }
}
