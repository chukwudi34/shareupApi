<?php

namespace App\Support;

use App\Exceptions\DomainException;
use App\Models\Opportunity;
use Carbon\CarbonInterface;

final class ParticipationRules
{
    /**
     * Validate a contribution and return the authoritative amount/units.
     *
     * @return array{amount:int, units:int, quantity:int}
     */
    public static function evaluate(
        Opportunity $opportunity,
        ?int $amountKobo,
        ?int $requestedUnits,
        int $existingConfirmedAmount = 0,
        int $participantCount = 0,
        ?CarbonInterface $now = null,
    ): array {
        $now ??= now();

        if ($opportunity->status !== OpportunityLifecycle::FUNDING) {
            throw new DomainException('STATE', 'This opportunity is not currently accepting contributions.');
        }

        if ($opportunity->funding_deadline->lessThanOrEqualTo($now)) {
            throw new DomainException('STATE', 'The funding window for this opportunity has closed.');
        }

        if ($opportunity->max_participants !== null
            && $existingConfirmedAmount === 0
            && $participantCount >= $opportunity->max_participants) {
            throw new DomainException('CAPACITY', 'This opportunity has reached its participant limit.');
        }

        $amount = $amountKobo;
        $units = $requestedUnits ?? 0;

        if ($opportunity->participation_method === 'UNITS') {
            $unitPrice = (int) $opportunity->unit_price;
            $minimumUnits = (int) ($opportunity->min_units ?? 1);

            if ($unitPrice <= 0) {
                throw new DomainException('VALIDATION', 'This opportunity has no unit price configured.');
            }

            if ($units <= 0 && $amount !== null) {
                $units = intdiv($amount, $unitPrice);
            }

            if ($units < $minimumUnits) {
                throw new DomainException('VALIDATION', "The minimum participation is {$minimumUnits} units.");
            }

            if ($opportunity->max_units !== null && $units > $opportunity->max_units) {
                throw new DomainException('LIMIT', "The maximum participation is {$opportunity->max_units} units.");
            }

            $amount = $units * $unitPrice;
        } else {
            if ($amount === null || $amount <= 0) {
                throw new DomainException('VALIDATION', 'Enter a contribution amount.');
            }

            if ($amount < $opportunity->min_contribution) {
                throw new DomainException('VALIDATION', 'The contribution is below the minimum for this opportunity.');
            }

            if ($opportunity->max_contribution !== null && $amount > $opportunity->max_contribution) {
                throw new DomainException('LIMIT', 'The contribution is above the maximum for this opportunity.');
            }

            if ($opportunity->unit_price) {
                $units = intdiv($amount, $opportunity->unit_price);
            }
        }

        if ($opportunity->max_contribution !== null
            && $existingConfirmedAmount + $amount > $opportunity->max_contribution) {
            throw new DomainException('LIMIT', 'This contribution would exceed your maximum for this opportunity.');
        }

        if (! $opportunity->allows_overfunding) {
            $remainingAmount = $opportunity->target_amount - $opportunity->committed_amount;
            if ($amount > $remainingAmount) {
                throw new DomainException('CAPACITY', 'Only '.Money::format(max(0, $remainingAmount)).' of capacity remains.');
            }

            if ($opportunity->total_units) {
                $remainingUnits = $opportunity->total_units - $opportunity->units_committed;
                if ($units > $remainingUnits) {
                    throw new DomainException('CAPACITY', 'Only '.max(0, $remainingUnits).' units remain.');
                }
            }
        }

        if ($opportunity->max_units !== null && $units > $opportunity->max_units) {
            throw new DomainException('LIMIT', 'This contribution exceeds the per-participant unit limit.');
        }

        $quantity = $opportunity->units_per_package
            ? intdiv($units, $opportunity->units_per_package)
            : $units;

        return ['amount' => (int) $amount, 'units' => (int) $units, 'quantity' => (int) $quantity];
    }
}
