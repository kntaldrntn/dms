<?php

namespace Database\Seeders;

use App\Models\DocumentClassification;
use App\Models\DocumentType;
use App\Models\TransactionType;
use Illuminate\Database\Seeder;

/**
 * FGD document-type rulebook as answered by the Office of the City Administrator in the
 * "Document Management System Sheets" Google Sheet (tabs Rulebook and Classifications, 2026-10-21).
 * Blank answers in the sheet stay null/false here; they are editable in the Document Types registry.
 */
class DocumentTypeRulebookSeeder extends Seeder
{
    /** document_code => [signature_type, may_be_confidential, default category, remarks] */
    private const RULEBOOK = [
        'AOC' => ['handwritten', false, 'Simple Transaction', null],
        'AR' => ['handwritten', false, 'Simple Transaction', null],
        'BR' => ['handwritten', false, 'Simple Transaction', null],
        'COM' => ['digital', true, null, 'Can be simple, complex or highly technical (depends on content)'],
        'VC' => ['handwritten', false, null, 'Simple (Petty Cash/AICS) or Complex (Procurement)'],
        'IPW' => ['handwritten', false, 'Highly Technical Transaction', 'With extension'],
        'OBR' => ['handwritten', false, 'Simple Transaction', null],
        'PY' => ['handwritten', false, 'Complex Transaction', null],
        'PRMT' => [null, false, 'Simple Transaction', 'Signature to be consulted'],
        'PP' => ['digital', true, null, 'Either Complex or Highly Technical'],
        'PO' => ['handwritten', false, 'Simple Transaction', null],
        'PR' => ['handwritten', false, 'Simple Transaction', null],
    ];

    /** classification name => document codes that may carry it */
    private const CLASSIFICATIONS = [
        'AICS' => ['COM', 'VC', 'PY'],
        'Barangay/SK Resolution' => ['COM'],
        'Capacity Development' => ['COM'],
        'Complaint' => ['COM'],
        'Financial' => ['AOC', 'AR', 'BR', 'COM', 'VC', 'IPW', 'OBR', 'PY', 'PP', 'PO', 'PR'],
        'For Signature' => ['COM', 'PRMT'],
        'Human Resource' => ['COM'],
        'Invitation' => ['COM'],
        'Issuances' => ['COM'],
        'Legal Document' => ['COM'],
        'Legislative Document' => ['COM'],
        'Project Proposal' => ['COM', 'PP'],
        'Report' => ['COM'],
        'Request' => ['COM'],
        'Request for City Govt Properties' => ['COM'],
        'Request for Data' => ['COM'],
        'Request for OT' => ['COM'],
        'Special Mayors Permit' => ['PRMT'],
    ];

    public function run(): void
    {
        $categoryIds = TransactionType::pluck('id', 'name');
        $classificationIds = DocumentClassification::pluck('id', 'name');

        foreach (self::RULEBOOK as $code => [$signature, $mayBeConfidential, $category, $remarks]) {
            $type = DocumentType::where('document_code', $code)->first();

            if (! $type) {
                continue;
            }

            $type->update([
                'signature_type' => $signature,
                'may_be_confidential' => $mayBeConfidential,
                'default_transaction_type_id' => $category ? $categoryIds->get($category) : null,
                'rulebook_remarks' => $remarks,
            ]);

            $type->classifications()->sync(
                collect(self::CLASSIFICATIONS)
                    ->filter(fn (array $codes) => in_array($code, $codes, true))
                    ->keys()
                    ->map(fn (string $name) => $classificationIds->get($name))
                    ->filter()
                    ->values()
                    ->all()
            );
        }
    }
}
