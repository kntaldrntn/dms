<?php

namespace App\Jobs;

use App\Models\Document;
use App\Services\SmsService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Texts the client their barcode + access code once, like the tracking-code email. */
class SendTrackingSms implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [60, 300];

    public function __construct(public int $documentId) {}

    public function handle(SmsService $sms): void
    {
        $document = Document::find($this->documentId);
        $number = $sms->mobileNumber($document?->contact_no);
        if (! $number || $document->state !== 1) {
            return;
        }

        try {
            $sms->send($number, self::message($document));
        } catch (Throwable $exception) {
            Log::warning('Tracking code SMS failed.', [
                'document_id' => $document->id,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            throw $exception;
        }
    }

    /** Kept short (no subject): one or two SMS parts. */
    public static function message(Document $document): string
    {
        return "City of San Fernando DMS: We received your document. Barcode: {$document->barcode} "
            ."Access code: {$document->access_code}. Track it at ".route('login', ['tab' => 'track']);
    }
}
