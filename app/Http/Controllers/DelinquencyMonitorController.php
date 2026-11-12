<?php

namespace App\Http\Controllers;

use App\Http\Requests\AcknowledgeDelinquencyTagRequest;
use App\Http\Requests\TagDelinquencyRequest;
use App\Models\DelinquencyTag;
use App\Models\Department;
use App\Models\Document;
use App\Services\DashboardReportService;
use App\Services\DelinquencyTagService;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** FGD Phase 5: the City Administrator's office sees every delinquent document and tags it with a follow-up note. */
class DelinquencyMonitorController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('monitorDelinquency', Document::class);
        $user = $request->user();
        $now = Carbon::now('Asia/Manila');

        // Every office, whatever the ADM user's own role: monitoring is the point of this page.
        $documents = Document::with(['department:id,code,name', 'documentType:id,document_code,document_type', 'transactionType:id,name',
            'latestDelinquencyTag.tagger:id,name', 'latestDelinquencyTag.acknowledger:id,name'])
            ->where('state', 1)->where('status', '!=', 'Completed')->whereNotNull('department_id')
            ->get()
            ->filter(fn (Document $document) => $document->is_delinquent);

        $rows = $documents->map(function (Document $document) use ($user, $now) {
            $heldSince = $document->heldSince();
            $hidden = $document->detailsHiddenFrom($user);
            $tag = $document->latestDelinquencyTag;
            // A tag counts for the current holder only; an older tag from a previous office is shown as history.
            $currentTag = $tag && $tag->department_id === $document->department_id ? $tag : null;

            return [
                'id' => $document->id,
                'barcode' => $document->barcode,
                'subject_matter' => $hidden ? 'Confidential document' : $document->subject_matter,
                'details_hidden' => $hidden,
                'document_type' => $document->documentType?->document_type,
                'category' => DashboardReportService::category($document->transactionType?->name),
                'office' => $document->department?->only(['id', 'code', 'name']),
                'held_since' => $heldSince->format('M d, Y g:i A'),
                'held_minutes' => (int) $heldSince->diffInMinutes($now, true),
                'allowed_minutes' => $document->allowedMinutes(),
                'tag' => $currentTag ? [
                    'label' => $currentTag->label,
                    'tag' => $currentTag->tag,
                    'note' => $currentTag->note,
                    'tagged_by' => $currentTag->tagger?->name,
                    'tagged_at' => $currentTag->tagged_at->format('M d, Y g:i A'),
                    'acknowledged_by' => $currentTag->acknowledger?->name,
                    'acknowledged_at' => $currentTag->acknowledged_at?->format('M d, Y g:i A'),
                ] : null,
            ];
        })->sortByDesc('held_minutes')->values();

        return Inertia::render('delinquency/Index', [
            'rows' => $rows->all(),
            'tags' => collect(DelinquencyTag::TAGS)->map(fn ($label, $value) => compact('value', 'label'))->values()->all(),
            'offices' => Department::whereIn('id', $rows->pluck('office.id')->unique())->orderBy('code')->get(['id', 'code', 'name']),
            'asOf' => $now->format('M d, Y g:i A'),
        ]);
    }

    public function tag(TagDelinquencyRequest $request, Document $document, DelinquencyTagService $tags): RedirectResponse
    {
        $tag = $tags->tag($request->user(), $document, $request->validated('tag'), $request->validated('note'));

        return back()->with('success', "Document {$document->barcode} tagged \"{$tag->label}\". Its office sees the note in its inbox.");
    }

    public function acknowledge(AcknowledgeDelinquencyTagRequest $request, Document $document, DelinquencyTag $tag, DelinquencyTagService $tags): RedirectResponse
    {
        $tags->acknowledge($request->user(), $document, $tag);

        return back()->with('success', "Noted the City Administrator's tag on {$document->barcode}.");
    }
}
