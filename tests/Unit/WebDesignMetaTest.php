<?php

namespace Tests\Unit;

use App\Support\WebDesignMeta;
use PHPUnit\Framework\TestCase;

class WebDesignMetaTest extends TestCase
{
    public function test_reads_service_features_from_meta_json(): void
    {
        $notes = "[WEBDESIGN_META]{\"packageName\":\"Business Starter Launch\",\"serviceFeatures\":[\"Dashboard\",\"Mailing List\",\"Registration\"]}\n";

        $this->assertSame(
            ['Dashboard', 'Mailing List', 'Registration'],
            WebDesignMeta::additionalServices($notes)
        );
    }

    public function test_reads_additional_services_line_when_json_is_missing(): void
    {
        $notes = "Web design quotation request\nAdditional Services: Dashboard, Mailing List, Registration\n";

        $this->assertSame(
            ['Dashboard', 'Mailing List', 'Registration'],
            WebDesignMeta::additionalServices($notes)
        );
    }

    public function test_expands_extras_as_priced_rows_under_the_web_design_package(): void
    {
        $items = [
            [
                'id' => 1,
                'name' => 'Custom Web Design',
                'detail' => 'Business Starter Launch',
                'itemType' => 'web_design',
                'price' => 0,
            ],
            [
                'id' => 2,
                'name' => 'Additional Service',
                'detail' => 'Dashboard',
                'itemType' => 'web_design_addon',
                'price' => 1500,
                'total' => 1500,
                'unitPrice' => 1500,
                'quantity' => 1,
            ],
        ];

        $mapped = WebDesignMeta::foldIntoPackage(
            $items,
            "[WEBDESIGN_META]{\"serviceFeatures\":[\"Dashboard\",\"Pop up Message/Advisory\"]}"
        );

        $this->assertCount(3, $mapped);
        $this->assertSame('Custom Web Design', $mapped[0]['name']);
        $this->assertSame('Business Starter Launch', $mapped[0]['detail']);
        $this->assertArrayNotHasKey('additionalServices', $mapped[0]);
        $this->assertSame('Dashboard', $mapped[1]['name']);
        $this->assertTrue($mapped[1]['included']);
        $this->assertSame(1500.0, (float) $mapped[1]['price']);
        $this->assertSame('Pop up Message/Advisory', $mapped[2]['name']);
        $this->assertTrue($mapped[2]['included']);
        $this->assertSame(0.0, (float) $mapped[2]['price']);
    }

    public function test_drops_duplicate_hosting_rows_that_repeat_included_services(): void
    {
        $items = [
            [
                'id' => 1,
                'name' => 'Custom Web Design',
                'detail' => 'Business Starter Launch',
                'itemType' => 'web_design',
                'price' => 1000,
                'total' => 1000,
            ],
            [
                'id' => 2,
                'name' => 'Hosting',
                'detail' => 'Dashboard',
                'itemType' => 'service',
                'price' => 12,
                'total' => 12,
            ],
            [
                'id' => 3,
                'name' => 'Hosting',
                'detail' => 'Pop up Message/Advisory',
                'itemType' => 'service',
                'price' => 22,
                'total' => 22,
            ],
        ];

        $mapped = WebDesignMeta::foldIntoPackage(
            $items,
            '[WEBDESIGN_META]{"serviceFeatures":["Dashboard","Pop up Message/Advisory"]}'
        );

        $this->assertCount(3, $mapped);
        $this->assertSame('Custom Web Design', $mapped[0]['name']);
        $this->assertSame('Dashboard', $mapped[1]['name']);
        $this->assertTrue($mapped[1]['included']);
        $this->assertSame(12.0, (float) $mapped[1]['price']);
        $this->assertSame('Pop up Message/Advisory', $mapped[2]['name']);
        $this->assertTrue($mapped[2]['included']);
        $this->assertSame(22.0, (float) $mapped[2]['price']);
    }

    public function test_attaches_client_notes_from_the_notes_block(): void
    {
        $items = [
            [
                'id' => 1,
                'name' => 'Custom Web Design',
                'detail' => 'Business Starter Launch',
                'itemType' => 'web_design',
                'price' => 0,
            ],
        ];

        $notes = "Web design quotation request\nNotes:\nhi\nPricing: Pending Quotation\nNotify: Customer Care\n";
        $mapped = WebDesignMeta::foldIntoPackage($items, $notes);

        $this->assertSame('hi', $mapped[0]['clientNotes']);
    }

    public function test_prefers_client_notes_from_webdesign_meta(): void
    {
        $this->assertSame(
            'Need a members login',
            WebDesignMeta::clientNotes('[WEBDESIGN_META]{"clientNotes":"Need a members login","serviceFeatures":[]}')
        );
    }

    public function test_reads_sales_notes_and_inbox_sales_reply(): void
    {
        $notes = '[WEBDESIGN_META]{"clientNotes":"hi","salesNotes":"We can include that","serviceFeatures":[]}';

        $this->assertSame('We can include that', WebDesignMeta::salesNotes($notes));
        $this->assertSame(
            [
                'Included services' => '',
                'Notes' => 'hi',
                'Sales reply' => 'We can include that',
            ],
            WebDesignMeta::inboxFields($notes)
        );
        $this->assertSame(
            ' Notes: hi. Sales reply: We can include that.',
            WebDesignMeta::inboxDetailSuffix($notes)
        );
    }

    public function test_builds_inbox_fields_and_preview_from_notes(): void
    {
        $notes = "Web design quotation request\nNotes:\nhi\nPricing: Pending Quotation\n[WEBDESIGN_META]{\"serviceFeatures\":[\"Dashboard\"]}\n";

        $this->assertSame(
            [
                'Included services' => 'Dashboard',
                'Notes' => 'hi',
                'Sales reply' => '',
            ],
            WebDesignMeta::inboxFields($notes)
        );
        $this->assertSame(' Notes: hi', WebDesignMeta::inboxPreviewSuffix($notes));
        $this->assertSame(
            ' Included services: Dashboard. Notes: hi.',
            WebDesignMeta::inboxDetailSuffix($notes)
        );
        $this->assertSame(
            'Business Starter Launch',
            WebDesignMeta::packageItemLine([
                ['name' => 'Business Starter Launch', 'itemType' => 'web_design'],
                ['name' => 'Dashboard', 'itemType' => 'web_design_addon'],
                ['name' => 'Pop up Message/Advisory', 'itemType' => 'web_design_addon'],
            ], $notes)
        );
    }

    public function test_amount_detail_rows_include_priced_included_services(): void
    {
        $notes = "[WEBDESIGN_META]{\"packageName\":\"Business Starter Launch\",\"serviceFeatures\":[\"Dashboard\",\"Pop up Message/Advisory\"]}\n"
            ."[DEAL_META]{\"dealAmounts\":{\"Business Starter Launch\":12000,\"Dashboard\":1500,\"Pop up Message/Advisory\":800}}\n";

        $rows = WebDesignMeta::amountDetailRows([
            [
                'name' => 'Business Starter Launch',
                'itemType' => 'web_design',
                'price' => 12000,
                'total' => 12000,
            ],
        ], $notes);

        $this->assertSame('Items', $rows[0]['label']);
        $this->assertSame('Business Starter Launch — ₱12,000.00', $rows[0]['value']);
        $this->assertSame('Included service', $rows[1]['label']);
        $this->assertSame('Dashboard — ₱1,500.00', $rows[1]['value']);
        $this->assertSame('Included service', $rows[2]['label']);
        $this->assertSame('Pop up Message/Advisory — ₱800.00', $rows[2]['value']);
    }
}
