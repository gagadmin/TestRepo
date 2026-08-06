<?php

namespace App\Http\Requests;

use App\Services\Integrations\IntegrationUrlGuard;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use InvalidArgumentException;

class DataSourceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('integrations.manage') ?? false;
    }

    protected function prepareForValidation(): void
    {
        if ($this->input('type') !== 'google_search_console') {
            return;
        }

        $settings = $this->input('settings', []);

        if (! is_array($settings)) {
            return;
        }

        unset($settings['health_path'], $settings['data_path']);

        $siteUrl = trim((string) ($settings['site_url'] ?? ''));
        $parts = str_starts_with($siteUrl, 'sc-domain:') ? false : parse_url($siteUrl);

        if (is_array($parts)
            && ($parts['path'] ?? '') === ''
            && ! isset($parts['query'])
            && ! isset($parts['fragment'])) {
            $settings['site_url'] = rtrim($siteUrl, '/').'/';
        }

        $this->merge(['settings' => $settings]);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'type' => ['required', Rule::in(array_keys(config('integrations.types')))],
            'description' => ['nullable', 'string', 'max:1000'],
            'base_url' => ['required', 'url:http,https', 'max:500'],
            'auth_type' => ['required', Rule::in(['none', 'bearer', 'api_key', 'basic'])],
            'credentials' => ['sometimes', 'nullable', 'array'],
            'credentials.token' => ['nullable', 'string', 'max:4000'],
            'credentials.api_key' => ['nullable', 'string', 'max:4000'],
            'credentials.header' => ['nullable', 'string', 'max:100'],
            'credentials.username' => ['nullable', 'string', 'max:255'],
            'credentials.password' => ['nullable', 'string', 'max:4000'],
            'headers' => ['sometimes', 'nullable', 'array', 'max:20'],
            'headers.*' => ['nullable', 'string', 'max:1000'],
            'settings' => ['sometimes', 'array'],
            'settings.health_path' => ['nullable', 'string', 'max:500', 'starts_with:/'],
            'settings.data_path' => ['nullable', 'string', 'max:500', 'starts_with:/'],
            'settings.site_url' => ['nullable', 'string', 'max:500'],
            'settings.mapping' => ['nullable', 'array', 'max:100'],
            'settings.allowed_roles' => ['nullable', 'array', 'max:10'],
            'settings.allowed_roles.*' => ['string', Rule::in(['executive', 'manager', 'analyst'])],
            'settings.allowed_departments' => ['nullable', 'array', 'max:30'],
            'settings.allowed_departments.*' => ['string', 'max:120'],
            'settings.on_hold_status_ids' => ['nullable', 'array', 'max:20'],
            'settings.on_hold_status_ids.*' => ['integer', 'min:1'],
            'timeout_seconds' => ['required', 'integer', 'between:1,60'],
            'retry_count' => ['required', 'integer', 'between:0,5'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                try {
                    app(IntegrationUrlGuard::class)->assertAllowed((string) $this->input('base_url'));
                } catch (InvalidArgumentException $exception) {
                    $validator->errors()->add('base_url', $exception->getMessage());
                }

                foreach ($this->input('headers', []) ?? [] as $name => $value) {
                    if (! is_string($name) || ! preg_match('/^[A-Za-z0-9][A-Za-z0-9-]*$/', $name)) {
                        $validator->errors()->add('headers', 'Custom header names may contain only letters, numbers, and hyphens.');
                    }

                    if (in_array(strtolower((string) $name), [
                        'host', 'content-length', 'transfer-encoding', 'connection',
                        'forwarded', 'x-forwarded-for', 'x-forwarded-host', 'x-forwarded-proto',
                    ], true)) {
                        $validator->errors()->add('headers', "The {$name} header cannot be overridden.");
                    }

                    if (is_string($value) && preg_match('/[\r\n]/', $value)) {
                        $validator->errors()->add('headers', 'Custom header values cannot contain line breaks.');
                    }
                }

                $apiKeyHeader = data_get($this->input('credentials', []), 'header');

                if ($apiKeyHeader !== null && ! $this->isSafeHeaderName((string) $apiKeyHeader)) {
                    $validator->errors()->add(
                        'credentials.header',
                        'Select a safe API key header name.'
                    );
                }

                $authType = $this->string('auth_type')->toString();
                $isSearchConsole = $this->string('type')->toString() === 'google_search_console';

                if ($isSearchConsole
                    && $this->string('base_url')->toString() !== 'https://www.googleapis.com/webmasters/v3') {
                    $validator->errors()->add(
                        'base_url',
                        'Google Search Console sources must use the approved Google API URL.'
                    );
                }

                $siteUrl = trim((string) $this->input('settings.site_url'));

                if ($isSearchConsole && $siteUrl === '') {
                    $validator->errors()->add(
                        'settings.site_url',
                        'The exact Search Console property is required.'
                    );
                } elseif ($isSearchConsole
                    && ! str_starts_with($siteUrl, 'sc-domain:')
                    && filter_var($siteUrl, FILTER_VALIDATE_URL) === false) {
                    $validator->errors()->add(
                        'settings.site_url',
                        'Use an exact URL-prefix or sc-domain Search Console property.'
                    );
                }

                if ($isSearchConsole && $authType !== 'none') {
                    $validator->errors()->add(
                        'auth_type',
                        'Google Search Console authentication is managed by the server-side service account.'
                    );
                }

                if ($isSearchConsole && $this->filled('settings.health_path')) {
                    $validator->errors()->add(
                        'settings.health_path',
                        'Google Search Console does not use a custom health endpoint.'
                    );
                }

                if ($isSearchConsole && $this->filled('settings.data_path')) {
                    $validator->errors()->add(
                        'settings.data_path',
                        'Google Search Console uses its approved Search Analytics endpoint.'
                    );
                }

                $credentials = $this->input('credentials');

                if ($isSearchConsole && is_array($credentials) && array_filter($credentials) !== []) {
                    $validator->errors()->add(
                        'credentials',
                        'Search Console credentials must remain in the server-side credential file.'
                    );
                }
                $source = $this->route('dataSource');
                $authChanged = $source
                    && $source->apiConfiguration?->auth_type !== $authType;
                $credentialsRequired = $this->isMethod('post') || $authChanged;

                if ($authType !== 'none' && $credentialsRequired && empty($credentials)) {
                    $validator->errors()->add('credentials', 'Credentials are required for the selected authentication method.');
                }

                if (! is_array($credentials) || $credentials === []) {
                    return;
                }

                $requiredKeys = match ($authType) {
                    'bearer' => ['token'],
                    'api_key' => ['api_key'],
                    'basic' => ['username', 'password'],
                    default => [],
                };

                foreach ($requiredKeys as $key) {
                    if (empty($credentials[$key])) {
                        $validator->errors()->add(
                            "credentials.{$key}",
                            ucfirst(str_replace('_', ' ', $key)).' is required.'
                        );
                    }
                }
            },
        ];
    }

    private function isSafeHeaderName(string $name): bool
    {
        return (bool) preg_match('/^[A-Za-z0-9][A-Za-z0-9-]*$/', $name)
            && ! in_array(strtolower($name), [
                'host', 'content-length', 'transfer-encoding', 'connection',
                'forwarded', 'x-forwarded-for', 'x-forwarded-host', 'x-forwarded-proto',
            ], true);
    }
}
