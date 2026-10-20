<?php

namespace App\Mail;

use App\Models\ContactInquiry;
use Illuminate\Mail\Mailable;

class ContactInquiryReplyMail extends Mailable
{
    public function __construct(
        public ContactInquiry $inquiry,
        public string $replyMessage,
    ) {}

    public function build()
    {
        $name = trim($this->inquiry->first_name . ' ' . $this->inquiry->last_name) ?: 'there';

        $careAddress = config('mail.from.address');

        return $this->subject('Re: Your Contact Us inquiry — ' . $this->inquiry->inquiry_type)
            ->replyTo($careAddress, config('mail.from.name', 'WebFocus Customer Care'))
            ->view('emails.contact-inquiry-reply', [
                'name' => $name,
                'inquiryType' => $this->inquiry->inquiry_type,
                'replyMessage' => $this->replyMessage,
            ]);
    }
}
