<?php
namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PaperlessReconcileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null; // only logged-in users
    }

    public function rules(): array
    {
        return [
            'doc_id' => ['required', 'string'],
            'account_id' => ['required', 'integer'],
            'start' => ['required', 'date'],
            'end' => ['required', 'date'],
        ];
    }

    public function messages(): array
    {
        return [
            'doc_id.required' => 'Document id is required.',
            'account_id.required' => 'Please select an account to generate the CSV from.',
            'start.required' => 'Start date is required.',
            'end.required' => 'End date is required.',
        ];
    }
}
