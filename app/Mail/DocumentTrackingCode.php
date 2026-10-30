<?php

namespace App\Mail;

use App\Models\Document;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class DocumentTrackingCode extends Mailable
{
    public function __construct(public Document $document) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Document {$this->document->barcode} received: your tracking code");
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.document-tracking-code', with: [
            'name' => $this->document->source_name,
            'barcode' => $this->document->barcode,
            'accessCode' => $this->document->access_code,
            // Confidential documents: the email must not say what the document is about.
            'subject' => $this->document->is_confidential ? null : $this->document->subject_matter,
            'receivedAt' => $this->document->created_at->timezone('Asia/Manila')->format('F j, Y g:i A'),
            'trackUrl' => route('login', ['tab' => 'track']),
        ]);
    }
}
