<?php

namespace App\Services;

use App\Models\LedgerAccount;
use App\Models\LedgerEntry;
use App\Models\LedgerLine;
use App\Models\Opportunity;
use App\Models\User;
use App\Support\Ledger;
use App\Support\References;
use Illuminate\Support\Facades\DB;

final class LedgerService
{
    public function seedChart(): void
    {
        foreach (Ledger::ACCOUNTS as $code => [$name, $type]) {
            LedgerAccount::firstOrCreate(
                ['code' => $code],
                ['name' => $name, 'type' => $type, 'is_system' => true],
            );
        }
    }

    public function post(array $journal, ?Opportunity $opportunity = null, ?User $actor = null): LedgerEntry
    {
        return DB::transaction(function () use ($journal, $opportunity, $actor) {
            $entry = LedgerEntry::create([
                'reference' => References::make('LED'),
                'kind' => $journal['kind'],
                'description' => $journal['description'],
                'opportunity_id' => $opportunity?->id,
                'posted_by_id' => $actor?->id,
                'posted_at' => now(),
            ]);

            foreach ($journal['lines'] as $line) {
                $account = LedgerAccount::firstOrCreate(
                    ['code' => $line['account']],
                    [
                        'name' => Ledger::ACCOUNTS[$line['account']][0],
                        'type' => Ledger::ACCOUNTS[$line['account']][1],
                        'is_system' => true,
                    ],
                );

                LedgerLine::create([
                    'ledger_entry_id' => $entry->id,
                    'ledger_account_id' => $account->id,
                    'direction' => $line['direction'],
                    'amount' => $line['amount'],
                ]);
            }

            return $entry;
        });
    }

    /** Signed balance for an account, based on its normal side. */
    public function balance(string $code): int
    {
        $account = LedgerAccount::where('code', $code)->first();

        if (! $account) {
            return 0;
        }

        $debits = (int) $account->lines()->where('direction', 'DEBIT')->sum('amount');
        $credits = (int) $account->lines()->where('direction', 'CREDIT')->sum('amount');

        return in_array($account->type, ['ASSET', 'EXPENSE'], true)
            ? $debits - $credits
            : $credits - $debits;
    }

    /** @return array<int, array{code:string, name:string, type:string, debit:int, credit:int, balance:int}> */
    public function trialBalance(): array
    {
        $this->seedChart();

        return LedgerAccount::orderBy('code')->get()->map(function (LedgerAccount $account) {
            $debits = (int) $account->lines()->where('direction', 'DEBIT')->sum('amount');
            $credits = (int) $account->lines()->where('direction', 'CREDIT')->sum('amount');

            return [
                'code' => $account->code,
                'name' => $account->name,
                'type' => $account->type,
                'debit' => $debits,
                'credit' => $credits,
                'balance' => in_array($account->type, ['ASSET', 'EXPENSE'], true)
                    ? $debits - $credits
                    : $credits - $debits,
            ];
        })->all();
    }
}
