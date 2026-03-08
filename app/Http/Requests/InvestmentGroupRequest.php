<?php

namespace App\Http\Requests;

use App\Models\InvestmentGroup;
use Illuminate\Validation\Rule;

/**
 * @property InvestmentGroup $investmentGroup
 */
class InvestmentGroupRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     * Pass ID to unique check, if it exists in request
     */
    public function rules(): array
    {
        return [
            'name' => [
                'required',
                'min:' . self::DEFAULT_STRING_MIN_LENGTH,
                'max:' . self::DEFAULT_STRING_MAX_LENGTH,
                Rule::unique('investment_groups')->where(function ($query) {
                    $query->where('user_id', $this->user()->id);

                    // When updating, exclude the current investment group based on route parameter
                    $investmentGroup = $this->route('investment_group') ?? $this->route('investmentGroup');
                    if ($investmentGroup) {
                        $query->where('id', '!=', $investmentGroup->id);
                    }
                }),
            ],
            'auto_invest' => ['boolean'],
        ];
    }
}
