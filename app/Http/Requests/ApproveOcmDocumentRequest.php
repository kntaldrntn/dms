<?php

namespace App\Http\Requests;

class ApproveOcmDocumentRequest extends DocumentMutationRequest
{
    protected string $ability = 'refer';

    public function rules(): array
    {
        return parent::rules() + [
            'stamp_placement' => ['required', 'array:page,x,y,width,height'],
            'stamp_placement.page' => ['required', 'integer', 'min:1', 'max:100'],
            'stamp_placement.x' => ['required', 'numeric', 'min:0', 'max:950'],
            'stamp_placement.y' => ['required', 'numeric', 'min:0', 'max:950'],
            'stamp_placement.width' => ['required', 'numeric', 'min:180', 'max:500'],
            'stamp_placement.height' => ['required', 'numeric', 'min:70', 'max:300'],
        ];
    }
}
