<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'before' => 'array',
            'after' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public static function record(
        ?User $actor,
        string $action,
        string $entity,
        int|string $entityId,
        ?string $reason = null,
        ?array $before = null,
        ?array $after = null,
    ): self {
        return static::create([
            'actor_id' => $actor?->id,
            'action' => $action,
            'entity' => $entity,
            'entity_id' => (string) $entityId,
            'reason' => $reason,
            'before' => $before,
            'after' => $after,
            'ip' => request()?->ip(),
            'created_at' => now(),
        ]);
    }
}
