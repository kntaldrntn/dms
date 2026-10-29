<?php

namespace App\Services;

use App\Models\Document;
use App\Models\DocumentTrail;

/**
 * The one status a client may see (FGD: latest status only, never the trail). Shared by the client email
 * and, later, SMS and the barcode + access code lookup.
 */
class ClientStatusService
{
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
