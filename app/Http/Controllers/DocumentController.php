<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use App\Models\Document;
use App\Models\DocumentTrail;
use Illuminate\Support\Facades\Auth;
use App\Models\TransactionType;
use App\Models\DocumentType;
use App\Models\DocumentClassification;
use App\Models\DeliveryMethod;
use App\Models\Department;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Illuminate\Support\Str;
use OpenAI\Laravel\Facades\OpenAI;
use Smalot\PdfParser\Parser;

class DocumentController extends Controller
{
    public function index(Request $request)
    {
        $query = Document::with(['documentType', 'department', 'transactionType'])
                         ->where('state', 1);

        if ($request->filled('search')) {
            $searchTerm = $request->search;
            $query->where(function($q) use ($searchTerm) {
                $q->where('barcode', 'LIKE', "%{$searchTerm}%")
                  ->orWhere('subject_matter', 'LIKE', "%{$searchTerm}%")
                  ->orWhere('source_name', 'LIKE', "%{$searchTerm}%");
            });
        }

        $documents = $query->latest()->paginate(10)->withQueryString();

        return Inertia::render('documents/Index', [
            'documents' => $documents,
            'departments' => Department::where('state', 1)->get(),
            'filters' => $request->only(['search']),
            'flash' => [
                'success' => session('success'),
                'error' => session('error')
            ]
        ]);
    }

    public function create()
    {
        $recentDocuments = Document::with('documentType')
            ->where('state', 1)
            ->latest()
            ->take(5)
            ->get();

        return Inertia::render('documents/Create', [
            'transactionTypes' => TransactionType::where('state', 1)->get(),
            'documentTypes' => DocumentType::where('state', 1)->get(),
            'classifications' => DocumentClassification::where('state', 1)->get(),
            'deliveryMethods' => DeliveryMethod::where('state', 1)->get(),
            'departments' => Department::where('state', 1)->get(),
            'generatedAccessCode' => strtoupper(Str::random(6)),
            'recentDocuments' => $recentDocuments,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'barcode' => 'required|string|max:50|unique:documents,barcode',
            'transaction_type_id' => 'required|exists:transaction_types,id',
            'document_type_id' => 'required|exists:document_types,id',
            'source_type' => 'required|string',
            'source_location' => 'nullable|string',
            'source_name' => 'required|string|max:255',
            'delivery_method_id' => 'nullable|exists:delivery_methods,id',
            'gender' => 'nullable|string',
            'contact_no' => 'nullable|string',
            'email' => 'nullable|email',
            'subject_matter' => 'required|string',
            'classification_id' => 'nullable|exists:document_classifications,id',
            'department_id' => 'nullable|exists:departments,id',
            'linked_documents' => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:10240',
            'access_code' => 'required|string|max:20',
            'ai_routing_suggestions' => 'nullable|array',
        ]);

        if ($request->hasFile('linked_documents')) {
            $path = $request->file('linked_documents')->store('documents', 'public');
            $validated['linked_documents'] = $path; 
        }

        $transactionType = TransactionType::findOrFail($validated['transaction_type_id']);
        
        $now = Carbon::now('Asia/Manila'); 
        $validated['due_date'] = $now->copy()->addWeekdays($transactionType->processing_days);
        $validated['status'] = 'Pending';

        $document = Document::create($validated);

        DocumentTrail::create([
            'document_id' => $document->id,
            'department_id' => Auth::user()->department_id, 
            'received_by' => Auth::id(),
            'received_at' => $now,
            'received_action' => 'New Document Trail',
            'released_by' => Auth::id(),
            'released_at' => $now, 
            'released_to' => $validated['department_id'], 
        ]);

        return redirect()->route('documents.index')->with('success', 'Document registered successfully.');
    }

    public function show(Document $document)
    {
        $document->load([
            'transactionType', 
            'documentType', 
            'classification', 
            'deliveryMethod', 
            'department',
            'trails.office',          
            'trails.receiver',        
            'trails.releaser',        
            'trails.releasedToOffice' 
        ]);
        
        return Inertia::render('documents/Show', [
            'document' => $document,
            'trails' => $document->trails 
        ]);
    }

    public function edit(Document $document)
    {
        return Inertia::render('documents/Edit', [
            'document' => $document,
            'transactionTypes' => TransactionType::where('state', 1)->get(),
            'documentTypes' => DocumentType::where('state', 1)->get(),
            'classifications' => DocumentClassification::where('state', 1)->get(),
            'deliveryMethods' => DeliveryMethod::where('state', 1)->get(),
            'departments' => Department::where('state', 1)->get(),
        ]);
    }

