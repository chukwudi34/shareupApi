<?php

namespace App\Payments;

use App\Exceptions\DomainException;

/**
 * Local payment simulator. It behaves like a provider: a signed checkout intent
 * is issued server-side, and settling requires that exact signature.
 */
final class SandboxProvider implements PaymentProvider
{
    public function signature(string $reference, int $amountKobo): string
    {
        return hash_hmac('sha256', $reference.':'.$amountKobo, (string) config('shareup.sandbox.secret'));
    }

    public function verify(string $reference, int $amountKobo, ?string $signature = null): bool
    {
        if (! $signature) {
            throw new DomainException('PAYMENT', 'A signed sandbox payment intent is required.');
        }

        return hash_equals($this->signature($reference, $amountKobo), $signature);
    }

    public function name(): string
    {
        return 'SANDBOX';
    }
}
