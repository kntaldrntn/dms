<?php

namespace App\Jobs;

use App\Mail\ClientStatusUpdate;
use App\Models\Document;
use App\Services\ClientStatusService;
use App\Services\MailSettingsService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/** Emails the client when their document's latest status changes. */
class NotifyClientOfStatus implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [60, 300];

    public function __construct(public int $documentId) {}

    public function handle(ClientStatusService $statuses, MailSettingsService $mailSettings): void
    {
        $mailSettings->apply();
        $document = Document::with('department')->find($this->documentId);
        $status = $document?->email ? $statuses->latest($document) : null;
        if ($status === null) {
            return;
        }

        $previous = $document->client_notified_status;
        // Claim the status atomically: of several jobs for the same change, only one sends.
        $claimed = Document::whereKey($document->id)
            ->where(fn ($query) => $query->whereNull('client_notified_status')->orWhere('client_notified_status', '!=', $status))
            ->update(['client_notified_status' => $status]);
        if ($claimed === 0) {
            return;
        }

        try {
            Mail::to($document->email)->send(new ClientStatusUpdate($document, $status));
        } catch (Throwable $exception) {
            // Release the claim so the retry sends it.
            Document::whereKey($document->id)->where('client_notified_status', $status)->update(['client_notified_status' => $previous]);
            Log::warning('Client status email failed.', [
                'document_id' => $document->id,
                'status' => $status,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            throw $exception;
        }
    }
}