    public function update(Request $request, Document $document)
    {
        $rules = [
            'barcode' => 'required|string|max:50|unique:documents,barcode,' . $document->id,
            'transaction_type_id' => 'required|exists:transaction_types,id',
            'document_type_id' => 'required|exists:document_types,id',
            'source_type' => 'required|string',
            'source_location' => 'nullable|string',
            'source_name' => 'required|string|max:255',
            'delivery_method_id' => 'nullable|exists:delivery_methods,id',
            'gender' => 'nullable|string',
            'contact_no' => 'nullable|string',
            'email' => 'nullable|email',
            'subject_matter' => 'required|string',
            'classification_id' => 'nullable|exists:document_classifications,id',
            'department_id' => 'nullable|exists:departments,id',
            'access_code' => 'required|string|max:20',
            'ai_routing_suggestions' => 'nullable|array',
        ];

        if ($request->hasFile('linked_documents')) {
            $rules['linked_documents'] = 'nullable|file|mimes:pdf,jpg,jpeg,png|max:10240';
        } else {
            $rules['linked_documents'] = 'nullable|string';
        }

        $validated = $request->validate($rules);

        if ($request->hasFile('linked_documents')) {
            $path = $request->file('linked_documents')->store('documents', 'public');
            $validated['linked_documents'] = $path;
        } elseif (!array_key_exists('linked_documents', $validated)) {
            $validated['linked_documents'] = $document->linked_documents;
        }

        if ($document->transaction_type_id != $validated['transaction_type_id']) {
            $transactionType = TransactionType::findOrFail($validated['transaction_type_id']);
            $validated['due_date'] = Carbon::parse($document->created_at, 'Asia/Manila')->addWeekdays($transactionType->processing_days);
        }

        if ($document->department_id != $validated['department_id']) {
            $latestTrail = DocumentTrail::where('document_id', $document->id)
                ->where('released_to', $document->department_id)
                ->latest()
                ->first();

            if ($latestTrail) {
                $latestTrail->update([
                    'released_to' => $validated['department_id']
                ]);
            }
        }

        $document->update($validated);

        return redirect()->route('documents.index')->with('success', 'Document updated successfully.');
    }

    public function destroy(Document $document)
    {
        $document->update(['state' => 0]);
        return redirect()->back()->with('success', 'Document deactivated successfully.');
    }

