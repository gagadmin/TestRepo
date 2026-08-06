<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SecurityEventUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('security.manage') ?? false;
    }

    public function rules(): array
    {
        return [
            'status' => ['required', Rule::in(['open', 'acknowledged', 'resolved', 'false_positive'])],
            'resolution_note' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return [
            'status.in' => 'Status must be open, acknowledged, resolved, or false_positive.',
        ];
    }

    public function after(): array
    {
        return [
            function ($validator) {
                $status = $this->input('status');
                $note = trim((string) $this->input('resolution_note'));

                // Closing a finding must be accountable: require a reason.
                if (in_array($status, ['resolved', 'false_positive'], true) && $note === '') {
                    $validator->errors()->add(
                        'resolution_note',
                        'Explain how this finding was resolved or why it is a false positive.',
                    );
                }
            },
        ];
    }
}
