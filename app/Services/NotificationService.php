<?php

namespace App\Services;

use App\Models\Notification;
use App\Models\Opportunity;
use App\Models\Participation;
use App\Models\User;

final class NotificationService
{
    public function staff(string $title, string $body, string $kind = 'INFO', ?string $href = null): void
    {
        User::whereIn('role', ['OPS', 'FINANCE', 'ADMIN'])
            ->pluck('id')
            ->each(fn (int $id) => Notification::sendTo($id, $title, $body, $kind, $href));
    }

    public function participants(Opportunity $opportunity, string $title, string $body, string $kind = 'INFO', ?string $href = null): void
    {
        Participation::where('opportunity_id', $opportunity->id)
            ->whereIn('status', ['CONFIRMED', 'REFUND_PENDING'])
            ->distinct()
            ->pluck('user_id')
            ->each(fn (int $id) => Notification::sendTo($id, $title, $body, $kind, $href));
    }
}
