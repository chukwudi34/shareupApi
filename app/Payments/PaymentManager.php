<?php

namespace App\Payments;

final class PaymentManager
{
    public static function driver(?string $name = null): PaymentProvider
    {
        return match (strtoupper($name ?? (string) config('shareup.payment_provider'))) {
            'PAYSTACK' => new PaystackProvider,
            default => new SandboxProvider,
        };
    }
}
