<?php

namespace App\Http\Controllers;

use App\Models\Approval;
use App\Models\Asset;
use App\Models\AuditLog;
use App\Models\Distribution;
use App\Models\LedgerEntry;
use App\Models\Notification;
use App\Models\Opportunity;
use App\Models\Participation;
use App\Models\Refund;
use App\Models\SupplierPayout;
use App\Models\Transaction;
use App\Models\User;
use App\Services\LedgerService;
use App\Services\OperationsService;
use App\Support\Presenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminController extends Controller
{
    public function __construct(
        private readonly OperationsService $operations,
        private readonly LedgerService $ledger,
    ) {}

    public function overview(): JsonResponse
    {
        return response()->json([
            'data' => [
                'stats' => [
                    'participants' => User::where('role', 'PARTICIPANT')->count(),
                    'fundingCount' => Opportunity::where('status', 'FUNDING')->count(),
                    'committedAmount' => (int) Opportunity::whereIn('status', ['FUNDING', 'FUNDED', 'ACQUIRED', 'DISTRIBUTING', 'COMPLETED'])->sum('committed_amount'),
                    'safeguardedFunds' => $this->ledger->balance('1000'),
                    'platformRevenue' => $this->ledger->balance('4000'),
                    'pendingApprovals' => Approval::where('status', 'PENDING')->count(),
                    'pendingRefunds' => Refund::where('status', 'REQUESTED')->count(),
                    'activeAssets' => Asset::where('status', 'ACTIVE')->count(),
                ],
                'pendingReviews' => Opportunity::with('creator')
                    ->where('status', 'PENDING_REVIEW')
                    ->latest()
                    ->limit(6)
                    ->get()
                    ->map(fn (Opportunity $opportunity) => Presenter::opportunity($opportunity)),
                'recentOpportunities' => Opportunity::with('creator')
                    ->latest()
                    ->limit(8)
                    ->get()
                    ->map(fn (Opportunity $opportunity) => Presenter::opportunity($opportunity)),
                'approvals' => $this->approvalPayload(),
                'recentAudit' => $this->auditPayload(),
            ],
        ]);
    }

    public function opportunities(Request $request): JsonResponse
    {
        $items = Opportunity::with('creator')
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')->toString()))
            ->latest()
            ->limit(100)
            ->get()
            ->map(fn (Opportunity $opportunity) => Presenter::opportunity($opportunity, true));

        return response()->json(['data' => $items]);
    }

    public function review(Request $request, Opportunity $opportunity): JsonResponse
    {
        $data = $request->validate([
            'decision' => ['required', 'in:APPROVE,REJECT,CHANGES'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $updated = $this->operations->review($request->user(), $opportunity, $data['decision'], $data['notes'] ?? null);

        return response()->json(['data' => Presenter::opportunity($updated, true)]);
    }

    public function publish(Request $request, Opportunity $opportunity): JsonResponse
    {
        $updated = $this->operations->publish($request->user(), $opportunity);

        return response()->json(['data' => Presenter::opportunity($updated, true)]);
    }

    public function cancel(Request $request, Opportunity $opportunity): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);

        $updated = $this->operations->cancelOpportunity($request->user(), $opportunity, $data['reason']);

        return response()->json(['data' => Presenter::opportunity($updated, true)]);
    }

    public function startAcquisition(Request $request, Opportunity $opportunity): JsonResponse
    {
        $updated = $this->operations->startAcquisition($request->user(), $opportunity);

        return response()->json(['data' => Presenter::opportunity($updated, true)]);
    }

    public function recordAcquisition(Request $request, Opportunity $opportunity): JsonResponse
    {
        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:160'],
            'description' => ['nullable', 'string'],
            'coverImage' => ['nullable', 'string'],
            'custodian' => ['nullable', 'string', 'max:160'],
            'currentValuation' => ['nullable', 'integer', 'min:0'],
            'documentsNote' => ['nullable', 'string'],
        ]);

        $asset = $this->operations->recordAcquisition($request->user(), $opportunity, $data);

        return response()->json(['data' => Presenter::asset($asset->load('incomes'))], 201);
    }

    public function refunds(): JsonResponse
    {
        $items = Refund::with(['user', 'opportunity'])
            ->latest()
            ->limit(100)
            ->get()
            ->map(fn (Refund $refund) => Presenter::refund($refund));

        return response()->json(['data' => $items]);
    }

    public function approveRefund(Request $request, Refund $refund): JsonResponse
    {
        $updated = $this->operations->approveRefund($request->user(), $refund);

        return response()->json(['data' => Presenter::refund($updated->load(['user', 'opportunity']))]);
    }

    public function payRefund(Request $request, Refund $refund): JsonResponse
    {
        $updated = $this->operations->payRefund($request->user(), $refund);

        return response()->json(['data' => Presenter::refund($updated->load(['user', 'opportunity']))]);
    }

    public function distributions(): JsonResponse
    {
        $items = Distribution::with(['asset', 'opportunity', 'items'])
            ->latest()
            ->limit(100)
            ->get()
            ->map(fn (Distribution $distribution) => Presenter::distribution($distribution));

        return response()->json(['data' => $items]);
    }

    public function createDistribution(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', 'in:FINANCIAL,PHYSICAL'],
            'assetId' => ['nullable', 'integer', 'exists:assets,id'],
            'opportunityId' => ['nullable', 'integer', 'exists:opportunities,id'],
            'title' => ['required', 'string', 'max:160'],
            'grossAmount' => ['nullable', 'integer', 'min:0'],
            'expenseAmount' => ['nullable', 'integer', 'min:0'],
            'totalQuantity' => ['nullable', 'integer', 'min:1'],
            'notes' => ['nullable', 'string'],
        ]);

        $result = $this->operations->createDistribution($request->user(), [
            'type' => $data['type'],
            'asset_id' => $data['assetId'] ?? null,
            'opportunity_id' => $data['opportunityId'] ?? null,
            'title' => $data['title'],
            'gross_amount' => $data['grossAmount'] ?? 0,
            'expense_amount' => $data['expenseAmount'] ?? 0,
            'total_quantity' => $data['totalQuantity'] ?? 0,
            'notes' => $data['notes'] ?? null,
        ]);

        return response()->json(['data' => Presenter::distribution($result['distribution'])], 201);
    }

    public function approveDistribution(Request $request, Distribution $distribution): JsonResponse
    {
        $updated = $this->operations->approveDistribution($request->user(), $distribution);

        return response()->json(['data' => Presenter::distribution($updated->load('items'))]);
    }

    public function executeDistribution(Request $request, Distribution $distribution): JsonResponse
    {
        $updated = $this->operations->executeDistribution($request->user(), $distribution);

        return response()->json(['data' => Presenter::distribution($updated->load('items'))]);
    }

    public function payouts(): JsonResponse
    {
        $items = SupplierPayout::with(['opportunity', 'requester'])
            ->latest()
            ->limit(100)
            ->get()
            ->map(fn (SupplierPayout $payout) => $this->payoutPayload($payout));

        return response()->json(['data' => $items]);
    }

    public function createPayout(Request $request): JsonResponse
    {
        $data = $request->validate([
            'opportunityId' => ['required', 'integer', 'exists:opportunities,id'],
            'amount' => ['required', 'integer', 'min:1'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $payout = $this->operations->requestSupplierPayout(
            $request->user(),
            Opportunity::findOrFail($data['opportunityId']),
            $data['amount'],
            $data['note'] ?? null,
        );

        return response()->json(['data' => $this->payoutPayload($payout->load(['opportunity', 'requester']))], 201);
    }

    public function approvePayout(Request $request, SupplierPayout $payout): JsonResponse
    {
        $updated = $this->operations->approveSupplierPayout($request->user(), $payout);

        return response()->json(['data' => $this->payoutPayload($updated->load(['opportunity', 'requester']))]);
    }

    public function payPayout(Request $request, SupplierPayout $payout): JsonResponse
    {
        $updated = $this->operations->paySupplierPayout($request->user(), $payout);

        return response()->json(['data' => $this->payoutPayload($updated->load(['opportunity', 'requester']))]);
    }

    public function ledger(): JsonResponse
    {
        $entries = LedgerEntry::with(['lines.account'])
            ->latest('id')
            ->limit(40)
            ->get()
            ->map(fn (LedgerEntry $entry) => [
                'id' => $entry->id,
                'reference' => $entry->reference,
                'kind' => $entry->kind,
                'description' => $entry->description,
                'postedAt' => Presenter::date($entry->posted_at),
                'lines' => $entry->lines->map(fn ($line) => [
                    'code' => $line->account?->code,
                    'name' => $line->account?->name,
                    'direction' => $line->direction,
                    'amount' => (int) $line->amount,
                ])->all(),
            ]);

        return response()->json([
            'data' => [
                'trialBalance' => $this->ledger->trialBalance(),
                'entries' => $entries,
            ],
        ]);
    }

    public function approvals(): JsonResponse
    {
        return response()->json(['data' => $this->approvalPayload()]);
    }

    public function audit(): JsonResponse
    {
        return response()->json(['data' => $this->auditPayload(60)]);
    }

    public function users(): JsonResponse
    {
        $users = User::withCount(['participations'])
            ->latest()
            ->limit(100)
            ->get()
            ->map(fn (User $user) => Presenter::user($user) + [
                'participationCount' => $user->participations_count,
            ]);

        return response()->json(['data' => $users]);
    }

    public function assets(): JsonResponse
    {
        $assets = Asset::with('opportunity')
            ->withCount('ownerships')
            ->latest()
            ->limit(100)
            ->get()
            ->map(fn (Asset $asset) => Presenter::asset($asset) + [
                'ownerCount' => $asset->ownerships_count,
                'opportunityTitle' => $asset->opportunity?->title,
            ]);

        return response()->json(['data' => $assets]);
    }

    public function recordIncome(Request $request, Asset $asset): JsonResponse
    {
        $data = $request->validate([
            'source' => ['required', 'string', 'max:160'],
            'category' => ['nullable', 'string', 'max:80'],
            'amount' => ['required', 'integer', 'min:1'],
            'receivedAt' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
        ]);

        $income = $this->operations->recordAssetIncome($request->user(), $asset, $data);

        return response()->json([
            'data' => [
                'id' => $income->id,
                'source' => $income->source,
                'amount' => (int) $income->amount,
                'receivedAt' => Presenter::date($income->received_at),
            ],
        ], 201);
    }

    public function portfolioAudit(): JsonResponse
    {
        $confirmed = Participation::where('status', 'CONFIRMED')->get();

        return response()->json([
            'data' => [
                'confirmedParticipations' => $confirmed->count(),
                'participantFunds' => $this->ledger->balance('2000'),
                'refundPayable' => $this->ledger->balance('2200'),
                'distributionPayable' => $this->ledger->balance('2300'),
                'walletPayable' => $this->ledger->balance('2400'),
                'pendingTransactions' => Transaction::where('status', 'PENDING')->count(),
                'ledgerEntries' => LedgerEntry::count(),
                'unbalancedEntries' => DB::table('ledger_entries')
                    ->join('ledger_lines', 'ledger_entries.id', '=', 'ledger_lines.ledger_entry_id')
                    ->groupBy('ledger_entries.id')
                    ->havingRaw("sum(case when direction = 'DEBIT' then amount else -amount end) <> 0")
                    ->count(),
            ],
        ]);
    }

    private function payoutPayload(SupplierPayout $payout): array
    {
        return [
            'id' => $payout->id,
            'reference' => $payout->reference,
            'amount' => (int) $payout->amount,
            'status' => $payout->status,
            'note' => $payout->note,
            'requestedById' => $payout->requested_by_id,
            'approvedById' => $payout->approved_by_id,
            'opportunityTitle' => $payout->opportunity?->title,
            'supplierName' => $payout->opportunity?->supplier_name,
            'createdAt' => Presenter::date($payout->created_at),
            'paidAt' => Presenter::date($payout->paid_at),
        ];
    }

    private function approvalPayload(): array
    {
        return Approval::with('requester')
            ->where('status', 'PENDING')
            ->latest()
            ->limit(30)
            ->get()
            ->map(fn (Approval $approval) => [
                'id' => $approval->id,
                'type' => $approval->type,
                'entityId' => $approval->entity_id,
                'summary' => $approval->summary,
                'amount' => $approval->amount,
                'status' => $approval->status,
                'requestedById' => $approval->requested_by_id,
                'requesterName' => $approval->requester?->name,
                'createdAt' => Presenter::date($approval->created_at),
            ])->all();
    }

    private function auditPayload(int $limit = 12): array
    {
        return AuditLog::with('actor')
            ->latest('id')
            ->limit($limit)
            ->get()
            ->map(fn (AuditLog $log) => [
                'id' => $log->id,
                'actor' => $log->actor?->name,
                'action' => $log->action,
                'entity' => $log->entity,
                'entityId' => $log->entity_id,
                'reason' => $log->reason,
                'createdAt' => Presenter::date($log->created_at),
            ])->all();
    }
}
