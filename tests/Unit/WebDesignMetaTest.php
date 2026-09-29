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

    public function test_folds_extras_under_the_web_design_package(): void
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
                'price' => 0,
            ],
        ];

        $mapped = WebDesignMeta::foldIntoPackage(
            $items,
            "[WEBDESIGN_META]{\"serviceFeatures\":[\"Dashboard\",\"Pop up Message/Advisory\"]}"
        );

        $this->assertCount(1, $mapped);
        $this->assertSame('Custom Web Design', $mapped[0]['name']);
        $this->assertSame('Business Starter Launch', $mapped[0]['detail']);
        $this->assertArrayNotHasKey('additionalServices', $mapped[0]);
        $this->assertSame(0, $mapped[0]['price']);
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

    public function test_builds_inbox_fields_and_preview_from_notes(): void
    {
        $notes = "Web design quotation request\nNotes:\nhi\nPricing: Pending Quotation\n[WEBDESIGN_META]{\"serviceFeatures\":[\"Dashboard\"]}\n";

        $this->assertSame(
            [
                'Included services' => 'Dashboard',
                'Notes' => 'hi',
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
}
