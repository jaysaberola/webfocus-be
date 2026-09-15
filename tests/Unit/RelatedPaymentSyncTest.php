<?php

namespace Tests\Unit;

use App\Models\PaynamicsPaymentReference;
use App\Models\SalesTransaction;
use App\Support\RelatedPaymentSync;
use Carbon\Carbon;
use Tests\TestCase;

class RelatedPaymentSyncTest extends TestCase
{
    public function test_reads_payment_date_and_mode_from_notes(): void
    {
        $transaction = new SalesTransaction();
        $transaction->setRelation('paynamicsPaymentReferences', collect());
        $transaction->notes = "Payment date: 2026-09-15\nPayment mode: Credit Card";

        $this->assertSame('2026-09-15', RelatedPaymentSync::dateFrom($transaction));
        $this->assertSame('Credit Card', RelatedPaymentSync::modeFrom($transaction));
    }

    public function test_prefers_paid_paynamics_reference_over_notes(): void
    {
        $reference = new PaynamicsPaymentReference([
            'status' => 'paid',
            'payment_method' => 'UnionBank',
        ]);
        $reference->paid_at = Carbon::parse('2026-09-15 10:59:00');

        $transaction = new SalesTransaction();
        $transaction->notes = "Payment date: 2026-01-01\nPayment mode: Cash";
        $transaction->setRelation('paynamicsPaymentReferences', collect([$reference]));

        $this->assertSame('2026-09-15', RelatedPaymentSync::dateFrom($transaction));
        $this->assertSame('UnionBank', RelatedPaymentSync::modeFrom($transaction));
    }
}
