<?php

namespace App\Jobs;

use App\Mail\DocumentTrackingCode;
use App\Models\Document;
use App\Services\MailSettingsService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Emails the client their barcode + access code once (FGD: clients look up the latest status themselves on the
 * DMS sign-in page; no email per status change).
 */
class SendTrackingCode implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [60, 300];

    public function __construct(public int $documentId) {}

    public function handle(MailSettingsService $mailSettings): void
    {
        $mailSettings->apply();
        $document = Document::find($this->documentId);
        if (! $document?->email || $document->state !== 1) {
            return;
        }

        try {
            Mail::to($document->email)->send(new DocumentTrackingCode($document));
        } catch (Throwable $exception) {
            Log::warning('Tracking code email failed.', [
                'document_id' => $document->id,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            throw $exception;
        }
    }
}
