<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class ApproveOcmDocumentRequest extends DocumentMutationRequest
{
    protected string $ability = 'approveOcm';

    /** Placement is normalized 0-1000 from the page's top-left corner. */
    public static function placementRules(): array
    {
        return [
            'stamp_placement' => ['required', 'array:page,x,y,width,height'],
            'stamp_placement.page' => ['required', 'integer', 'min:1', 'max:100'],
            'stamp_placement.x' => ['required', 'numeric', 'min:0', 'max:1000'],
            'stamp_placement.y' => ['required', 'numeric', 'min:0', 'max:1000'],
            'stamp_placement.width' => ['required', 'numeric', 'min:250', 'max:500'],
            'stamp_placement.height' => ['required', 'numeric', 'min:70', 'max:300'],
        ];
    }

    /** Adds an error when the rectangle would run past the page edge. */
    public static function ensureFitsOnPage(Validator $validator): void
    {
        $placement = $validator->getData()['stamp_placement'] ?? null;
        if ($validator->errors()->isNotEmpty() || ! is_array($placement)) {
            return;
        }
        if ($placement['x'] + $placement['width'] > 1000 || $placement['y'] + $placement['height'] > 1000) {
            $validator->errors()->add('stamp_placement', 'The signature stamp must fit inside the page.');
        }
    }

    /**
     * FGD Phase 3: the City Mayor's approval routes straight to the departments (Primary + view-only copies).
     * Paperless: certificate stamp. Paper: wet signature recorded by OCM; the scan of the signed copy is optional.
     */
    public function rules(): array
    {
        $paperless = $this->route('document')->isPaperless();

        return parent::rules() + [
            'routed_to' => ['required', 'array', 'min:1', 'max:100'],
            'routed_to.*' => ['integer', 'distinct', Rule::exists('departments', 'id')->where('state', 1)->whereNotIn('code', ['REC', 'ADM', 'OCM'])],
            'primary_office_id' => ['required', 'integer', Rule::in((array) $this->input('routed_to', []))],
            'remarks' => ['nullable', 'string', 'max:1000'],
            'signed_scan' => $paperless ? ['prohibited'] : ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
            // The PIN opens the Mayor's own certificate for this signing; never stored or flashed.
            'certificate_pin' => $paperless ? ['required', 'string', 'max:100'] : ['prohibited'],
        ] + ($paperless ? self::placementRules() : ['stamp_placement' => ['prohibited']]);
    }

    public function after(): array
    {
        return [fn (Validator $validator) => self::ensureFitsOnPage($validator)];
    }

    public function messages(): array
    {
        return [
            'routed_to.required' => 'Choose the departments that act on the approved document.',
            'routed_to.*.exists' => 'Choose active departments.',
            'primary_office_id.in' => 'Choose the Primary office from the selected offices.',
        ];
    }
}
