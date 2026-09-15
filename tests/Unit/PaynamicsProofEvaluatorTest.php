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
}
