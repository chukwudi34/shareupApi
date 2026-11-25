<?php

namespace App\Http\Controllers;

use App\Exceptions\DomainException;
use App\Models\Transaction;
use App\Services\FundingService;
use App\Support\Presenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentController extends Controller
{
    public function __construct(private readonly FundingService $funding) {}

    public function sandboxShow(Request $request, string $reference): JsonResponse
    {
        $transaction = Transaction::with(['opportunity', 'participation'])
            ->where('reference', $reference)
            ->firstOrFail();

        if ($transaction->user_id !== $request->user()->id) {
            throw new DomainException('FORBIDDEN', 'This checkout belongs to another account.', 403);
        }

        return response()->json([
            'data' => [
                'transaction' => Presenter::transaction($transaction),
                'opportunity' => $transaction->opportunity ? Presenter::opportunity($transaction->opportunity) : null,
                'signature' => $transaction->metadata['sandboxSignature'] ?? null,
                'sandbox' => $transaction->provider === 'SANDBOX',
            ],
        ]);
    }

    public function sandboxSettle(Request $request): JsonResponse
    {
        $data = $request->validate([
            'reference' => ['required', 'string'],
            'signature' => ['required', 'string'],
        ]);

        $transaction = Transaction::where('reference', $data['reference'])->firstOrFail();

        if ($transaction->user_id !== $request->user()->id) {
            throw new DomainException('FORBIDDEN', 'This checkout belongs to another account.', 403);
        }

        $confirmed = $this->funding->confirm($transaction, $data['signature']);

        return response()->json([
            'data' => Presenter::transaction($confirmed),
            'message' => $confirmed->status === 'PAID'
                ? 'Payment verified and recorded.'
                : 'Payment received.',
        ]);
    }

    /** Paystack server-to-server webhook. Signature is checked before anything else. */
    public function paystackWebhook(Request $request): JsonResponse
    {
        $secret = (string) config('shareup.paystack.secret_key');
        $signature = (string) $request->header('x-paystack-signature', '');
        $expected = hash_hmac('sha256', $request->getContent(), $secret);

        if ($secret === '' || ! hash_equals($expected, $signature)) {
            return response()->json(['message' => 'Invalid signature.'], 401);
        }

        $payload = $request->json()->all();

        if (($payload['event'] ?? null) !== 'charge.success') {
            return response()->json(['message' => 'Ignored.']);
        }

        $transaction = Transaction::where('reference', $payload['data']['reference'] ?? '')->first();

        if (! $transaction || $transaction->status !== 'PENDING') {
            return response()->json(['message' => 'Nothing to do.']);
        }

        try {
            $this->funding->confirm($transaction);
        } catch (\Throwable $exception) {
            report($exception);

            return response()->json(['message' => 'Verification failed; provider will retry.'], 422);
        }

        return response()->json(['message' => 'Recorded.']);
    }
}
