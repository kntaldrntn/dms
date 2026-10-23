<?php

namespace App\Http\Requests;

class ReceiveAdmDocumentRequest extends DocumentMutationRequest
{
    protected string $ability = 'receiveAdm';
}
