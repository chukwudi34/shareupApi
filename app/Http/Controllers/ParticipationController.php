<?php

namespace App\Http\Controllers;

use App\Models\Opportunity;
use App\Models\Participation;
use App\Models\Transaction;
use App\Services\FundingService;
use App\Support\Money;
use App\Support\Presenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ParticipationController extends Controller
{
    public function __construct(private readonly FundingService $funding) {}

    public function index(Request $request): JsonResponse
    {
        $items = Participation::with('opportunity')
            ->where('user_id', $request->user()->id)
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')->toString()))
            ->latest()
            ->get()
            ->map(function (Participation $participation) {
                $payload = Presenter::participation($participation);
                $payload['checkoutUrl'] = null;

                if ($participation->status === 'PENDING') {
                    $transaction = Transaction::where('participation_id', $participation->id)
                        ->where('status', 'PENDING')
                        ->latest()
                        ->first();

                    $payload['checkoutUrl'] = $transaction ? '/pay/sandbox/'.$transaction->reference : null;
                }

                return $payload;
            });

        return response()->json(['data' => $items]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'opportunityId' => ['required', 'integer', 'exists:opportunities,id'],
            'amountKobo' => ['nullable', 'integer', 'min:1'],
            'amount' => ['nullable'],
            'units' => ['nullable', 'integer', 'min:1'],
            'idempotencyKey' => ['required', 'string', 'max:120'],
        ]);

        $amountKobo = $data['amountKobo'] ?? (isset($data['amount']) ? Money::parse($data['amount']) : null);

        $result = $this->funding->start(
            $request->user(),
            Opportunity::findOrFail($data['opportunityId']),
            $amountKobo,
            $data['units'] ?? null,
            $data['idempotencyKey'],
        );

        return response()->json([
            'data' => [
                'participation' => Presenter::participation($result['participation']),
                'transaction' => Presenter::transaction($result['transaction']),
                'authorizationUrl' => $result['authorizationUrl'],
                'sandboxSignature' => $result['sandboxSignature'],
            ],
        ], 201);
    }

    public function cancel(Request $request, Participation $participation): JsonResponse
    {
        $updated = $this->funding->cancel($request->user(), $participation);

        return response()->json(['data' => Presenter::participation($updated->load('opportunity'))]);
    }
}
