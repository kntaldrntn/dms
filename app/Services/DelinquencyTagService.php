<?php

namespace App\Services;

use App\Models\DelinquencyTag;
use App\Models\Document;
use App\Models\DocumentTrail;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * FGD Phase 5: ADM tags delinquent documents and the holding office acknowledges the tag.
 *
 * A tag is a monitoring note, not a custody change: it does not go through DocumentWorkflow::mutate(), because
 * bumping workflow_version would make the holding office's open Process form stale for a note it did not cause.
 * The document row is still locked so the delinquency check and the insert see the same custody.
 */
class DelinquencyTagService
{
    public function tag(User $user, Document $document, string $tag, string $note): DelinquencyTag
    {
        return DB::transaction(function () use ($user, $document, $tag, $note) {
            $locked = Document::whereKey($document->id)->lockForUpdate()->firstOrFail();
            if (Gate::forUser($user)->denies('tagDelinquency', $locked)) {
                throw ValidationException::withMessages([
                    'tag' => "Document {$locked->barcode} is no longer delinquent: its office acted on it or it was completed.",
                ]);
            }

            $entry = DocumentTrail::where('document_id', $locked->id)->where('copy_role', DocumentTrail::PRIMARY)
                ->whereNull('released_at')->latest('id')->first();
            $created = DelinquencyTag::create([
                'document_id' => $locked->id,
                'department_id' => $locked->department_id,
                'document_trail_id' => $entry?->id,
                'tag' => $tag,
                'note' => $note,
                'tagged_by' => $user->id,
                'tagged_at' => Carbon::now('Asia/Manila')->format('Y-m-d H:i:s'),
            ]);
            Log::info('Delinquent document tagged by ADM.', [
                'document_id' => $locked->id, 'department_id' => $locked->department_id, 'tag' => $tag, 'user_id' => $user->id,
            ]);

            return $created;
        });
    }

    public function acknowledge(User $user, Document $document, DelinquencyTag $tag): void
    {
        DB::transaction(function () use ($user, $document, $tag) {
            $locked = DelinquencyTag::whereKey($tag->id)->lockForUpdate()->firstOrFail();
            // Two users of the office may click Noted at once: the second one is told it is already done.
            if ($locked->acknowledged_at !== null) {
                throw ValidationException::withMessages(['tag' => 'This tag was already noted by your office.']);
            }
            if (Gate::forUser($user)->denies('acknowledgeDelinquencyTag', [$document, $locked])) {
                abort(403);
            }
            $locked->update([
                'acknowledged_by' => $user->id,
                'acknowledged_at' => Carbon::now('Asia/Manila')->format('Y-m-d H:i:s'),
            ]);
        });
    }
}
