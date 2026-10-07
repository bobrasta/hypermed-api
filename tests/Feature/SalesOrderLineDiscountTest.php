<?php

namespace Tests\Feature;

use App\Models\SalesOrderItem;
use Tests\TestCase;

// Quotation → sales order → invoice keeps each line's discount (no database:
// unsaved models).
class SalesOrderLineDiscountTest extends TestCase
{
    public function test_full_invoice_carries_the_whole_line_discount(): void
    {
        $line = new SalesOrderItem(['quantity_ordered' => 2, 'unit_price' => 5000, 'discount' => 1000, 'total_price' => 9000]);
        $this->assertSame(1000, $line->discountFor(2));
    }

    public function test_partial_invoices_split_it_and_add_up_exactly(): void
    {
        $line = new SalesOrderItem(['quantity_ordered' => 3, 'unit_price' => 10000, 'discount' => 1000]);
        $first = $line->discountFor(1);
        $second = $line->discountFor(1, 1);
        $third = $line->discountFor(1, 2);
        $this->assertSame([333, 334, 333], [$first, $second, $third]);
        $this->assertSame(1000, $first + $second + $third);
    }

    public function test_no_discount_or_nothing_ordered_is_zero(): void
    {
        $this->assertSame(0, (new SalesOrderItem(['quantity_ordered' => 2, 'unit_price' => 5000, 'discount' => 0]))->discountFor(2));
        $this->assertSame(0, (new SalesOrderItem(['quantity_ordered' => 0, 'unit_price' => 5000, 'discount' => 500]))->discountFor(1));
    }
}
