<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class ApplicationSummaryMail extends Mailable
{
    public function __construct(public string $companyName, public string $jobTitle, public int $newCount) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('applications.mail_subject'));
    }

    public function content(): Content
    {
        return new Content(view: 'mail.application-summary', text: 'mail.application-summary-text');
    }
}
