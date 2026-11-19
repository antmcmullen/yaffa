<?php

namespace App\Http\Requests\API;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PortfolioValuationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $query = $this->query->all();

        if (array_key_exists('account_ids', $query) && $query['account_ids'] === null) {
            $this->merge(['account_ids' => []]);
        }
    }

    public function rules(): array
    {
        return [
            'account_ids' => ['present', 'array', 'list'],
            'account_ids.*' => ['required', 'integer', 'distinct'],
            'currency_id' => [
                'required',
                'integer',
                Rule::exists('currencies', 'id')
                    ->where(fn ($query) => $query->where('user_id', $this->user()->id)),
            ],
            'dates' => ['required', 'array', 'list', 'min:1', 'max:31'],
            'dates.*' => ['required', 'date_format:Y-m-d', 'distinct'],
        ];
    }
}
