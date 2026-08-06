<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UserAccessRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('users.manage') ?? false;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => trim((string) $this->input('name')),
            'email' => strtolower(trim((string) $this->input('email'))),
            'department' => filled($this->input('department'))
                ? trim((string) $this->input('department'))
                : null,
            'title' => filled($this->input('title'))
                ? trim((string) $this->input('title'))
                : null,
            'roles' => collect($this->input('roles', []))
                ->filter(fn (mixed $role) => is_string($role))
                ->unique()
                ->values()
                ->all(),
        ]);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'email:rfc',
                'max:255',
                Rule::unique('users', 'email')->ignore($this->route('user')),
            ],
            'department' => ['nullable', 'string', 'max:120'],
            'title' => ['nullable', 'string', 'max:120'],
            'is_active' => ['required', 'boolean'],
            'roles' => ['required', 'array', 'min:1', 'max:4'],
            'roles.*' => ['required', 'string', Rule::exists('roles', 'name')],
        ];
    }
}
