<?php

namespace App\Http\Requests;

use App\Models\PiggyBank;
use Illuminate\Validation\Rule;

/**
 * @property PiggyBank $piggy_bank
 */
class PiggyBankRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'name' => [
                'required',
                'min:' . self::DEFAULT_STRING_MIN_LENGTH,
                'max:' . self::DEFAULT_STRING_MAX_LENGTH,
                Rule::unique('piggy_banks')->where(fn ($query) => $query
                    ->where('user_id', $this->user()->id)
                    ->when($this->piggy_bank, fn ($query) => $query->where('id', '!=', $this->piggy_bank->id))),
            ],
            'target_amount' => [
                'required',
                'numeric',
                'min:1',
            ],
            'current_amount' => [
                'nullable',
                'numeric',
                'min:0',
            ],
            'start_date' => [
                'nullable',
                'date',
            ],
            'target_date' => [
                'nullable',
                'date',
                'after_or_equal:start_date',
            ],
            'notes' => [
                'nullable',
                'string',
                'max:65535',
            ],
            'active' => [
                'boolean',
            ],
        ];
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'active' => $this->active ?? 0,
            'current_amount' => $this->current_amount ?? 0,
        ]);
    }
}
