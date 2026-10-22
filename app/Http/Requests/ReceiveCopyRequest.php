<?php

namespace App\Http\Requests;

/** A copy office acknowledges its view-only copy. Copies never act, so no stale-form check is needed. */
class ReceiveCopyRequest extends DocumentMutationRequest
{
    protected string $ability = 'receiveCopy';

    public function rules(): array
    {
        return [];
    }
}
