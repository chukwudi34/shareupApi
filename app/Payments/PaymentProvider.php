<?php

namespace App\Payments;

interface PaymentProvider
{
    /** Verify that the provider really collected this amount for this reference. */
    public function verify(string $reference, int $amountKobo, ?string $signature = null): bool;

    public function name(): string;
}
