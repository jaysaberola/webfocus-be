<?php

namespace Tests\Unit;

use App\Support\PaynamicsProofEvaluator;
use PHPUnit\Framework\TestCase;

class PaynamicsProofEvaluatorTest extends TestCase
{
    public function test_accepts_paynamics_payment_success_receipt(): void
    {
        $text = <<<TXT
        Paynamics
        Payment Success
        Request ID: WF260915103045ABCDEFGH
        Amount PHP 6,080.00
        Response Code GR001
        TXT;

        $result = PaynamicsProofEvaluator::evaluate($text, 6080.00);

        $this->assertTrue($result['valid']);
        $this->assertSame(PaynamicsProofEvaluator::CODE_OK, $result['code']);
    }

    public function test_rejects_unrelated_gcash_receipt(): void
    {
        $text = <<<TXT
        GCash
        You have sent PHP 6,080.00
        Ref No. 1234567890
        Transaction successful
        TXT;

        $result = PaynamicsProofEvaluator::evaluate($text, 6080.00);

        $this->assertFalse($result['valid']);
        $this->assertSame(PaynamicsProofEvaluator::CODE_NOT_PAYNAMICS, $result['code']);
    }

    public function test_rejects_amount_mismatch(): void
    {
        $text = <<<TXT
        Paynamics Payment Success
        Amount PHP 1,000.00
        Request ID WF260915103045ABCDEFGH
        TXT;

        $result = PaynamicsProofEvaluator::evaluate($text, 6080.00);

        $this->assertFalse($result['valid']);
        $this->assertSame(PaynamicsProofEvaluator::CODE_AMOUNT_MISMATCH, $result['code']);
    }

    public function test_rejects_checkout_page(): void
    {
        $text = <<<TXT
        Paynamics
        How would you like to pay?
        Credit / Debit Card
        GCash Maya
        Amount PHP 6,080.00
        TXT;

        $result = PaynamicsProofEvaluator::evaluate($text, 6080.00);

        $this->assertFalse($result['valid']);
        $this->assertSame(PaynamicsProofEvaluator::CODE_CHECKOUT_PAGE, $result['code']);
    }

    public function test_rejects_unreadable_text(): void
    {
        $result = PaynamicsProofEvaluator::evaluate('??', 6080.00);

        $this->assertFalse($result['valid']);
        $this->assertSame(PaynamicsProofEvaluator::CODE_UNREADABLE, $result['code']);
    }

    public function test_accepts_matching_request_id_without_brand(): void
    {
        $text = <<<TXT
        Payment Success
        Request ID WF260915103045ABCDEFGH
        Amount 6080.00
        TXT;

        $result = PaynamicsProofEvaluator::evaluate($text, 6080.00, ['WF260915103045ABCDEFGH']);

        $this->assertTrue($result['valid']);
        $this->assertSame('WF260915103045ABCDEFGH', $result['matched_request_id']);
    }

    public function test_accepts_hosted_paynamics_success_page_without_brand_word(): void
    {
        $text = <<<TXT
        Payment Success
        WEBFOCUS SOLUTIONS, INC
        Amount PHP 23,400.00
        Request ID WF260915105840VHPNDTW3
        Payment Date Sep 15, 2026 10:59 AM
        Payment Method Credit Card
        Payment Channel Unionbank of the Philippines
        Go back to merchant
        Have concerns on payment?
        TXT;

        $result = PaynamicsProofEvaluator::evaluate($text, 23400.00, ['WF260915105840VHPNDTW3']);

        $this->assertTrue($result['valid']);
        $this->assertSame(PaynamicsProofEvaluator::CODE_OK, $result['code']);
        $this->assertTrue($result['has_brand']);
        $this->assertSame('WF260915105840VHPNDTW3', $result['matched_request_id']);
    }

    public function test_accepts_matching_payment_date_and_time(): void
    {
        $text = <<<TXT
        Paynamics
        Payment Success
        Request ID: WF260915105840VHPNDTW3
        Amount PHP 23,400.00
        Payment Date Sep 15, 2026 10:59 AM
        TXT;

        $result = PaynamicsProofEvaluator::evaluate(
            $text,
            23400.00,
            ['WF260915105840VHPNDTW3'],
            [],
            ['2026-09-15 10:59:00']
        );

        $this->assertTrue($result['valid']);
        $this->assertTrue($result['has_date']);
        $this->assertSame(PaynamicsProofEvaluator::CODE_OK, $result['code']);
    }

    public function test_rejects_wrong_payment_date(): void
    {
        $text = <<<TXT
        Paynamics
        Payment Success
        Request ID: WF260915105840VHPNDTW3
        Amount PHP 23,400.00
        Payment Date Aug 01, 2026 10:59 AM
        TXT;

        $result = PaynamicsProofEvaluator::evaluate(
            $text,
            23400.00,
            ['WF260915105840VHPNDTW3'],
            [],
            ['2026-09-15 10:59:00']
        );

        $this->assertFalse($result['valid']);
        $this->assertSame(PaynamicsProofEvaluator::CODE_DATE_MISMATCH, $result['code']);
    }

    public function test_rejects_same_date_with_unrelated_time(): void
    {
        $text = <<<TXT
        Paynamics
        Payment Success
        Request ID: WF260915105840VHPNDTW3
        Amount PHP 23,400.00
        Payment Date Sep 15, 2026 3:10 AM
        TXT;

        $result = PaynamicsProofEvaluator::evaluate(
            $text,
            23400.00,
            ['WF260915105840VHPNDTW3'],
            [],
            ['2026-09-15 10:59:00']
        );

        $this->assertFalse($result['valid']);
        $this->assertSame(PaynamicsProofEvaluator::CODE_DATE_MISMATCH, $result['code']);
    }

    public function test_rejects_success_receipt_for_a_different_invoice(): void
    {
        $text = <<<TXT
        Payment Success
        WEBFOCUS SOLUTIONS, INC
        Amount PHP 23,400.00
        Request ID WF260915105840VHPNDTW3
        Payment Method Credit Card
        Go back to merchant
        TXT;

        $result = PaynamicsProofEvaluator::evaluate(
            $text,
            23400.00,
            ['WF260915120000OTHERINV'],
            ['WF260915105840VHPNDTW3']
        );

        $this->assertFalse($result['valid']);
        $this->assertSame(PaynamicsProofEvaluator::CODE_WRONG_INVOICE, $result['code']);
    }

    public function test_rejects_when_invoice_request_id_is_missing_from_receipt(): void
    {
        $text = <<<TXT
        Payment Success
        WEBFOCUS SOLUTIONS, INC
        Amount PHP 23,400.00
        Payment Method Credit Card
        Go back to merchant
        TXT;

        $result = PaynamicsProofEvaluator::evaluate($text, 23400.00, ['WF260915105840VHPNDTW3']);

        $this->assertFalse($result['valid']);
        $this->assertSame(PaynamicsProofEvaluator::CODE_REQUEST_ID_MISMATCH, $result['code']);
    }

    public function test_rejects_receipt_request_id_that_belongs_to_another_payment(): void
    {
        $text = <<<TXT
        Payment Success
        WEBFOCUS SOLUTIONS, INC
        Amount PHP 23,400.00
        Request ID WF260915105840VHPNDTW3
        Payment Method Credit Card
        Go back to merchant
        TXT;

        $result = PaynamicsProofEvaluator::evaluate(
            $text,
            23400.00,
            [],
            ['WF260915105840VHPNDTW3']
        );

        $this->assertFalse($result['valid']);
        $this->assertSame(PaynamicsProofEvaluator::CODE_WRONG_INVOICE, $result['code']);
    }
}
