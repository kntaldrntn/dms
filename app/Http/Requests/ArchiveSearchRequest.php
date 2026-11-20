<?php

namespace App\Http\Requests;

use App\Models\Document;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** The Archive's filters: one keyword box plus dropdowns and a received-date range, all combinable. */
class ArchiveSearchRequest extends FormRequest
{
    public const PER_PAGE = [10, 25, 50, 100];

    public const STATUSES = ['ongoing', 'completed'];

    public function authorize(): bool
    {
        return $this->user()->can('viewAny', Document::class);
    }

    public function rules(): array
    {
        return [
            'keyword' => ['nullable', 'string', 'max:100'],
            'document_type_id' => ['nullable', 'integer', 'exists:document_types,id'],
            'transaction_type_id' => ['nullable', 'integer', 'exists:transaction_types,id'],
            'classification_id' => ['nullable', 'integer', 'exists:document_classifications,id'],
            'delivery_method_id' => ['nullable', 'integer', 'exists:delivery_methods,id'],
            'office_id' => ['nullable', 'integer', 'exists:departments,id'],
            'source_type' => ['nullable', Rule::in(['Internal', 'External'])],
            'status' => ['nullable', Rule::in(self::STATUSES)],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'per_page' => ['nullable', 'integer', Rule::in(self::PER_PAGE)],
        ];
    }

    public function messages(): array
    {
        return ['to.after_or_equal' => 'The "To" date must be on or after the "From" date.'];
    }

    public function perPage(): int
    {
        return $this->integer('per_page') ?: self::PER_PAGE[0];
    }

    /** @return array<string, mixed> Only the filters that were filled in, echoed back to the page. */
    public function filters(): array
    {
        return array_filter(
            $this->safe()->except('per_page'),
            fn ($value) => $value !== null && $value !== '',
        );
    }
}
