<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Chart of accounts and balanced journal builders.
 *
 * Money held for participants is never recognised as platform revenue.
 * Every journal must balance: sum(debits) === sum(credits).
 */
final class Ledger
{
    public const ACCOUNTS = [
        '1000' => ['Safeguarded participant funds', 'ASSET'],
        '1100' => ['Operating cash', 'ASSET'],
        '1200' => ['Unconfirmed provider receipts', 'ASSET'],
        '2000' => ['Participant funds payable', 'LIABILITY'],
        '2100' => ['Acquisition payable', 'LIABILITY'],
        '2200' => ['Refund payable', 'LIABILITY'],
        '2300' => ['Distribution payable', 'LIABILITY'],
        '2400' => ['Wallet payable', 'LIABILITY'],
        '2500' => ['Unallocated asset proceeds', 'LIABILITY'],
        '4000' => ['Platform revenue', 'REVENUE'],
        '4900' => ['Rounding adjustments', 'REVENUE'],
        '5000' => ['Payment processing cost', 'EXPENSE'],
    ];

    public static function journal(string $kind, string $description, array $lines): array
    {
        $normalised = [];
        $debits = 0;
        $credits = 0;

        foreach ($lines as $line) {
            [$account, $direction, $amount] = $line;
            if ($amount <= 0) {
                continue;
            }
            if (! isset(self::ACCOUNTS[$account])) {
                throw new InvalidArgumentException("Unknown ledger account {$account}.");
            }
            if (! in_array($direction, ['DEBIT', 'CREDIT'], true)) {
                throw new InvalidArgumentException('Ledger direction must be DEBIT or CREDIT.');
            }

            $normalised[] = ['account' => $account, 'direction' => $direction, 'amount' => $amount];
            $direction === 'DEBIT' ? $debits += $amount : $credits += $amount;
        }

        if ($debits !== $credits) {
            throw new InvalidArgumentException("Unbalanced journal {$kind}: debits {$debits}, credits {$credits}.");
        }

        return ['kind' => $kind, 'description' => $description, 'lines' => $normalised];
    }

    public static function contributionReceived(int $amount): array
    {
        return self::journal('CONTRIBUTION_RECEIVED', 'Participant contribution received', [
            ['1000', 'DEBIT', $amount],
            ['2000', 'CREDIT', $amount],
        ]);
    }

    public static function platformFee(int $fee): array
    {
        return self::journal('PLATFORM_FEE', 'Platform fee recognised on funded opportunity', [
            ['2000', 'DEBIT', $fee],
            ['4000', 'CREDIT', $fee],
        ]);
    }

    public static function acquisitionCommitted(int $amount): array
    {
        return self::journal('ACQUISITION_COMMITTED', 'Funds committed to supplier acquisition', [
            ['2000', 'DEBIT', $amount],
            ['2100', 'CREDIT', $amount],
        ]);
    }

    public static function supplierPayout(int $amount): array
    {
        return self::journal('SUPPLIER_PAYOUT', 'Supplier paid from safeguarded funds', [
            ['2100', 'DEBIT', $amount],
            ['1000', 'CREDIT', $amount],
        ]);
    }

    public static function refundApproved(int $amount): array
    {
        return self::journal('REFUND_APPROVED', 'Refund approved and payable', [
            ['2000', 'DEBIT', $amount],
            ['2200', 'CREDIT', $amount],
        ]);
    }

    public static function refundPaid(int $amount): array
    {
        return self::journal('REFUND_PAID', 'Refund paid to participant', [
            ['2200', 'DEBIT', $amount],
            ['1000', 'CREDIT', $amount],
        ]);
    }

    public static function unallocatedProceeds(int $amount): array
    {
        return self::journal('UNALLOCATED_PROCEEDS', 'Asset proceeds held before distribution', [
            ['1000', 'DEBIT', $amount],
            ['2500', 'CREDIT', $amount],
        ]);
    }

    public static function distributionDeclared(int $net): array
    {
        return self::journal('DISTRIBUTION_DECLARED', 'Distribution declared to participants', [
            ['2500', 'DEBIT', $net],
            ['2300', 'CREDIT', $net],
        ]);
    }

    public static function distributionPaid(int $amount, bool $toWallet = false): array
    {
        return self::journal('DISTRIBUTION_PAID', 'Distribution paid', [
            ['2300', 'DEBIT', $amount],
            [$toWallet ? '2400' : '1000', 'CREDIT', $amount],
        ]);
    }

    public static function walletDeposit(int $amount): array
    {
        return self::journal('WALLET_DEPOSIT', 'Wallet funded', [
            ['1000', 'DEBIT', $amount],
            ['2400', 'CREDIT', $amount],
        ]);
    }

    public static function walletWithdrawal(int $amount): array
    {
        return self::journal('WALLET_WITHDRAWAL', 'Wallet withdrawn to bank', [
            ['2400', 'DEBIT', $amount],
            ['1000', 'CREDIT', $amount],
        ]);
    }

    public static function adjustment(int $amount, string $direction = 'CREDIT'): array
    {
        return self::journal('ADJUSTMENT', 'Manual ledger adjustment', [
            [$direction === 'CREDIT' ? '1100' : '4900', $direction, $amount],
            [$direction === 'CREDIT' ? '4900' : '1100', $direction === 'CREDIT' ? 'DEBIT' : 'CREDIT', $amount],
        ]);
    }
}
