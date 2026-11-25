<?php

namespace App\Support;

/**
 * Deterministic pro-rata allocation using the largest-remainder method so
 * the sum of allocations always equals the amount being distributed.
 */
final class Entitlements
{
    /**
     * @param  array<int, array{user_id:int, units:int}>  $holders
     * @return array{items: array<int, array{user_id:int, units:int, amount:int}>, allocated:int, unallocated:int}
     */
    public static function proRata(int $netAmount, array $holders): array
    {
        $totalUnits = array_sum(array_column($holders, 'units'));

        if ($netAmount < 0) {
            throw new \InvalidArgumentException('Distribution amount cannot be negative.');
        }

        if ($totalUnits <= 0 || $netAmount === 0) {
            return [
                'items' => array_map(fn (array $holder) => [
                    'user_id' => $holder['user_id'],
                    'units' => $holder['units'],
                    'amount' => 0,
                ], $holders),
                'allocated' => 0,
                'unallocated' => $netAmount,
            ];
        }

        $allocated = 0;
        $rows = [];

        foreach ($holders as $holder) {
            $exact = $netAmount * $holder['units'];
            $base = intdiv($exact, $totalUnits);
            $allocated += $base;
            $rows[] = [
                'user_id' => $holder['user_id'],
                'units' => $holder['units'],
                'amount' => $base,
                'remainder' => $exact % $totalUnits,
                'index' => count($rows),
            ];
        }

        $leftover = $netAmount - $allocated;

        usort($rows, function (array $a, array $b) {
            return [$b['remainder'], $a['index']] <=> [$a['remainder'], $b['index']];
        });

        for ($i = 0; $i < $leftover; $i++) {
            $rows[$i % count($rows)]['amount']++;
        }

        usort($rows, fn (array $a, array $b) => $a['index'] <=> $b['index']);

        $items = array_map(fn (array $row) => [
            'user_id' => $row['user_id'],
            'units' => $row['units'],
            'amount' => $row['amount'],
        ], $rows);

        $sum = array_sum(array_column($items, 'amount'));

        return [
            'items' => $items,
            'allocated' => $sum,
            'unallocated' => $netAmount - $sum,
        ];
    }

    /**
     * Split physical quantities (bags, acres, bags of rice) across holders.
     *
     * @param  array<int, array{user_id:int, units:int}>  $holders
     * @return array<int, array{user_id:int, units:int, quantity:int}>
     */
    public static function physical(int $totalQuantity, array $holders): array
    {
        $totalUnits = array_sum(array_column($holders, 'units'));

        if ($totalUnits <= 0) {
            return array_map(fn (array $holder) => [
                'user_id' => $holder['user_id'],
                'units' => $holder['units'],
                'quantity' => 0,
            ], $holders);
        }

        $rows = [];
        $assigned = 0;

        foreach ($holders as $index => $holder) {
            $base = intdiv($totalQuantity * $holder['units'], $totalUnits);
            $assigned += $base;
            $rows[] = [
                'user_id' => $holder['user_id'],
                'units' => $holder['units'],
                'quantity' => $base,
                'remainder' => ($totalQuantity * $holder['units']) % $totalUnits,
                'index' => $index,
            ];
        }

        usort($rows, fn (array $a, array $b) => [$b['remainder'], $a['index']] <=> [$a['remainder'], $b['index']]);

        for ($leftover = $totalQuantity - $assigned, $i = 0; $i < $leftover; $i++) {
            $rows[$i % count($rows)]['quantity']++;
        }

        usort($rows, fn (array $a, array $b) => $a['index'] <=> $b['index']);

        return array_map(fn (array $row) => [
            'user_id' => $row['user_id'],
            'units' => $row['units'],
            'quantity' => $row['quantity'],
        ], $rows);
    }
}
