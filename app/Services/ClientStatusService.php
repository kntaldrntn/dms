<?php

namespace App\Services;

use App\Models\Document;
use App\Models\DocumentTrail;

/**
 * The one status a client may see (FGD: latest status only, never the trail), shown by the public
 * barcode + access code lookup on the sign-in page.
 */
class ClientStatusService
{
    /**
     * What a client may see of the document matching both codes, or null when none matches. Nothing else
     * (trail, offices' remarks, files) ever leaves this method.
     */
    public function track(string $barcode, string $accessCode): ?array
    {
        $document = Document::with('department')
            ->where('state', 1)
            ->where('barcode', strtoupper(trim($barcode)))
            ->get()
            // Constant-time compare, so response timing does not hint at a partly right code.
            ->first(fn (Document $document) => hash_equals(strtoupper((string) $document->access_code), strtoupper(trim($accessCode))));
        $status = $document ? $this->latest($document) : null;
        if ($status === null) {
            return null;
        }

        return [
            'barcode' => $document->barcode,
            // Confidential documents: the client sees the status only, not what the document is about.
            'subject' => $document->is_confidential ? null : $document->subject_matter,
            'received_at' => $document->created_at->timezone('Asia/Manila')->format('M j, Y g:i A'),
            'status' => $status,
            'updated_at' => $document->updated_at->timezone('Asia/Manila')->format('M j, Y g:i A'),
        ];
    }

    /** Null when the client should hear nothing (deactivated document). */
    public function latest(Document $document): ?string
    {
        if ($document->state !== 1) {
            return null;
        }
        if ($document->status === 'Completed') {
            return 'Completed';
        }

        return match ($document->stage) {
            DocumentTrail::STAGE_ADM_APPROVAL => 'For approval of the City Administrator',
            DocumentTrail::STAGE_OCM_APPROVAL => 'For approval of the City Mayor',
            default => $document->department?->code === 'REC'
                ? 'Received by the Records Section'
                : 'Being processed at the '.($document->department?->name ?? 'concerned office'),
        };
    }
}
