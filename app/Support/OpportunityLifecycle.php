<?php

namespace App\Support;

use App\Exceptions\DomainException;
use App\Models\Opportunity;

final class OpportunityLifecycle
{
    public const DRAFT = 'DRAFT';

    public const PENDING_REVIEW = 'PENDING_REVIEW';

    public const PUBLISHED = 'PUBLISHED';

    public const FUNDING = 'FUNDING';

    public const FUNDED = 'FUNDED';

    public const PARTIALLY_FUNDED = 'PARTIALLY_FUNDED';

    public const ACQUIRING = 'ACQUIRING';

    public const ACQUIRED = 'ACQUIRED';

    public const DISTRIBUTING = 'DISTRIBUTING';

    public const COMPLETED = 'COMPLETED';

    public const REFUNDING = 'REFUNDING';

    public const CANCELLED = 'CANCELLED';

    public const EXPIRED = 'EXPIRED';

    public const REJECTED = 'REJECTED';

    public const TRANSITIONS = [
        self::DRAFT => [self::PENDING_REVIEW, self::CANCELLED],
        self::PENDING_REVIEW => [self::PUBLISHED, self::DRAFT, self::REJECTED],
        self::PUBLISHED => [self::FUNDING, self::CANCELLED],
        self::FUNDING => [self::FUNDED, self::PARTIALLY_FUNDED, self::CANCELLED, self::EXPIRED],
        self::FUNDED => [self::ACQUIRING, self::REFUNDING, self::CANCELLED],
        self::PARTIALLY_FUNDED => [self::ACQUIRING, self::REFUNDING, self::CANCELLED],
        self::ACQUIRING => [self::ACQUIRED, self::FUNDED, self::REFUNDING],
        self::ACQUIRED => [self::DISTRIBUTING, self::COMPLETED],
        self::DISTRIBUTING => [self::COMPLETED],
        self::REFUNDING => [self::CANCELLED],
        self::EXPIRED => [self::FUNDING, self::REFUNDING],
        self::REJECTED => [self::DRAFT],
        self::COMPLETED => [],
        self::CANCELLED => [],
    ];

    public static function can(string $from, string $to): bool
    {
        return in_array($to, self::TRANSITIONS[$from] ?? [], true);
    }

    public static function assertTransition(string $from, string $to): void
    {
        if (! self::can($from, $to)) {
            throw new DomainException('STATE', "Cannot move opportunity from {$from} to {$to}.");
        }
    }

    public static function isLive(string $status): bool
    {
        return in_array($status, [self::PUBLISHED, self::FUNDING], true);
    }

    /** Validate everything a public offer must have before review. */
    public static function assertPublishable(Opportunity $opportunity): void
    {
        $required = [
            'title', 'summary', 'description', 'cover_image', 'category',
            'supplier_name', 'target_amount', 'min_contribution',
            'funding_deadline', 'location', 'risk_notes', 'acquisition_conditions',
        ];

        foreach ($required as $field) {
            if (blank($opportunity->{$field})) {
                throw new DomainException('PUBLICATION', "The {$field} field is required before review.");
            }
        }

        if ($opportunity->target_amount < $opportunity->min_contribution) {
            throw new DomainException('PUBLICATION', 'Target amount must be at least the minimum contribution.');
        }

        if ($opportunity->min_contribution <= 0) {
            throw new DomainException('PUBLICATION', 'Minimum contribution must be greater than zero.');
        }

        if ($opportunity->funding_deadline->isPast()) {
            throw new DomainException('PUBLICATION', 'The funding deadline must be in the future.');
        }

        if ($opportunity->participation_method === 'UNITS') {
            if (! $opportunity->unit_price || ! $opportunity->total_units || ! $opportunity->min_units) {
                throw new DomainException('PUBLICATION', 'Unit-based offers need a unit price, total units and minimum units.');
            }
            if ($opportunity->min_units * $opportunity->unit_price < $opportunity->min_contribution) {
                throw new DomainException('PUBLICATION', 'Minimum units price must meet the minimum contribution.');
            }
        }
    }

    /** Funding outcome based on committed amount against target (80% partial threshold). */
    public static function fundingOutcome(Opportunity $opportunity): string
    {
        if ($opportunity->committed_amount >= $opportunity->target_amount) {
            return self::FUNDED;
        }

        if ($opportunity->allows_partial_funding
            && $opportunity->committed_amount >= (int) ceil($opportunity->target_amount * 0.8)) {
            return self::PARTIALLY_FUNDED;
        }

        return self::FUNDING;
    }
}
