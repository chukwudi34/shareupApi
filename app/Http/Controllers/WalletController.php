<?php

namespace App\Http\Controllers;

use App\Models\Refund;
use App\Models\Transaction;
use App\Services\FundingService;
use App\Support\Money;
use App\Support\Presenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WalletController extends Controller
{
    public function __construct(private readonly FundingService $funding) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $transactions = Transaction::with('opportunity')
            ->where('user_id', $user->id)
            ->latest()
            ->limit(50)
            ->get()
            ->map(fn (Transaction $transaction) => Presenter::transaction($transaction));

        $refunds = Refund::with(['opportunity'])
            ->where('user_id', $user->id)
            ->latest()
            ->limit(20)
            ->get()
            ->map(fn (Refund $refund) => Presenter::refund($refund));

        return response()->json([
            'data' => [
                'balance' => (int) $user->wallet_balance,
                'transactions' => $transactions,
                'refunds' => $refunds,
            ],
        ]);
    }

    public function deposit(Request $request): JsonResponse
    {
        $data = $request->validate([
            'amountKobo' => ['nullable', 'integer', 'min:10000'],
            'amount' => ['nullable'],
            'idempotencyKey' => ['required', 'string', 'max:120'],
        ]);

        $amountKobo = $data['amountKobo'] ?? (isset($data['amount']) ? Money::parse($data['amount']) : null);

        if (! $amountKobo) {
            return response()->json(['message' => 'Enter a deposit amount.'], 422);
        }

        $result = $this->funding->startDeposit($request->user(), $amountKobo, $data['idempotencyKey']);

        return response()->json([
            'data' => [
                'transaction' => Presenter::transaction($result['transaction']),
                'authorizationUrl' => $result['authorizationUrl'],
                'sandboxSignature' => $result['sandboxSignature'],
            ],
        ], 201);
    }
}
