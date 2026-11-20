<?php

namespace App\Http\Controllers;

use App\Http\Requests\ArchiveSearchRequest;
use App\Models\DeliveryMethod;
use App\Models\Department;
use App\Models\DocumentClassification;
use App\Models\DocumentType;
use App\Models\TransactionType;
use App\Services\ArchiveSearchService;
use Inertia\Inertia;
use Inertia\Response;

/** Search every document the user may see, like the old DTS "Transaction" page. Read-only. */
class ArchiveController extends Controller
{
    public function __invoke(ArchiveSearchRequest $request, ArchiveSearchService $archive): Response
    {
        // Each registry names its label column differently; the page gets plain {id, name} options.
        $options = fn (string $model, string $label = 'name') => $model::where('state', 1)->orderBy($label)
            ->get(['id', "{$label} as name"]);

        return Inertia::render('archive/Index', [
            'documents' => $archive->search($request->user(), $request->filters(), $request->perPage()),
            'filters' => $request->filters(),
            'perPage' => $request->perPage(),
            'perPageOptions' => ArchiveSearchRequest::PER_PAGE,
            'options' => [
                'documentTypes' => $options(DocumentType::class, 'document_type'),
                'transactionTypes' => $options(TransactionType::class),
                'classifications' => $options(DocumentClassification::class),
                'deliveryMethods' => $options(DeliveryMethod::class, 'delivery_method_name'),
                'offices' => Department::where('state', 1)->orderBy('code')->get(['id', 'code', 'name']),
            ],
        ]);
    }
}
