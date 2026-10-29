<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * City Administrator's single approval (FGD Phase 2c) and route on, to the City Mayor or straight to departments.
 * Paperless: certificate stamp. Paper: wet signature; the scan of the signed copy is optional.
 */
class ApproveAdmDocumentRequest extends DocumentMutationRequest
{
    protected string $ability = 'approveAdm';

    public function rules(): array
    {
        $paperless = $this->route('document')->isPaperless();

        return parent::rules() + [
            'target' => ['required', Rule::in(['ocm', 'departments'])],
            'routed_to' => ['exclude_unless:target,departments', 'required', 'array', 'min:1', 'max:100'],
            'routed_to.*' => ['integer', 'distinct', Rule::exists('departments', 'id')->where('state', 1)->whereNotIn('code', ['REC', 'ADM', 'OCM'])],
            'primary_office_id' => ['exclude_unless:target,departments', 'required', 'integer', Rule::in((array) $this->input('routed_to', []))],
            'remarks' => ['nullable', 'string', 'max:1000'],
            'signed_scan' => $paperless ? ['prohibited'] : ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
            // Opens the City Administrator's own certificate for this signing; never stored or flashed.
            'certificate_pin' => $paperless ? ['required', 'string', 'max:100'] : ['prohibited'],
        ] + ($paperless ? ApproveOcmDocumentRequest::placementRules() : ['stamp_placement' => ['prohibited']]);
    }

    public function after(): array
    {
        return [fn (Validator $validator) => ApproveOcmDocumentRequest::ensureFitsOnPage($validator)];
    }

    public function messages(): array
    {
        return [
            'routed_to.*.exists' => 'Choose active departments. Use "City Mayor" to send it to OCM.',
            'primary_office_id.in' => 'Choose the Primary office from the selected offices.',
        ];
    }
}
