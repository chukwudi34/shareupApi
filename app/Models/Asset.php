<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Asset extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'acquisition_price' => 'integer',
            'current_valuation' => 'integer',
            'total_units' => 'integer',
            'valuation_at' => 'datetime',
            'acquisition_date' => 'datetime',
        ];
    }

    public function opportunity(): BelongsTo
    {
        return $this->belongsTo(Opportunity::class);
    }

    public function ownerships(): HasMany
    {
        return $this->hasMany(OwnershipInterest::class);
    }

    public function incomes(): HasMany
    {
        return $this->hasMany(AssetIncome::class);
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(AssetExpense::class);
    }

    public function valuations(): HasMany
    {
        return $this->hasMany(AssetValuation::class);
    }

    public function reports(): HasMany
    {
        return $this->hasMany(AssetReport::class);
    }

    public function distributions(): HasMany
    {
        return $this->hasMany(Distribution::class);
    }

    public function inventory(): HasMany
    {
        return $this->hasMany(InventoryItem::class);
    }
}
