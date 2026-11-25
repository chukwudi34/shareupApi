<?php

namespace App\Payments;

use Illuminate\Support\Facades\Http;

final class PaystackProvider implements PaymentProvider
{
    public function verify(string $reference, int $amountKobo, ?string $signature = null): bool
    {
        $secret = config('shareup.paystack.secret_key');

        if (! $secret) {
            return false;
        }

        $response = Http::withToken($secret)
            ->acceptJson()
            ->get(rtrim((string) config('shareup.paystack.base_url'), '/').'/transaction/verify/'.rawurlencode($reference));

        if (! $response->successful()) {
            return false;
        }

        $data = $response->json('data', []);

        return ($data['status'] ?? null) === 'success'
            && (int) ($data['amount'] ?? 0) === $amountKobo
            && ($data['currency'] ?? 'NGN') === 'NGN';
    }

    public function name(): string
    {
        return 'PAYSTACK';
    }
}
