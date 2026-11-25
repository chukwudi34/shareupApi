<?php

namespace App\Support;

use App\Exceptions\DomainException;
use App\Models\Opportunity;
use App\Models\Participation;

final class RefundPolicy
{
    public const REASONS = [
        'FUNDING_FAILED' => 'Funding did not reach its target',
        'OPPORTUNITY_CANCELLED' => 'Opportunity cancelled',
        'CAPACITY_UNAVAILABLE' => 'Contribution arrived after capacity was filled',
        'DUPLICATE_PAYMENT' => 'Duplicate payment',
        'SUPPLIER_FAILURE' => 'Supplier could not fulfil the order',
        'PARTICIPANT_REQUEST' => 'Participant requested a refund',
        'DISPUTE_UPHELD' => 'Dispute resolved in the participant’s favour',
    ];

    public static function refundableBalance(Participation $participation): int
    {
        return max(0, (int) $participation->amount - (int) $participation->refunded_amount);
    }

    public static function assertCanRequest(Opportunity $opportunity, Participation $participation): void
    {
        if (! in_array($participation->status, ['CONFIRMED', 'REFUND_PENDING'], true)) {
            throw new DomainException('REFUND', 'Only confirmed contributions can be refunded.');
        }

        if (in_array($opportunity->status, ['ACQUIRED', 'DISTRIBUTING', 'COMPLETED'], true)) {
            throw new DomainException('REFUND', 'Contributions are no longer refundable after acquisition.');
        }

        if (self::refundableBalance($participation) <= 0) {
            throw new DomainException('REFUND', 'There is no refundable balance on this contribution.');
        }
    }

    public static function label(string $reason): string
    {
        return self::REASONS[$reason] ?? $reason;
    }
}
