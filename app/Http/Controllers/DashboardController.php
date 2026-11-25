<?php

namespace App\Http\Controllers;

use App\Models\Notification;
use App\Models\OwnershipInterest;
use App\Models\Participation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class DashboardController extends Controller
{
    public function summary(Request $request): JsonResponse
    {
        $user = $request->user();

        $participations = Participation::with('opportunity')
            ->where('user_id', $user->id)
            ->get();

        $confirmed = $participations->where('status', 'CONFIRMED');
        $totalInvested = (int) $confirmed->sum('amount');
        $assetCount = $confirmed->pluck('opportunity_id')->unique()->count();

        $ownerships = OwnershipInterest::with('asset')->where('user_id', $user->id)->get();
        $portfolioValue = (int) $ownerships->sum(function (OwnershipInterest $ownership) {
            $valuation = (int) ($ownership->asset->current_valuation ?? $ownership->asset->acquisition_price);
            $totalUnits = (int) $ownership->asset->total_units;

            return $totalUnits > 0 ? (int) round($valuation * ($ownership->units / $totalUnits)) : 0;
        });

        $months = collect(range(5, 0))->map(fn (int $offset) => Carbon::now()->startOfMonth()->subMonths($offset));
        $trend = $months->map(function (Carbon $month) use ($confirmed) {
            return (int) $confirmed->filter(fn (Participation $participation) => $participation->confirmed_at
                && $participation->confirmed_at->isSameMonth($month))->sum('amount');
        })->values()->all();

        $growthPercent = 0;
        $nonZero = array_values(array_filter($trend));
        if (count($nonZero) >= 2 && $nonZero[0] > 0) {
            $growthPercent = (int) round((($nonZero[count($nonZero) - 1] - $nonZero[0]) / $nonZero[0]) * 100);
        }

        return response()->json([
            'data' => [
                'totalInvested' => $totalInvested,
                'assetCount' => $assetCount,
                'portfolioValue' => $portfolioValue,
                'walletBalance' => (int) $user->wallet_balance,
                'unreadNotifications' => Notification::where('user_id', $user->id)->whereNull('read_at')->count(),
                'activeCount' => $confirmed->filter(fn (Participation $participation) => in_array($participation->opportunity?->status, ['FUNDING', 'FUNDED', 'ACQUIRED'], true))->count(),
                'growthPercent' => $growthPercent,
                'trend' => $trend,
            ],
        ]);
    }
}
