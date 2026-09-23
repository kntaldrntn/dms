<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use App\Models\Document;
use App\Models\DocumentTrail;
use App\Models\User;
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

        $documents = $query->latest()->paginate(6)->withQueryString();

        return Inertia::render('documents/Index', [
            'documents' => $documents, 
            'departments' => Department::where('state', 1)->get(),
            'filters' => $request->only(['search']),
            'flash' => [
                'success' => session('success'),
                'error' => session('error')
            ],
            'transactionTypes' => TransactionType::where('state', 1)->get(),
            'documentTypes' => DocumentType::where('state', 1)->get(),
            'classifications' => DocumentClassification::where('state', 1)->get(),
            'deliveryMethods' => DeliveryMethod::where('state', 1)->get(),
            'generatedAccessCode' => strtoupper(Str::random(6)),
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
            'access_code' => 'required|string|max:20',
            'ai_routing_suggestions' => 'nullable|array',
            'linked_documents' => 'nullable|string',
            'attached_file' => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:10240',
        ]);

        if ($request->hasFile('attached_file')) {
            $path = $request->file('attached_file')->store('documents', 'public');
            $validated['attached_file'] = $path; 
        }

        $transactionType = TransactionType::findOrFail($validated['transaction_type_id']);
        
        $now = Carbon::now('Asia/Manila'); 
        $validated['due_date'] = $now->copy()->addWeekdays($transactionType->processing_days);
        $validated['status'] = 'Pending';

        $document = Document::create($validated);

        // 1. Always create the initial receipt trail for Records
        $initialTrail = DocumentTrail::create([
            'document_id' => $document->id,
            'department_id' => Auth::user()->department_id ?? 1, 
            'received_by' => Auth::id(),
            'received_at' => $now,
            'received_action' => 'Document Scanned & Registered',
        ]);

        // 2. AUTO-ROUTE FIX: Only attempt to dispatch if a specific department was selected
        if (!empty($validated['department_id'])) {
            $initialTrail->update([
                'released_by' => Auth::id(),
                'released_at' => $now,
                'released_action' => 'Direct Dispatch',
                'released_to' => $validated['department_id'], 
            ]);

            DocumentTrail::create([
                'document_id' => $document->id,
                'department_id' => $validated['department_id'],
                'received_at' => $now, // Target office SLA starts
                'received_action' => 'Direct Dispatch from Records', // <-- FIXED BLANK BUG
            ]);
        }

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
            'trails.releasedToOffice',
            'trails.signer'
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
            'linked_documents' => 'nullable|string',
        ];

        if ($request->hasFile('attached_file')) {
            $rules['attached_file'] = 'nullable|file|mimes:pdf,jpg,jpeg,png|max:10240';
        }

        $validated = $request->validate($rules);

        if ($request->hasFile('attached_file')) {
            $path = $request->file('attached_file')->store('documents', 'public');
            $validated['attached_file'] = $path;
        }

        if ($document->transaction_type_id != $validated['transaction_type_id']) {
            $transactionType = TransactionType::findOrFail($validated['transaction_type_id']);
            $validated['due_date'] = Carbon::parse($document->created_at, 'Asia/Manila')->addWeekdays($transactionType->processing_days);
        }

        if ($document->department_id != $validated['department_id']) {
            $latestTrail = DocumentTrail::where('document_id', $document->id)
                ->where('department_id', $document->department_id)
                ->whereNull('released_at')
                ->latest('id')
                ->first();

            if ($latestTrail && !empty($validated['department_id'])) {
                $latestTrail->update([
                    'department_id' => $validated['department_id']
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

            1. LOCATE THE MAIN DOCUMENT:
            Search the OCR text for the stamped barcode. The text block containing this barcode is the MAIN document.

            2. IGNORE ROUTING SLIPS:
            Completely ignore any pages, headers, or text blocks labeled \"Referral Slip\", \"Routing Slip\", or \"Action Slip\". These are just attached transmittals. Do NOT extract names from these slips.

            3. IDENTIFY AND EXCLUDE RECIPIENTS:
            Before determining source_name, identify names associated with recipient/addressee fields such as:
            - TO:
            - THRU:
            - THROUGH:
            - ATTENTION:
            - ATTN:
            - FOR:
            - addressee blocks
            - recipient sections
            - salutations near the beginning of the document

            These names are RECIPIENTS and MUST NOT be used as source_name.

            4. IDENTIFY AND EXCLUDE BODY-ONLY NAMES:
            Identify names that appear only inside the document's body, subject, narrative, request, reference, quoted text, endorsement, explanation, or supporting content.

            These names are merely MENTIONED PERSONS and MUST NOT be used as source_name unless the same person also appears in a valid sender/signature block.

            IMPORTANT:
            A person's title, rank, position, importance, or government office does NOT make them the sender.

            A Mayor, Administrator, Director, Department Head, Officer, Employee, Applicant, Client, or any other named person mentioned in the document is NOT automatically the source.

            A body mention alone is NEVER sufficient evidence for source_name.

            5. EXTRACT TRUE SOURCE:
            source_name refers ONLY to the AUTHOR, SENDER, or SIGNATORY of the MAIN DOCUMENT.

            A valid source_name MUST have DIRECT SENDER EVIDENCE on the MAIN DOCUMENT.

            VALID sender evidence includes:
            - a printed/typed full name directly associated with a handwritten signature
            - a printed/typed full name directly associated with a digital signature
            - a printed/typed full name immediately above a position/designation in the signature block
            - a clearly labeled FROM: field
            - an equivalent sender/author block

            A typical signature block may look conceptually like:

            [signature]
            [PRINTED FULL NAME]
            [POSITION / DESIGNATION]
            [OFFICE / DEPARTMENT]

            The printed/typed name associated with that sender/signature block should be source_name.

            INVALID source_name evidence includes:
            - TO:
            - THRU:
            - THROUGH:
            - ATTENTION:
            - ATTN:
            - FOR:
            - recipient/addressee
            - salutation recipient
            - names appearing only in the subject
            - names appearing only inside body paragraphs
            - names appearing only inside requests or statements
            - names appearing only in references
            - names appearing only in quoted text
            - names appearing only in endorsements
            - names of officials merely being discussed
            - names of persons whose approval is being requested
            - names from Referral Slips
            - names from Routing Slips
            - names from Action Slips

            CRITICAL:
            Do NOT choose source_name by prominence, title, importance, frequency, or location in the OCR.

            The selected source_name MUST have positive sender/signatory evidence.

            6. FINAL SOURCE NAME VALIDATION:
            Before returning source_name, perform ALL of these checks:

            A. Identify the document recipient/addressee.
            B. Identify names appearing only inside the body/content.
            C. Identify names appearing only on routing/referral/action slips.
            D. Identify the person with DIRECT sender evidence from a signature block, FROM field, or equivalent sender block.
            E. Exclude A, B, and C from source_name candidates.
            F. source_name MUST come from D.
            G. If the proposed source_name appears only in the body and does not have direct sender/signature evidence, REJECT IT.
            H. If the proposed source_name is the recipient/addressee, REJECT IT.
            I. If the proposed source_name comes only from a routing/referral/action slip, REJECT IT.
            J. If no reliable sender/signatory can be identified, return null instead of guessing.
            
            Return ONLY a valid JSON object matching this strict structure:
            {
            '_thought_process': 'String. Step 1: Find barcode. Step 2: Identify the main document text and mentally separate it from any attached routing slips. Step 3: Identify and exclude recipient names. Step 4: Identify and exclude names that appear only in the document body. Step 5: Find the actual signature/sender block on the main document.',

            'decoy_memo_number': 'String or null. Extract Memorandum No. here if one exists (e.g., OT-402-2026).',

            'decoy_letterhead': 'String. Extract the massive Office Name at the top here.',

            'barcode': 'String. Extract the barcode quoted in your thought process. It MUST be 1 uppercase letter followed by numbers. CRITICAL: Correct OCR letter mixups (e.g., C001, C003).',

            'source_type': 'String. Strictly \"Internal\" or \"External\".',

            'source_location': 'If Internal, return the integer ID of the sending department. If External, return null.',

            'source_name': 'String or null. Extract ONLY the FULL NAME of the AUTHOR, SENDER, or SIGNATORY of the MAIN DOCUMENT. A valid source_name MUST have DIRECT SENDER EVIDENCE, such as a printed/typed name associated with a handwritten/digital signature, a signature block, a FROM: field, or an equivalent sender block. FIRST exclude all names associated with TO:, THRU:, THROUGH:, ATTENTION:, ATTN:, FOR:, recipients, addressees, and salutations. ALSO exclude names that appear ONLY inside the subject, body paragraphs, narrative content, requests, references, quoted text, endorsements, explanations, or supporting content. A person mentioned in the document body is NOT the source merely because they are an important official, office holder, approver, department head, director, administrator, mayor, employee, applicant, or other named person. A title or prominent position alone is NOT sender evidence. NEVER use names appearing only on Referral Slips, Routing Slips, or Action Slips. Prefer the printed/typed name directly associated with the signature and immediately above or near the sender\\'s position/designation. FINAL VALIDATION: If the selected source_name is the recipient, appears only inside the body/content, or lacks direct sender/signature evidence, REJECT it and inspect the actual signature/sender block again. If no reliable sender/signatory exists, return null instead of guessing.',

            'gender': 'String. Strictly \"Male\" or \"Female\". Infer from the sender\\'s title (Mr./Ms./Atty.) or first name.',

            'contact_no': 'String. Extract the sender\\'s phone or contact number if present. Return null if none.',

            'email': 'String. Extract the sender\\'s email address if present. Return null if none.',

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

            $userPrompt = "Match the details against these system options:\nDepartments: $departments\nDocument Types: $documentTypes\nTransaction Types:$transactionTypes\nClassifications: $classifications\nDelivery Methods:$deliveryMethods";

            $cleanText = substr(
                preg_replace('/\s+/', ' ', trim($request->extracted_text)),
                0,
                12000
            );
            
            $thumbnail = $request->file('ai_thumbnail');

            $base64Image = base64_encode(
                file_get_contents($thumbnail->path())
            );

            $mimeType = $thumbnail->getMimeType();

            $response = OpenAI::chat()->create([
                'model' => 'gpt-4o-mini',

                'response_format' => [
                    'type' => 'json_object'
                ],

                'messages' => [
                    [
                        'role' => 'system',
                        'content' => $systemInstruction
                    ],
                    [
                        'role' => 'user',
                        'content' => [
                            [
                                'type' => 'text',
                                'text' =>
                                    $userPrompt .
                                    "\n\nRaw Document OCR Text:\n" .
                                    $cleanText
                            ],
                            [
                                'type' => 'image_url',
                                'image_url' => [
                                    'url' =>
                                        "data:{$mimeType};base64,{$base64Image}",
                                    'detail' => 'low'
                                ]
                            ]
                        ]
                    ]
                ],

                'temperature' => 0.1,
            ]);

            $result = json_decode(
                $response->choices[0]->message->content,
                true
            );
            
            if (
                !empty($result['barcode'])
                && is_string($result['barcode'])
            ) {
                $cleanBarcode = preg_replace(
                    '/[-\s]/',
                    '',
                    $result['barcode']
                );
                
                if (
                    preg_match(
                        '/^([A-Z])([0-9]+)$/i',
                        $cleanBarcode,
                        $matches
                    )
                ) {
                    $letter = strtoupper($matches[1]);

                    $digits = str_pad(
                        $matches[2],
                        9,
                        '0',
                        STR_PAD_LEFT
                    );

                    $result['barcode'] = $letter . $digits;
                }
            }

            if (isset($response->usage)) {
                $result['meta'] = [
                    'total_tokens' => $response->usage->totalTokens,
                    'prompt_tokens' => $response->usage->promptTokens,
                    'completion_tokens' => $response->usage->completionTokens,
                ];
            }

            return response()->json($result);

        } catch (\Exception $e) {
            return response()->json([
                'error' =>
                    'Failed to analyze document: ' .
                    $e->getMessage()
            ], 500);
        }
    }

    // =====================================================================
    // CORE WORKFLOW & ROUTING MECHANICS 
    // =====================================================================

    public function routeDocument(Request $request, Document $document)
    {
        // Tier 1 Routing (Records distributing via the dual-panel modal)
        $validated = $request->validate([
            'routed_to' => 'required|array|min:1',
            'routed_to.*' => 'exists:departments,id',
        ]);

        $now = Carbon::now('Asia/Manila');
        $user = Auth::user();

        // 1. Close the active trail for Records
        $activeTrail = DocumentTrail::where('document_id', $document->id)
            ->where('department_id', $user->department_id)
            ->whereNull('released_at')
            ->latest('id')
            ->first();

        if ($activeTrail) {
            $activeTrail->update([
                'released_by' => $user->id,
                'released_at' => $now,
                'released_action' => 'Dispatched to target office(s)',
                'released_to' => $validated['routed_to'][0], // Logs primary physical target
                'trail_type' => 'forward',
                'is_digitally_signed' => false,
                'signed_by' => null,
            ]);
        }

        // 2. Fast Forward Distribution: Create auto-receive trails for EVERY selected office
        foreach ($validated['routed_to'] as $departmentId) {
            DocumentTrail::create([
                'document_id' => $document->id,
                'department_id' => $departmentId, 
                'received_at' => $now, // Target SLA clocks start instantly
                'received_action' => 'Dispatched from Records', // <-- FIXED BLANK BUG
            ]);
        }

        // Move the physical location to the primary target
        $document->update([
            'department_id' => $validated['routed_to'][0],
        ]);

        return redirect()->back()->with('success', 'Document successfully routed.');
    }

    // --- SMART SEARCH API ---
    public function searchBarcodes(Request $request)
    {
        $query = $request->get('q');
        
        if (!$query || strlen($query) < 2) {
            return response()->json([]);
        }

        $results = Document::where('barcode', 'LIKE', "%{$query}%")
                           ->select('barcode', 'subject_matter')
                           ->take(5) 
                           ->get();
                           
        return response()->json($results);
    }
}