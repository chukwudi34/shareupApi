<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Notification extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['read_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function sendTo(int $userId, string $title, string $body, string $kind = 'INFO', ?string $href = null): self
    {
        return static::create([
            'user_id' => $userId,
            'title' => $title,
            'body' => $body,
            'kind' => $kind,
            'href' => $href,
        ]);
    }
}
