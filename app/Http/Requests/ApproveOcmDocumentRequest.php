<?php

namespace App\Http\Requests;

use Illuminate\Validation\Validator;

class ApproveOcmDocumentRequest extends DocumentMutationRequest
{
    protected string $ability = 'refer';

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

    public function rules(): array
    {
        // The PIN opens the Mayor's own certificate for this signing; never stored or flashed.
        return parent::rules() + self::placementRules() + ['certificate_pin' => ['required', 'string', 'max:100']];
    }

    public function after(): array
    {
        return [fn (Validator $validator) => self::ensureFitsOnPage($validator)];
    }
}
