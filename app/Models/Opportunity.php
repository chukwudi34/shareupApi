<?php

namespace App\Models;

use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Opportunity extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'target_amount' => 'integer',
            'committed_amount' => 'integer',
            'target_quantity' => 'integer',
            'unit_price' => 'integer',
            'total_units' => 'integer',
            'units_committed' => 'integer',
            'units_per_package' => 'integer',
            'min_contribution' => 'integer',
            'max_contribution' => 'integer',
            'min_units' => 'integer',
            'max_units' => 'integer',
            'max_participants' => 'integer',
            'participant_count' => 'integer',
            'fee_bps' => 'integer',
            'policy_version' => 'integer',
            'reservation_minutes' => 'integer',
            'supplier_verified' => 'boolean',
            'allows_partial_funding' => 'boolean',
            'allows_overfunding' => 'boolean',
            'allow_cancellation' => 'boolean',
            'waiting_list_enabled' => 'boolean',
            'allows_increases' => 'boolean',
            'funding_deadline' => 'datetime',
            'expected_acquisition_date' => 'datetime',
            'estimated_delivery_date' => 'datetime',
            'verified_at' => 'datetime',
            'published_at' => 'datetime',
            'funded_at' => 'datetime',
            'acquired_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

    public function participations(): HasMany
    {
        return $this->hasMany(Participation::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public function distributions(): HasMany
    {
        return $this->hasMany(Distribution::class);
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(OpportunityDocument::class);
    }

    public function evidence(): HasMany
    {
        return $this->hasMany(AcquisitionEvidence::class);
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(OpportunityReview::class);
    }

    public function payouts(): HasMany
    {
        return $this->hasMany(SupplierPayout::class);
    }

    public function assets(): HasMany
    {
        return $this->hasMany(Asset::class);
    }

    public function waitlist(): HasMany
    {
        return $this->hasMany(WaitlistEntry::class);
    }

    public function percentFunded(): int
    {
        return Money::percent((int) $this->committed_amount, (int) $this->target_amount);
    }

    public function remainingAmount(): int
    {
        return max(0, (int) $this->target_amount - (int) $this->committed_amount);
    }

    public function percentOfUnits(): int
    {
        return $this->total_units
            ? Money::percent((int) $this->units_committed, (int) $this->total_units)
            : 0;
    }

    public function isOpen(): bool
    {
        return $this->status === 'FUNDING' && $this->funding_deadline->isFuture();
    }
}
