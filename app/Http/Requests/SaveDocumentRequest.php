<?php

namespace App\Http\Requests;

use App\Models\Document;
use App\Models\DocumentType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class SaveDocumentRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if (is_int($this->input('source_location'))) {
            $this->merge(['source_location' => (string) $this->input('source_location')]);
        }

        // Omitted intake flags keep the document's current values (or the safe defaults on create).
        $document = $this->route('document');
        $this->merge([
            'is_confidential' => $this->input('is_confidential', $document instanceof Document ? $document->is_confidential : false),
            // With the paperless route switched off, every saved document takes the paper route.
            'route_mode' => config('services.paperless.enabled')
                ? $this->input('route_mode', $document instanceof Document ? $document->route_mode : 'paper')
                : 'paper',
        ]);
    }

    public function authorize(): bool
    {
        $document = $this->route('document');

        return $document instanceof Document
            ? ($this->user()?->can('update', $document) ?? false)
            : ($this->user()?->can('create', Document::class) ?? false);
    }

    public function rules(): array
    {
        $document = $this->route('document');

        return [
            'workflow_version' => $document ? ['required', 'integer', 'min:0'] : ['prohibited'],
            'barcode' => ['required', 'string', 'max:50', Rule::unique('documents', 'barcode')->ignore($document)],
            'transaction_type_id' => ['required', 'integer', Rule::exists('transaction_types', 'id')->where('state', 1)],
            'document_type_id' => ['required', 'integer', Rule::exists('document_types', 'id')->where('state', 1)],
            'source_type' => ['required', Rule::in(['Internal', 'External'])],
            'source_location' => ['nullable', 'string', 'max:255'],
            'source_name' => ['required', 'string', 'max:255'],
            'delivery_method_id' => ['nullable', 'integer', Rule::exists('delivery_methods', 'id')->where('state', 1)],
            'gender' => ['nullable', Rule::in(['Male', 'Female'])],
            'contact_no' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'subject_matter' => ['required', 'string'],
            'classification_id' => ['nullable', 'integer', Rule::exists('document_classifications', 'id')->where('state', 1)],
            'is_confidential' => ['required', 'boolean'],
            'route_mode' => ['required', Rule::in(['paperless', 'paper'])],
            // Intake goes either to ADM/OCM (department_id, opens their approval stage) or straight to departments.
            'department_id' => ['nullable', 'integer', Rule::exists('departments', 'id')->where('state', 1), 'prohibits:routed_to'],
            'routed_to' => $document ? ['prohibited'] : ['nullable', 'array', 'max:100'],
            'routed_to.*' => ['integer', 'distinct', Rule::exists('departments', 'id')->where('state', 1)->whereNotIn('code', ['REC', 'ADM', 'OCM'])],
            'primary_office_id' => $document ? ['prohibited'] : ['nullable', 'integer', Rule::in((array) $this->input('routed_to', [])), 'required_with:routed_to'],
            'access_code' => ['required', 'string', 'max:20'],
            'ai_routing_suggestions' => ['nullable', 'array', 'max:10'],
            'ai_routing_suggestions.*.department_id' => ['required', 'integer'],
            'ai_routing_suggestions.*.priority' => ['nullable', 'string', 'max:20'],
            'ai_routing_suggestions.*.reason' => ['nullable', 'string', 'max:1000'],
            'linked_documents' => ['nullable', 'string'],
            'attached_file' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
        ];
    }

    public function messages(): array
    {
        return [
            'department_id.prohibits' => 'Send the document either to an executive office or to departments, not both.',
            'routed_to.*.exists' => 'Choose active departments. The City Administrator and City Mayor are chosen in Direct to.',
            'primary_office_id.in' => 'Choose the Primary office from the selected offices.',
            'primary_office_id.required_with' => 'Choose the Primary office.',
        ];
    }

    /** Intake destination, then the FGD rulebook checks that depend on the selected document type. */
    public function after(): array
    {
        return [function (Validator $validator) {
            // A new document must leave intake with a destination: one left in the registering office reaches
            // no inbox and pings nobody (user decision 2026-10-30).
            if (! $this->route('document') && ! $validator->errors()->hasAny(['department_id', 'routed_to'])) {
                $target = $this->integer('department_id');
                if (! $target && empty($this->input('routed_to'))) {
                    $validator->errors()->add('routed_to', 'Choose where the document goes: the City Administrator, the City Mayor, or at least one department.');
                } elseif ($target && $target === (int) $this->user()?->department_id) {
                    $validator->errors()->add('department_id', 'Choose an office other than your own.');
                }
            }
        }, function (Validator $validator) {
            $type = DocumentType::with('classifications:id')->find($this->integer('document_type_id'));
            if (! $type || $validator->errors()->hasAny(['is_confidential', 'route_mode'])) {
                return;
            }

            $document = $this->route('document');
            $hasScan = $this->hasFile('attached_file') || ($document instanceof Document && $document->attached_file);
            $isConfidential = $this->boolean('is_confidential');

            if ($isConfidential && $hasScan) {
                $validator->errors()->add('attached_file', 'Confidential documents are not uploaded. Remove the scanned file.');
            }
            if ($isConfidential && ! empty($this->input('ai_routing_suggestions'))) {
                $validator->errors()->add('is_confidential', 'Confidential documents cannot use AI-assisted intake. Clear the AI results and encode the details manually.');
            }

            if ($this->input('route_mode') === 'paperless') {
                if (! $type->allowsPaperless($isConfidential)) {
                    $validator->errors()->add('route_mode', $isConfidential
                        ? 'Confidential documents always take the paper route.'
                        : "{$type->document_type} needs a handwritten signature, so it takes the paper route.");
                } elseif (! $hasScan) {
                    $validator->errors()->add('attached_file', 'Paperless documents need the scanned copy before they are transmitted.');
                }
            }

            $classificationId = $this->integer('classification_id');
            if ($classificationId && $type->classifications->isNotEmpty() && ! $type->classifications->contains('id', $classificationId)) {
                $validator->errors()->add('classification_id', "This classification is not used for {$type->document_type}.");
            }
        }];
    }
}
