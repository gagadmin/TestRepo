<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Shared normalisation and validation for the user access profile.
 *
 * Create and update are separate Form Requests by design, but the access
 * profile must behave identically in both: a department typed with stray
 * whitespace on the create screen has to end up the same string the visibility
 * scopes compare against on the update screen. Keeping the rules in one place
 * is what stops the two drifting apart.
 */
final class AccessProfileInput
{
    /**
     * @return array{allowed_departments: list<string>|null, allowed_data_source_ids: list<int>|null}
     */
    public static function normalize(FormRequest $request): array
    {
        return [
            'allowed_departments' => self::departments($request),
            'allowed_data_source_ids' => self::dataSourceIds($request),
        ];
    }

    /**
     * @return array<string, list<string>>
     */
    public static function rules(): array
    {
        return [
            // Nullable, not required: an absent profile means "fall back to the
            // single department label", which is what pre-existing accounts do.
            'allowed_departments' => ['nullable', 'array', 'max:30'],
            'allowed_departments.*' => ['string', 'max:120'],

            // Null and [] differ. Null leaves platform access to the per-source
            // rules; an empty array permits no platform at all.
            'allowed_data_source_ids' => ['nullable', 'array', 'max:100'],
            'allowed_data_source_ids.*' => ['integer', Rule::exists('data_sources', 'id')],
        ];
    }

    /**
     * @return list<string>|null
     */
    private static function departments(FormRequest $request): ?array
    {
        $input = $request->input('allowed_departments');

        if (! is_array($input)) {
            return null;
        }

        return collect($input)
            ->filter(fn (mixed $department) => is_string($department) && trim($department) !== '')
            ->map(fn (string $department) => trim($department))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return list<int>|null
     */
    private static function dataSourceIds(FormRequest $request): ?array
    {
        $input = $request->input('allowed_data_source_ids');

        if (! is_array($input)) {
            return null;
        }

        return collect($input)
            ->filter(fn (mixed $id) => is_numeric($id))
            ->map(fn (mixed $id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }
}
