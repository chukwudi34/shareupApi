<?php

namespace App\Http\Controllers;

use App\Models\Asset;
use App\Models\OwnershipInterest;
use App\Support\Presenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AssetController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $ownerships = OwnershipInterest::with(['asset.incomes', 'asset.reports'])
            ->where('user_id', $request->user()->id)
            ->get();

        return response()->json([
            'data' => $ownerships->map(fn (OwnershipInterest $ownership) => Presenter::asset($ownership->asset, $ownership)),
            'meta' => ['count' => $ownerships->count()],
        ]);
    }

    public function show(Request $request, Asset $asset): JsonResponse
    {
        $asset->load(['incomes', 'expenses', 'reports', 'valuations']);

        $ownership = OwnershipInterest::where('asset_id', $asset->id)
            ->where('user_id', $request->user()->id)
            ->first();

        if (! $ownership && ! $request->user()->isStaff()) {
            return response()->json(['message' => 'You do not hold an interest in this asset.'], 403);
        }

        return response()->json([
            'data' => [
                'asset' => Presenter::asset($asset, $ownership),
                'distributions' => $asset->distributions()
                    ->with('items')
                    ->latest()
                    ->limit(20)
                    ->get()
                    ->map(fn ($distribution) => Presenter::distribution($distribution)),
            ],
        ]);
    }
}