    public function analyze(Request $request)
    {
        $request->validate([
            'ai_thumbnail' => 'required|mimes:jpg,jpeg|max:5120',
            'extracted_text' => 'required|string',
        ]);

        try {
            $departments = Department::where('state', 1)->pluck('name', 'id')->toJson();
            $documentTypes = DocumentType::where('state', 1)->pluck('document_type', 'id')->toJson();
            $transactionTypes = TransactionType::where('state', 1)->pluck('name', 'id')->toJson();
            $classifications = DocumentClassification::where('state', 1)->pluck('name', 'id')->toJson();
            $deliveryMethods = DeliveryMethod::where('state', 1)->pluck('delivery_method_name', 'id')->toJson();

            $systemInstruction = "You are an advanced document intake AI utilizing OCR-Assisted Vision. You have a blurry thumbnail (only good for general layout) and raw OCR text. YOU MUST RELY ON THE OCR TEXT FOR EXACT SPELLINGS.
            
            YOUR STRATEGY:
            1. Extract \"Decoy\" info (Letterheads, Memo Numbers) first.
            2. To find the source_name, you MUST look at the very end of the OCR text.
            3. Identify and IGNORE any \"Referral Slip\", \"Routing Slip\", or \"Action Slip\". The REAL document you must analyze is the one that contains the stamped barcode.
            
            Return ONLY a valid JSON object matching this strict structure:
            {
              '_thought_process': 'String. Step 1: Find the barcode (1 uppercase letter + 9 digits) anywhere in the OCR text, including a second page if present. Quote the exact substring you found, even if it looks OCR-garbled.',
              'decoy_memo_number': 'String or null. Extract Memorandum No. here if one exists (e.g., OT-402-2026).',
              'decoy_letterhead': 'String. Extract the massive Office Name at the top here.',
              'barcode': 'String. Extract the barcode quoted in your thought process. It might be on the SECOND page, so search the entire text. It MUST be 1 uppercase letter followed by numbers. CRITICAL: Do NOT invent a barcode by combining currency amounts like PHP500,000. If the OCR read \"C171\" or \"C001\", correct it to \"C003\" (e.g., C003192158).',
              'source_type': 'String. Strictly \"Internal\" or \"External\".',
              'source_location': 'If Internal, return the integer ID of the sending department. If External, return null.',
              'source_name': 'String. Locate the signature block at the VERY BOTTOM of the image, then extract their FULL NAME from the OCR text (e.g., ATTY. GILBERT L. CALOZA). CRITICAL EXCLUSION: NEVER use the name next to \"To:\" at the top, and NEVER use names from the middle paragraphs.',
              'gender': 'String. Strictly \"Male\" or \"Female\". Infer from the sender\'s title (Mr./Ms./Atty.) or first name.',
              'contact_no': 'String. Extract the sender\'s phone or contact number if present. Return null if none.',
              'email': 'String. Extract the sender\'s email address if present. Return null if none.',
              'delivery_method_id': 'Integer ID matching how the document arrived. Default to Hand Carry ID if unstated.',
              'subject_matter': 'String. IF decoy_memo_number exists, format EXACTLY as: \"memorandum no. [decoy_memo_number] [Recipient Name] re: [Brief Summary]\". IF NO memo number, format as: \"[Recipient Name] re: [Brief Summary]\".',
              'document_type_id': 'null or integer ID matching the best document type',
              'transaction_type_id': 'null or integer ID matching the best transaction type based on ease of business law in the Philippines',
              'classification_id': 'null or integer ID matching the best classification',
              'department_id': 'Integer or null. Strictly return 31 (Office of the City Mayor), 3 (Office of the City Administrator), or null (Other Departments). ONLY use 31 or 3 if the document is explicitly ADDRESSED TO or REQUIRES APPROVAL FROM them. CRITICAL: Do NOT route it to 3 or 31 if they are the ones SENDING the document. If it is going to a regular department, return null.',
              'approval_reason': 'String or null. If you selected 3 or 31, briefly explain why.',
              'routing_suggestions': [
                {
                  'department_id': 'Integer ID',
                  'priority': 'String. Strictly output Primary, Secondary, or FYI.',
                  'reason': 'Detailed explanation'
                }
              ],
              'NOTE_FOR_ROUTING': 'Provide 1 to 4 highly relevant routing suggestions. CRITICAL: DO NOT guess or invent hypothetical scenarios. Assign EXACTLY ONE office as Primary (the main executor of the request). Any other offices must be Secondary or FYI (like Budget/Accounting for records). STRICT RULE: If you selected 3 or 31 for the main department_id, DO NOT include them again here.',
              'confidence_score': 'Integer between 1 and 100.'
            }";

            $userPrompt = "Match the details against these system options:\nDepartments: $departments\nDocument Types: $documentTypes\nTransaction Types: $transactionTypes\nClassifications: $classifications\nDelivery Methods: $deliveryMethods";

            $cleanText = substr(preg_replace('/\s+/', ' ', trim($request->extracted_text)), 0, 12000);
            
            $thumbnail = $request->file('ai_thumbnail');
            $base64Image = base64_encode(file_get_contents($thumbnail->path()));
            $mimeType = $thumbnail->getMimeType();

            $response = OpenAI::chat()->create([
                'model' => 'gpt-4o-mini',
                'response_format' => ['type' => 'json_object'],
                'messages' => [
                    ['role' => 'system', 'content' => $systemInstruction],
                    [
                        'role' => 'user',
                        'content' => [
                            ['type' => 'text', 'text' => $userPrompt . "\n\nRaw Document OCR Text:\n" . $cleanText],
                            [
                                'type' => 'image_url', 
                                'image_url' => [
                                    'url' => "data:{$mimeType};base64,{$base64Image}",
                                    'detail' => 'low'
                                ]
                            ]
                        ]
                    ]
                ],
                'temperature' => 0.1,
            ]);

            $result = json_decode($response->choices[0]->message->content, true);
            
            // --- NEW: BARCODE ZERO-PADDING FIX ---
            if (!empty($result['barcode']) && is_string($result['barcode'])) {
                // Remove any accidental spaces or dashes the AI included
                $cleanBarcode = preg_replace('/[-\s]/', '', $result['barcode']);
                
                // If it matches exactly 1 Letter followed by ANY amount of numbers
                if (preg_match('/^([A-Z])([0-9]+)$/i', $cleanBarcode, $matches)) {
                    $letter = strtoupper($matches[1]);
                    // Force the number portion to be exactly 9 digits by adding leading zeros
                    $digits = str_pad($matches[2], 9, '0', STR_PAD_LEFT);
                    $result['barcode'] = $letter . $digits;
                }
            }
            // -------------------------------------

            if (isset($response->usage)) {
                $result['meta'] = [
                    'total_tokens' => $response->usage->totalTokens,
                    'prompt_tokens' => $response->usage->promptTokens,
                    'completion_tokens' => $response->usage->completionTokens,
                ];
            }

            return response()->json($result);

        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed to analyze document: ' . $e->getMessage()], 500);
        }
    }

    public function routeDocument(Request $request, Document $document)
    {
        // 1. Validate that we received an array of offices
        $validated = $request->validate([
            'routed_to' => 'required|array|min:1',
            'routed_to.*' => 'exists:departments,id',
        ]);

        $now = Carbon::now('Asia/Manila');

        // 2. Loop through every selected office and create a parallel trail
        foreach ($validated['routed_to'] as $departmentId) {
            DocumentTrail::create([
                'document_id' => $document->id,
                'department_id' => Auth::user()->department_id, // The office releasing it
                'received_by' => Auth::id(),
                'received_at' => $now,
                'received_action' => 'Routed via Fast Forward distribution',
                'released_by' => Auth::id(),
                'released_at' => $now,
                'released_to' => $departmentId, // The target execution office
            ]);
        }

        $document->update([
            'department_id' => $validated['routed_to'][0],
        ]);

        // 4. Return back to the page so the Vue modal triggers onSuccess and closes
        return redirect()->back()->with('success', 'Document successfully routed to selected offices.');
    }
}