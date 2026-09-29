<?php

namespace App\Mail;

use App\Models\SalesTransaction;
use App\Support\WebDesignMeta;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class WebDesignQuotationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public SalesTransaction $transaction,
        public ?string $assigneeName = null,
    ) {
    }

    public function build()
    {
        return $this->subject('New Web Design Quotation Request · '.$this->transaction->transaction_no)
            ->view('emails.web-design-quotation')
            ->with([
                'assigneeName' => $this->assigneeName,
                'clientNotes' => WebDesignMeta::clientNotes($this->transaction->notes),
                'additionalServices' => WebDesignMeta::additionalServices($this->transaction->notes),
            ]);
    }
}
