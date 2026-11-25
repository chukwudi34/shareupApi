<?php

namespace App\Support;

use App\Exceptions\DomainException;
use App\Models\User;

final class Permissions
{
    public const ROLES = ['PARTICIPANT', 'CREATOR', 'OPS', 'FINANCE', 'ADMIN'];

    public const PARTICIPANT = [
        'opportunity.view', 'participation.create', 'participation.cancel',
        'wallet.view', 'refund.request', 'asset.view', 'notification.view',
    ];

    public const CREATOR = [
        'opportunity.create', 'opportunity.edit', 'opportunity.submit',
        'opportunity.view', 'participation.create', 'participation.cancel',
        'wallet.view', 'refund.request', 'asset.view', 'evidence.upload', 'notification.view',
    ];

    public const OPS = [
        'opportunity.view', 'opportunity.review', 'opportunity.publish', 'opportunity.edit',
        'refund.review', 'refund.request', 'asset.view', 'asset.manage',
        'distribution.manage', 'evidence.verify', 'supplier.request', 'payout.request',
        'support.manage',
        'ledger.view', 'audit.view', 'notification.view', 'wallet.view',
    ];

    public const FINANCE = [
        'opportunity.view', 'refund.review', 'refund.approve', 'refund.payout',
        'supplier.approve', 'payout.pay', 'distribution.approve', 'asset.view',
        'ledger.view', 'audit.view', 'notification.view', 'wallet.view',
    ];

    public const MATRIX = [
        'PARTICIPANT' => self::PARTICIPANT,
        'CREATOR' => self::CREATOR,
        'OPS' => self::OPS,
        'FINANCE' => self::FINANCE,
    ];

    public static function forRole(string $role): array
    {
        if ($role === 'ADMIN') {
            return array_values(array_unique(array_merge(
                self::PARTICIPANT, self::CREATOR, self::OPS, self::FINANCE,
                ['user.manage', 'config.manage', 'approval.decide'],
            )));
        }

        return self::MATRIX[$role] ?? [];
    }

    public static function has(User $user, string $permission): bool
    {
        if ($user->status !== null && $user->status !== 'ACTIVE') {
            return false;
        }

        return in_array($permission, self::forRole($user->role), true);
    }

    public static function assert(User $user, string $permission): void
    {
        if (! self::has($user, $permission)) {
            throw new DomainException('FORBIDDEN', 'Your role does not allow this action.', 403);
        }
    }

    /** Maker-checker: the person who requested an action cannot approve it. */
    public static function assertMakerChecker(User $approver, ?int $requesterId): void
    {
        if ($requesterId !== null && $approver->id === $requesterId) {
            throw new DomainException('MAKER_CHECKER', 'A different authorised user must approve this request.', 403);
        }
    }
}
