<?php

namespace App\Http\Requests;

/**
 * The Primary office's receiving officer logs that the document arrived. Receiving changes no routing, so like a
 * copy receipt it needs no stale-form check; the service refuses a second receipt.
 */
class ReceiveDocumentRequest extends DocumentMutationRequest
{
    protected string $ability = 'receive';

    public function rules(): array
    {
        return [];
    }
}
