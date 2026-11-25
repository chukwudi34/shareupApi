<?php

namespace Tests\Unit;

use App\Exceptions\DomainException;
use App\Support\Entitlements;
use App\Support\Money;
use App\Support\OpportunityLifecycle;
use PHPUnit\Framework\TestCase;

class DomainRulesTest extends TestCase
{
    public function test_money_parses_naira_input_into_kobo(): void
    {
        $this->assertSame(8_000_000, Money::parse('80,000'));
        $this->assertSame(1_250_050, Money::parse('₦12,500.50'));
        $this->assertNull(Money::parse('not money'));
    }

    public function test_platform_fee_uses_basis_points_without_floats(): void
    {
        $this->assertSame(20_160_000, Money::bps(576_000_000, 350));
        $this->assertSame(0, Money::bps(0, 350));
    }

    public function test_pro_rata_allocation_never_loses_a_kobo(): void
    {
        $holders = [
            ['user_id' => 1, 'units' => 1],
            ['user_id' => 2, 'units' => 1],
            ['user_id' => 3, 'units' => 1],
        ];

        $result = Entitlements::proRata(100, $holders);

        $this->assertSame(100, $result['allocated']);
        $this->assertSame(0, $result['unallocated']);
        $this->assertSame([34, 33, 33], array_column($result['items'], 'amount'));
    }

    public function test_largest_remainder_favours_larger_holders(): void
    {
        $result = Entitlements::proRata(1000, [
            ['user_id' => 1, 'units' => 2],
            ['user_id' => 2, 'units' => 1],
        ]);

        $this->assertSame(667, $result['items'][0]['amount']);
        $this->assertSame(333, $result['items'][1]['amount']);
        $this->assertSame(1000, $result['allocated']);
    }

    public function test_physical_allocation_adds_up_to_the_supply(): void
    {
        $result = Entitlements::physical(10, [
            ['user_id' => 1, 'units' => 1],
            ['user_id' => 2, 'units' => 1],
            ['user_id' => 3, 'units' => 1],
        ]);

        $this->assertSame(10, array_sum(array_column($result, 'quantity')));
        $this->assertSame([4, 3, 3], array_column($result, 'quantity'));
    }

    public function test_invalid_state_transition_is_rejected(): void
    {
        $this->assertTrue(OpportunityLifecycle::can('FUNDING', 'FUNDED'));
        $this->assertFalse(OpportunityLifecycle::can('COMPLETED', 'FUNDING'));

        $this->expectException(DomainException::class);
        OpportunityLifecycle::assertTransition('COMPLETED', 'FUNDING');
    }
}
