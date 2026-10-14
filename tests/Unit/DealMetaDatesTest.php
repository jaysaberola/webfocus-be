<?php

namespace Tests\Unit;

use App\Support\DealMeta;
use PHPUnit\Framework\TestCase;

class DealMetaDatesTest extends TestCase
{
    public function test_backend_sets_nine_year_expiration_and_ten_year_domain_subscription(): void
    {
        $notes = '[DEAL_META]'.json_encode([
            'productCategory' => 'Domain Registration',
            'dealName' => 'Top Level Domain',
            'domainRegistrationStartDate' => '2026-01-15',
            'expirationStartDate' => '2026-02-01',
            'domainSubscriptionStartDate' => '2026-03-01',
            'subscriptionStartDate' => '2026-03-01',
            'domainRegistrationExpirationDate' => '2027-01-15',
            'domainSubscriptionEndDate' => '2027-03-01',
            'statusTriggerDate' => '2026-01-01',
        ]);

        $next = DealMeta::applyManagedDates($notes, ['Add On - Wildcard SSL']);
        $meta = DealMeta::parse($next);

        $this->assertSame('2035-01-15', $meta['domainRegistrationExpirationDate']);
        $this->assertSame('2035-02-01', $meta['sslWildcardExpirationDate']);
        $this->assertSame('2036-03-01', $meta['domainSubscriptionEndDate']);
        $this->assertSame('2036-03-01', $meta['subscriptionEndDate']);
        $this->assertArrayNotHasKey('statusTriggerDate', $meta);

        $this->assertSame('2035-01-15', DealMeta::financeExpirationDate($next)?->format('Y-m-d'));
        $this->assertSame('2036-03-01', DealMeta::clientSubscriptionEnd($next)?->format('Y-m-d'));
    }

    public function test_hosting_subscription_end_follows_payment_terms(): void
    {
        $notes = '[DEAL_META]'.json_encode([
            'productCategory' => 'Web Hosting - Shared',
            'dealName' => 'STARTER SHARED',
            'subscriptionStartDate' => '2026-04-01',
            'paymentTerms' => 'Annual',
        ]);

        $next = DealMeta::applyManagedDates($notes);
        $meta = DealMeta::parse($next);

        $this->assertSame('2027-04-01', $meta['subscriptionEndDate']);
        $this->assertSame('2027-04-01', DealMeta::clientSubscriptionEnd($next)?->format('Y-m-d'));
    }

    public function test_append_client_note_keeps_web_design_conversation(): void
    {
        $notes = '[WEBDESIGN_META]{"clientNotes":"Need a members login","serviceFeatures":["Dashboard"]}';
        $next = DealMeta::appendClientNote($notes, 'Can we do 12,000?');

        $this->assertSame("Need a members login\n\nCan we do 12,000?", DealMeta::clientNotes($next));
        $this->assertSame("Need a members login\n\nCan we do 12,000?", \App\Support\WebDesignMeta::clientNotes($next));
        $this->assertStringContainsString('[DEAL_META]', $next);
        $this->assertStringContainsString('[WEBDESIGN_META]', $next);
        $thread = DealMeta::conversation($next);
        $this->assertSame('client', $thread[0]['from']);
        $this->assertSame('Need a members login', $thread[0]['text']);
        $this->assertSame('Can we do 12,000?', $thread[array_key_last($thread)]['text']);
    }

    public function test_append_message_keeps_proposal_file_and_confirmation(): void
    {
        $notes = '[DEAL_META]{"clientNotes":"Need a quote"}';
        $next = DealMeta::appendSalesNote($notes, '', [
            'name' => 'proposal.pdf',
            'url' => '/storage/deal-chat/1/proposal.pdf',
            'kind' => 'proposal',
        ]);

        $thread = DealMeta::conversation($next);
        $last = $thread[array_key_last($thread)];
        $this->assertSame('sales', $last['from']);
        $this->assertSame('Uploaded a proposal quotation: proposal.pdf', $last['text']);
        $this->assertSame('proposal.pdf', $last['file']['name']);
        $this->assertSame('/storage/deal-chat/1/proposal.pdf', $last['file']['url']);
        $this->assertSame('proposal', $last['file']['kind']);
    }

    public function test_pin_quoted_price_is_visible_in_conversation(): void
    {
        $notes = '[DEAL_META]{"clientNotes":"Need a quote","dealName":"Business Starter Launch"}';
        $next = DealMeta::pinQuotedPrice($notes, 19756, [
            'orderNo' => 'ST-20260113-0001',
            'items' => [
                ['name' => 'Business Starter Launch', 'price' => 17756, 'total' => 17756],
                ['name' => 'Members Portal', 'price' => 2000, 'total' => 2000],
            ],
            'subtotal' => 19756,
            'discount' => 0,
            'tax' => 0,
        ]);

        $this->assertSame(19756.0, DealMeta::quotedPrice($next));
        $thread = DealMeta::conversation($next);
        $last = $thread[array_key_last($thread)];
        $this->assertSame('sales', $last['from']);
        $this->assertStringContainsString('A quoted price is ready for ST-20260113-0001.', $last['text']);
        $this->assertStringContainsString('Business Starter Launch — ₱17,756.00', $last['text']);
        $this->assertStringContainsString('Members Portal — ₱2,000.00', $last['text']);
        $this->assertStringContainsString('Grand total: ₱19,756.00', $last['text']);
        $this->assertStringContainsString('Pending Quotation', $last['text']);
        $this->assertSame($next, DealMeta::pinQuotedPrice($next, 19756));
    }

    public function test_a_changed_quote_is_sent_as_a_new_message_after_the_customer_reply(): void
    {
        $notes = '[DEAL_META]{"dealName":"Business Starter Launch | Mailing List","productCategory":"Web Development","dealAmounts":{"Business Starter Launch":1000,"Mailing List":1111}}';
        $first = DealMeta::pinQuotedPrice($notes, 1800, [
            'orderNo' => 'ST-20261014-0005',
            'items' => [
                ['name' => 'Business Starter Launch', 'price' => 900, 'total' => 900],
                ['name' => 'Mailing List', 'price' => 900, 'total' => 900],
            ],
            'subtotal' => 1800,
        ]);
        $withReply = DealMeta::appendMessage($first, 'client', 'ayoko niyan');
        $second = DealMeta::pinQuotedPrice($withReply, 2111, [
            'orderNo' => 'ST-20261014-0005',
            'items' => [
                ['name' => 'Business Starter Launch', 'price' => 1000, 'total_price' => 1000],
                ['name' => 'Mailing List', 'price' => 1111, 'total_price' => 1111],
            ],
            'subtotal' => 2111,
            'refreshLines' => true,
        ]);

        $thread = DealMeta::conversation($second);
        $this->assertCount(3, $thread);
        $this->assertSame('client', $thread[1]['from']);
        $this->assertSame('ayoko niyan', $thread[1]['text']);
        $this->assertSame('sales', $thread[2]['from']);
        $this->assertStringContainsString('A quoted price is ready for ST-20261014-0005.', $thread[2]['text']);
        $this->assertStringContainsString('Business Starter Launch — ₱1,000.00', $thread[2]['text']);
        $this->assertStringContainsString('Mailing List — ₱1,111.00', $thread[2]['text']);
        $this->assertStringContainsString('Grand total: ₱2,111.00', $thread[2]['text']);
        $this->assertStringContainsString('Grand total: ₱1,800.00', $thread[0]['text']);
    }
}
