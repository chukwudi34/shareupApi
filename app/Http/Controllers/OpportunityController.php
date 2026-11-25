<?php

namespace App\Http\Controllers;

use App\Exceptions\DomainException;
use App\Models\Notification;
use App\Models\Opportunity;
use App\Models\Participation;
use App\Support\Money;
use App\Support\OpportunityLifecycle;
use App\Support\Presenter;
use App\Support\References;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class OpportunityController extends Controller
{
    private const PUBLIC_STATUSES = [
        'PUBLISHED', 'FUNDING', 'FUNDED', 'PARTIALLY_FUNDED',
        'ACQUIRING', 'ACQUIRED', 'DISTRIBUTING', 'COMPLETED',
    ];

    public function index(Request $request): JsonResponse
    {
        $query = Opportunity::query()->with('creator')
            ->whereIn('status', self::PUBLIC_STATUSES)
            ->when($request->filled('category') && $request->string('category')->toString() !== 'All',
                fn ($builder) => $builder->where('category', $request->string('category')->toString()))
            ->when($request->filled('model'), fn ($builder) => $builder->where('model', $request->string('model')->toString()))
            ->when($request->filled('q'), function ($builder) use ($request) {
                $term = '%'.Str::lower($request->string('q')->toString()).'%';
                $builder->where(function ($inner) use ($term) {
                    $inner->whereRaw('lower(title) like ?', [$term])
                        ->orWhereRaw('lower(summary) like ?', [$term])
                        ->orWhereRaw('lower(category) like ?', [$term])
                        ->orWhereRaw('lower(location) like ?', [$term]);
                });
            })
            ->when($request->filled('status'), fn ($builder) => $builder->where('status', $request->string('status')->toString()));

        match ($request->string('sort')->toString()) {
            'ending' => $query->orderBy('funding_deadline'),
            'newest' => $query->orderByDesc('created_at'),
            'target' => $query->orderByDesc('target_amount'),
            default => $query->orderByDesc('participant_count')->orderByDesc('committed_amount'),
        };

        $items = $query->limit(60)->get()->map(fn (Opportunity $opportunity) => Presenter::opportunity($opportunity));

        return response()->json([
            'data' => $items,
            'meta' => ['total' => $items->count()],
        ]);
    }

    public function show(Request $request, string $slug): JsonResponse
    {
        $opportunity = Opportunity::with(['creator', 'documents', 'evidence', 'assets', 'reviews.reviewer'])
            ->where('slug', $slug)
            ->firstOrFail();

        $payload = Presenter::opportunity($opportunity, true);

        $viewer = $request->user();
        $payload['viewerParticipation'] = null;

        if ($viewer) {
            $participation = Participation::where('opportunity_id', $opportunity->id)
                ->where('user_id', $viewer->id)
                ->latest()
                ->first();

            $payload['viewerParticipation'] = $participation ? Presenter::participation($participation) : null;
        }

        $payload['related'] = Opportunity::where('category', $opportunity->category)
            ->where('id', '!=', $opportunity->id)
            ->whereIn('status', self::PUBLIC_STATUSES)
            ->limit(3)
            ->get()
            ->map(fn (Opportunity $item) => Presenter::opportunity($item));

        return response()->json(['data' => $payload]);
    }

    public function mine(Request $request): JsonResponse
    {
        $items = Opportunity::with('documents')
            ->where('creator_id', $request->user()->id)
            ->latest()
            ->get()
            ->map(fn (Opportunity $opportunity) => Presenter::opportunity($opportunity, true));

        return response()->json(['data' => $items]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);

        $user = $request->user();
        $title = $data['title'];

        $opportunity = Opportunity::create([
            'slug' => References::slug($title),
            'title' => $title,
            'summary' => $data['summary'] ?? Str::limit($data['description'] ?? 'A collective purchase on ShareUp.', 180),
            'description' => $data['description'] ?? 'Details coming soon.',
            'cover_image' => $data['coverImage'] ?? '/images/cover-produce.webp',
            'category' => $data['category'] ?? 'Agriculture',
            'model' => $data['model'] ?? 'COLLECTIVE_PURCHASE',
            'participation_method' => $data['participationMethod'] ?? 'AMOUNT',
            'status' => 'DRAFT',
            'creator_id' => $user->id,
            'creator_source' => in_array($user->role, ['OPS', 'ADMIN'], true) ? 'PLATFORM' : 'USER',
            'supplier_name' => $data['supplierName'] ?? 'Supplier to be confirmed',
            'supplier_contact' => $data['supplierContact'] ?? null,
            'supplier_verified' => false,
            'target_amount' => $data['targetAmount'],
            'target_quantity' => $data['targetQuantity'] ?? null,
            'quantity_label' => $data['quantityLabel'] ?? null,
            'unit_price' => $data['unitPrice'] ?? null,
            'total_units' => $data['totalUnits'] ?? null,
            'units_per_package' => $data['unitsPerPackage'] ?? null,
            'package_label' => $data['packageLabel'] ?? null,
            'min_contribution' => $data['minContribution'] ?? max(10000, intdiv($data['targetAmount'], 100)),
            'max_contribution' => $data['maxContribution'] ?? null,
            'min_units' => $data['minUnits'] ?? null,
            'max_units' => $data['maxUnits'] ?? null,
            'max_participants' => $data['maxParticipants'] ?? null,
            'funding_deadline' => $data['fundingDeadline'] ?? now()->addDays(30),
            'expected_acquisition_date' => $data['expectedAcquisitionDate'] ?? null,
            'estimated_delivery_date' => $data['estimatedDeliveryDate'] ?? null,
            'location' => $data['location'] ?? 'Lagos, Nigeria',
            'fee_bps' => $data['feeBps'] ?? (int) config('shareup.defaults.fee_bps'),
            'allows_partial_funding' => $data['allowsPartialFunding'] ?? false,
            'allows_overfunding' => $data['allowsOverfunding'] ?? false,
            'allow_cancellation' => $data['allowCancellation'] ?? true,
            'waiting_list_enabled' => $data['waitingListEnabled'] ?? false,
            'legal_structure' => $data['legalStructure'] ?? 'DIRECT_CO_OWNERSHIP',
            'ownership_type' => $data['ownershipType'] ?? 'DIRECT_CO_OWNERSHIP',
            'risk_notes' => $data['riskNotes'] ?? 'Risk notes to be completed before review.',
            'acquisition_conditions' => $data['acquisitionConditions'] ?? 'Acquisition conditions to be completed before review.',
            'funding_policy' => ($data['allowsPartialFunding'] ?? false) ? 'PARTIAL_ALLOWED' : 'ALL_OR_NOTHING',
        ]);

        return response()->json(['data' => Presenter::opportunity($opportunity, true)], 201);
    }

    public function update(Request $request, Opportunity $opportunity): JsonResponse
    {
        $this->assertOwner($request, $opportunity);

        if (! in_array($opportunity->status, ['DRAFT', 'REJECTED'], true)) {
            throw new DomainException('STATE', 'Only drafts can be edited.');
        }

        $data = $this->validated($request, true);
        $map = [
            'title' => 'title', 'summary' => 'summary', 'description' => 'description',
            'coverImage' => 'cover_image', 'category' => 'category', 'model' => 'model',
            'participationMethod' => 'participation_method', 'supplierName' => 'supplier_name',
            'supplierContact' => 'supplier_contact', 'targetAmount' => 'target_amount',
            'targetQuantity' => 'target_quantity', 'quantityLabel' => 'quantity_label',
            'unitPrice' => 'unit_price', 'totalUnits' => 'total_units',
            'unitsPerPackage' => 'units_per_package', 'packageLabel' => 'package_label',
            'minContribution' => 'min_contribution', 'maxContribution' => 'max_contribution',
            'minUnits' => 'min_units', 'maxUnits' => 'max_units',
            'maxParticipants' => 'max_participants', 'fundingDeadline' => 'funding_deadline',
            'expectedAcquisitionDate' => 'expected_acquisition_date',
            'estimatedDeliveryDate' => 'estimated_delivery_date', 'location' => 'location',
            'feeBps' => 'fee_bps', 'allowsPartialFunding' => 'allows_partial_funding',
            'allowsOverfunding' => 'allows_overfunding', 'allowCancellation' => 'allow_cancellation',
            'waitingListEnabled' => 'waiting_list_enabled', 'legalStructure' => 'legal_structure',
            'ownershipType' => 'ownership_type', 'riskNotes' => 'risk_notes',
            'acquisitionConditions' => 'acquisition_conditions',
        ];

        foreach ($map as $camel => $snake) {
            if (array_key_exists($camel, $data)) {
                $opportunity->{$snake} = $data[$camel];
            }
        }

        if ($opportunity->isDirty('title')) {
            $opportunity->slug = References::slug($opportunity->title);
        }

        $opportunity->save();

        return response()->json(['data' => Presenter::opportunity($opportunity->fresh(), true)]);
    }

    public function uploadDocument(Request $request, Opportunity $opportunity): JsonResponse
    {
        $this->assertOwner($request, $opportunity);

        $user = $request->user();
        $isStaff = in_array($user->role, ['OPS', 'ADMIN'], true);

        if (! $isStaff && ! in_array($opportunity->status, ['DRAFT', 'REJECTED'], true)) {
            throw new DomainException('STATE', 'Documents can only be changed while the opportunity is a draft.');
        }

        $data = $request->validate([
            'file' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:10240'],
            'title' => ['nullable', 'string', 'max:160'],
            'kind' => ['nullable', Rule::in([
                'SUPPLIER_QUOTE', 'OWNERSHIP', 'IDENTITY', 'LICENSE', 'OTHER',
            ])],
        ]);

        $file = $data['file'];
        $path = $file->store('opportunity-documents/'.$opportunity->id, 'public');

        $document = $opportunity->documents()->create([
            'title' => $data['title'] ?? $file->getClientOriginalName(),
            'kind' => $data['kind'] ?? 'OTHER',
            'file_url' => Storage::disk('public')->url($path),
            'verified' => false,
            'uploaded_at' => now(),
        ]);

        return response()->json([
            'data' => [
                'id' => $document->id,
                'title' => $document->title,
                'kind' => $document->kind,
                'verified' => false,
                'fileUrl' => $document->file_url,
                'uploadedAt' => $document->uploaded_at?->toIso8601String(),
            ],
        ], 201);
    }

    public function submit(Request $request, Opportunity $opportunity): JsonResponse
    {
        $this->assertOwner($request, $opportunity);

        if (! in_array($opportunity->status, ['DRAFT', 'REJECTED'], true)) {
            throw new DomainException('STATE', 'This opportunity has already been submitted.');
        }

        OpportunityLifecycle::assertPublishable($opportunity);

        $opportunity->update(['status' => 'PENDING_REVIEW']);
        $opportunity->creator; // ensure relation for presenter

        Notification::sendTo(
            $request->user()->id,
            'Submitted for review',
            $opportunity->title.' is queued for compliance review.',
            'INFO',
            '/create',
        );

        return response()->json(['data' => Presenter::opportunity($opportunity->fresh('creator'), true)]);
    }

    public function waitlist(Request $request, Opportunity $opportunity): JsonResponse
    {
        $opportunity->waitlist()->firstOrCreate(
            ['user_id' => $request->user()->id],
            ['notified_at' => null],
        );

        return response()->json(['message' => 'You are on the waiting list.']);
    }

    private function assertOwner(Request $request, Opportunity $opportunity): void
    {
        $user = $request->user();

        if ($opportunity->creator_id !== $user->id && ! in_array($user->role, ['OPS', 'ADMIN'], true)) {
            throw new DomainException('FORBIDDEN', 'You do not own this opportunity.', 403);
        }
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'title' => [$required, 'string', 'max:140'],
            'summary' => ['nullable', 'string', 'max:300'],
            'description' => ['nullable', 'string'],
            'coverImage' => ['nullable', 'string', 'max:255'],
            'category' => ['nullable', 'string', 'max:80'],
            'model' => ['nullable', Rule::in(['COLLECTIVE_PURCHASE', 'MANAGED_ASSET'])],
            'participationMethod' => ['nullable', Rule::in(['AMOUNT', 'UNITS'])],
            'supplierName' => ['nullable', 'string', 'max:160'],
            'supplierContact' => ['nullable', 'string', 'max:160'],
            'targetAmount' => [$required, 'integer', 'min:10000'],
            'targetQuantity' => ['nullable', 'integer', 'min:1'],
            'quantityLabel' => ['nullable', 'string', 'max:40'],
            'unitPrice' => ['nullable', 'integer', 'min:1'],
            'totalUnits' => ['nullable', 'integer', 'min:1'],
            'unitsPerPackage' => ['nullable', 'integer', 'min:1'],
            'packageLabel' => ['nullable', 'string', 'max:40'],
            'minContribution' => ['nullable', 'integer', 'min:1'],
            'maxContribution' => ['nullable', 'integer', 'min:1'],
            'minUnits' => ['nullable', 'integer', 'min:1'],
            'maxUnits' => ['nullable', 'integer', 'min:1'],
            'maxParticipants' => ['nullable', 'integer', 'min:1'],
            'fundingDeadline' => ['nullable', 'date'],
            'expectedAcquisitionDate' => ['nullable', 'date'],
            'estimatedDeliveryDate' => ['nullable', 'date'],
            'location' => ['nullable', 'string', 'max:160'],
            'feeBps' => ['nullable', 'integer', 'min:0', 'max:1000'],
            'allowsPartialFunding' => ['nullable', 'boolean'],
            'allowsOverfunding' => ['nullable', 'boolean'],
            'allowCancellation' => ['nullable', 'boolean'],
            'waitingListEnabled' => ['nullable', 'boolean'],
            'legalStructure' => ['nullable', 'string', 'max:80'],
            'ownershipType' => ['nullable', 'string', 'max:80'],
            'riskNotes' => ['nullable', 'string'],
            'acquisitionConditions' => ['nullable', 'string'],
        ]);
    }
}
