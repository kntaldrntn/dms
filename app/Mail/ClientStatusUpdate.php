<?php

namespace App\Mail;

use App\Models\Document;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class ClientStatusUpdate extends Mailable
{
    public function __construct(public Document $document, public string $status) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Document {$this->document->barcode}: {$this->status}");
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.client-status-update', with: [
            'name' => $this->document->source_name,
            'barcode' => $this->document->barcode,
            // Confidential documents: the client hears the status only, not what the document is about.
            'subject' => $this->document->is_confidential ? null : $this->document->subject_matter,
            'status' => $this->status,
            'updatedAt' => now('Asia/Manila')->format('F j, Y g:i A'),
        ]);
    }
}
