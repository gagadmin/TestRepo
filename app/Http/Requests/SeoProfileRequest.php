<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SeoProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('seo.view') ?? false;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'categories' => $this->cleanList($this->input('categories', [])),
            'brand_terms' => $this->cleanList($this->input('brand_terms', [])),
            'competitor_seeds' => $this->cleanList($this->input('competitor_seeds', [])),
        ]);
    }

    /**
     * Accept an array or a comma-separated string; return a trimmed, unique list.
     *
     * @return array<int, string>
     */
    private function cleanList(mixed $value): array
    {
        $items = is_string($value) ? explode(',', $value) : (array) $value;

        return collect($items)
            ->map(fn ($item) => trim((string) $item))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    public function rules(): array
    {
        return [
            'categories' => ['array', 'max:20'],
            'categories.*' => ['string', 'max:80'],

            'regions' => ['array', 'max:20'],
            'regions.*.name' => ['required_with:regions', 'string', 'max:80'],
            'regions.*.code' => ['nullable', 'string', 'max:3', 'regex:/^[A-Za-z]{2,3}$/'],

            'brand_terms' => ['array', 'max:30'],
            'brand_terms.*' => ['string', 'max:80'],

            'competitor_seeds' => ['array', 'max:30'],
            'competitor_seeds.*' => ['string', 'max:120', 'regex:/^[a-z0-9.-]+$/i'],
        ];
    }

    public function messages(): array
    {
        return [
            'competitor_seeds.*.regex' => 'Enter bare domains such as competitor.com.',
            'regions.*.code.regex' => 'Region code must be a 2–3 letter country code (e.g. AE).',
        ];
    }
}
