<?php

namespace App\Services;

use App\Models\DeliveryMethod;
use App\Models\Department;
use App\Models\DocumentClassification;
use App\Models\DocumentType;
use App\Models\TransactionType;
use Illuminate\Http\UploadedFile;
use OpenAI\Laravel\Facades\OpenAI;
use RuntimeException;

class DocumentAnalysisService
{
    public function analyze(string $extractedText, UploadedFile $thumbnail): array
    {
        $departments = Department::where('state', 1)->pluck('name', 'id')->toJson();
        $documentTypes = DocumentType::where('state', 1)->pluck('document_type', 'id')->toJson();
        $transactionTypes = TransactionType::where('state', 1)->pluck('name', 'id')->toJson();
        $classifications = DocumentClassification::where('state', 1)->pluck('name', 'id')->toJson();
        $deliveryMethods = DeliveryMethod::where('state', 1)->pluck('delivery_method_name', 'id')->toJson();
        $administratorId = Department::where('code', 'ADM')->where('state', 1)->value('id') ?? 'null';
        $mayorId = Department::where('code', 'OCM')->where('state', 1)->value('id') ?? 'null';

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

            'department_id': 'Integer or null. Strictly return {$mayorId} (Office of the City Mayor), {$administratorId} (Office of the City Administrator), or null (Other Departments). ONLY use these configured office IDs if the document is explicitly ADDRESSED TO or REQUIRES APPROVAL FROM them. CRITICAL: Do NOT route it to them if they are the ones SENDING the document. If it is going to a regular department, return null.',

            'approval_reason': 'String or null. If you selected the Administrator or Mayor office, briefly explain why.',

            'routing_suggestions': [
                {
                'department_id': 'Integer ID',
                'priority': 'String. Strictly output Primary, Secondary, or FYI.',
                'reason': 'Detailed explanation'
                }
            ],

            'NOTE_FOR_ROUTING': 'Provide 1 to 4 highly relevant routing suggestions. CRITICAL: DO NOT guess or invent hypothetical scenarios. Assign EXACTLY ONE office as Primary (the main executor of the request). Any other offices must be Secondary or FYI (like Budget/Accounting for records). STRICT RULE: If you selected an executive office for the main department_id, DO NOT include it again here.',

            'confidence_score': 'Integer between 1 and 100.'
            }";

        $userPrompt = "Match the details against these system options:\nDepartments: $departments\nDocument Types: $documentTypes\nTransaction Types:$transactionTypes\nClassifications: $classifications\nDelivery Methods:$deliveryMethods";

        $cleanText = substr(
            preg_replace('/\s+/', ' ', trim($extractedText)),
            0,
            12000
        );

        $base64Image = base64_encode(
            file_get_contents($thumbnail->path())
        );

        $mimeType = $thumbnail->getMimeType();

        $response = OpenAI::chat()->create([
            'model' => 'gpt-4o-mini',

            'response_format' => [
                'type' => 'json_object',
            ],

            'messages' => [
                [
                    'role' => 'system',
                    'content' => $systemInstruction,
                ],
                [
                    'role' => 'user',
                    'content' => [
                        [
                            'type' => 'text',
                            'text' => $userPrompt.
                                "\n\nRaw Document OCR Text:\n".
                                $cleanText,
                        ],
                        [
                            'type' => 'image_url',
                            'image_url' => [
                                'url' => "data:{$mimeType};base64,{$base64Image}",
                                'detail' => 'low',
                            ],
                        ],
                    ],
                ],
            ],

            'temperature' => 0.1,
        ]);

        $result = json_decode(
            $response->choices[0]->message->content,
            true, 512, JSON_THROW_ON_ERROR
        );

        if (! is_array($result) || array_is_list($result)) {
            throw new RuntimeException('Analysis did not return a JSON object.');
        }

        if (
            ! empty($result['barcode'])
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

                $result['barcode'] = $letter.$digits;
            }
        }

        if (isset($response->usage)) {
            $result['meta'] = [
                'total_tokens' => $response->usage->totalTokens,
                'prompt_tokens' => $response->usage->promptTokens,
                'completion_tokens' => $response->usage->completionTokens,
            ];
        }

        return $result;
    }
}
