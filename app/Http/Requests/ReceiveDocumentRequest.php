<?php

namespace App\Http\Requests;

use Illuminate\Validation\Validator;

/**
 * The Primary office's receiving officer logs that the document arrived. Receiving changes no routing, so like a
 * copy receipt it needs no stale-form check; the service refuses a second receipt.
 *
 * One click is for paperless documents only: a paper document is received by scanning its barcode
 * (ReceivePaperDocumentRequest), so the inbox never shows it as received before the paper is in hand.
 */
class ReceiveDocumentRequest extends DocumentMutationRequest
{
    protected string $ability = 'receive';

    public function rules(): array
    {
        return [];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if (! $this->route('document')->isPaperless()) {
                $validator->errors()->add('document', 'This is a paper document: receive it by scanning the barcode on the physical document.');
            }
        }];
    }
}
