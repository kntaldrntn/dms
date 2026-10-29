<?php

namespace App\Http\Requests;

class ReceiveOcmDocumentRequest extends DocumentMutationRequest
{
    protected string $ability = 'receiveOcm';
}
