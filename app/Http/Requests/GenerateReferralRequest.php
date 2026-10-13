<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

class GenerateReferralRequest extends DocumentMutationRequest
{
    protected string $ability = 'refer';

    public function rules(): array
    {
        return parent::rules() + [
            'targets' => ['required', 'array', 'min:1', 'max:100'],
            'targets.*' => ['required', 'integer', 'distinct', Rule::exists('departments', 'id')->where('state', 1), Rule::notIn([$this->route('document')->department_id])],
            'remarks' => ['nullable', 'string', 'max:1000'],
            'required_actions' => ['required', 'array', 'min:1', 'max:20'],
            'required_actions.*' => ['required', 'string', 'distinct', Rule::in([
                'for_approval', 'for_signature', 'for_information_reference', 'for_appropriate_action',
                'for_review_evaluation', 'lets_discuss_see_me', 'please_attend', 'please_handle',
                'for_dissemination', 'return_documents_to_me', 'for_comment_recommendation', 'other',
            ])],
            'other_action' => ['nullable', Rule::requiredIf(fn () => in_array('other', $this->input('required_actions', []), true)), 'string', 'max:255'],
            'concerned_personnel' => ['nullable', 'string', 'max:255'],
            'attach_signature' => ['sometimes', 'boolean'],
            'stamp_placement' => ['nullable', 'array:page,x,y,width,height'],
            'stamp_placement.page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'stamp_placement.x' => ['nullable', 'numeric', 'min:0', 'max:950'],
            'stamp_placement.y' => ['nullable', 'numeric', 'min:0', 'max:950'],
            'stamp_placement.width' => ['nullable', 'numeric', 'min:80', 'max:500'],
            'stamp_placement.height' => ['nullable', 'numeric', 'min:30', 'max:300'],
        ];
    }
}
