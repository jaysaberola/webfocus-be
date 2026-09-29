<?php

namespace Tests\Unit;

use App\Models\SalesTransaction;
use App\Support\WebDesignQuotation;
use Tests\TestCase;

class WebDesignQuotationDisplayAmountTest extends TestCase
{
    public function test_admin_deal_uses_grand_total_instead_of_item_sum(): void
    {
        $row = $this->makeRow([
            'grand_total' => 2500,
            'discount_total' => 500,
            'tax_total' => 0,
            'payment_status' => 'pending',
            'notes' => '[DEAL_META]{"dealName":"Managed I.T. Services - Web Security","dealDiscounts":{"Managed I.T. Services - Web Security":"500"}}',
        ], [
            ['name' => 'Managed I.T. Services - Web Security', 'item_type' => 'service', 'price' => 3000, 'quantity' => 1, 'total_price' => 3000],
        ]);

        $this->assertTrue(WebDesignQuotation::isAdminPriced($row));
        $this->assertSame(2500.0, WebDesignQuotation::displayAmount($row));
    }

    public function test_admin_deal_includes_tax_in_grand_total(): void
    {
        $row = $this->makeRow([
            'grand_total' => 3360,
            'discount_total' => 0,
            'tax_total' => 360,
            'payment_status' => 'pending',
            'notes' => '[DEAL_META]{"dealName":"Managed I.T. Services - Web Security","dealTaxes":{"Managed I.T. Services - Web Security":"360"}}',
        ], [
            ['name' => 'Managed I.T. Services - Web Security', 'item_type' => 'service', 'price' => 3000, 'quantity' => 1, 'total_price' => 3000],
        ]);

        $this->assertSame(3360.0, WebDesignQuotation::displayAmount($row));
    }

    public function test_customer_cart_order_still_uses_the_higher_item_total(): void
    {
        $row = $this->makeRow([
            'grand_total' => 1728,
            'discount_total' => 0,
            'tax_total' => 0,
            'payment_status' => 'pending',
            'notes' => 'Public cart checkout',
        ], [
            ['name' => 'Top Level Domain', 'item_type' => 'domain', 'price' => 1728, 'quantity' => 1, 'total_price' => 1728],
            ['name' => 'Shared Hosting', 'item_type' => 'hosting', 'price' => 4500, 'quantity' => 1, 'total_price' => 4500],
        ]);

        $this->assertFalse(WebDesignQuotation::isAdminPriced($row));
        $this->assertSame(6228.0, WebDesignQuotation::displayAmount($row));
    }

    /**
     * @param  array<string, mixed>  $attrs
     * @param  array<int, array<string, mixed>>  $items
     */
    private function makeRow(array $attrs, array $items): SalesTransaction
    {
        $row = new SalesTransaction();
        foreach ($attrs as $key => $value) {
            $row->setAttribute($key, $value);
        }
        $row->setRelation('items', collect($items)->map(fn ($item) => (object) $item));

        return $row;
    }
}
