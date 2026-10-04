<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

class AccountSummaryRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        return [
            'date_from' => ['nullable', 'date'],
            'date_to' => ['required', 'date', Rule::when($this->filled('date_from'), 'after_or_equal:date_from')],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (! $this->filled('date_to')) {
            $this->merge(['date_to' => now()->toDateString()]);
        }
    }
}
