<?php

namespace Tests\Unit;

use App\Models\SalesTransaction;
use App\Support\DealMeta;
use Tests\TestCase;

class DealMetaLineAdjustmentsTest extends TestCase
{
    public function test_applies_deal_discount_and_tax_to_matching_item(): void
    {
        $row = new SalesTransaction();
        $row->setAttribute('discount_total', 25);
        $row->setAttribute('tax_total', 0);
        $row->setAttribute(
            'notes',
            '[DEAL_META]{"dealDiscounts":{"Managed I.T. Services - Web Security":"25"},"dealTaxes":{}}'
        );

        $priced = DealMeta::applyLineAdjustments([
            [
                'name' => 'Hosting',
                'detail' => 'Managed I.T. Services',
                'price' => 1000,
                'total' => 1000,
            ],
            [
                'name' => 'Hosting',
                'detail' => 'Managed I.T. Services - Web Security',
                'price' => 2000,
                'total' => 2000,
            ],
        ], $row);

        $this->assertSame(0.0, $priced['items'][0]['discount']);
        $this->assertSame(25.0, $priced['items'][1]['discount']);
        $this->assertSame(3000.0, $priced['subtotal']);
        $this->assertSame(25.0, $priced['discountTotal']);
        $this->assertSame(0.0, $priced['taxTotal']);
    }

    public function test_falls_back_to_header_tax_on_the_first_item(): void
    {
        $row = new SalesTransaction();
        $row->setAttribute('discount_total', 0);
        $row->setAttribute('tax_total', 360);
        $row->setAttribute('notes', '[DEAL_META]{"dealName":"Managed I.T. Services"}');

        $priced = DealMeta::applyLineAdjustments([
            [
                'name' => 'Hosting',
                'detail' => 'Managed I.T. Services',
                'price' => 3000,
                'total' => 3000,
            ],
        ], $row);

        $this->assertSame(360.0, $priced['items'][0]['tax']);
        $this->assertSame(360.0, $priced['taxTotal']);
    }

    public function test_applies_deal_amount_to_unpriced_included_service(): void
    {
        $row = new SalesTransaction();
        $row->setAttribute('discount_total', 0);
        $row->setAttribute('tax_total', 0);
        $row->setAttribute(
            'notes',
            '[DEAL_META]{"dealAmounts":{"Dashboard":"1500","Pop up Message/Advisory":"800"}}'
        );

        $priced = DealMeta::applyLineAdjustments([
            [
                'name' => 'Business Starter Launch',
                'detail' => 'Business Starter Launch',
                'price' => 0,
                'total' => 0,
            ],
            [
                'name' => 'Dashboard',
                'detail' => 'Included service',
                'price' => 0,
                'total' => 0,
                'included' => true,
            ],
        ], $row);

        $this->assertSame(0.0, (float) $priced['items'][0]['price']);
        $this->assertSame(1500.0, (float) $priced['items'][1]['price']);
        $this->assertSame(1500.0, $priced['subtotal']);
    }
}
