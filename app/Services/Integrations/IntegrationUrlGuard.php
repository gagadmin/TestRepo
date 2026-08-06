<?php

namespace App\Services\Integrations;

use InvalidArgumentException;

class IntegrationUrlGuard
{
    public function assertAllowed(string $url): void
    {
        $parts = parse_url($url);
        $scheme = strtolower($parts['scheme'] ?? '');
        $host = strtolower($parts['host'] ?? '');

        if (! in_array($scheme, ['http', 'https'], true) || $host === '') {
            throw new InvalidArgumentException('The integration endpoint must be a valid HTTP or HTTPS URL.');
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new InvalidArgumentException('Credentials must not be embedded in the integration URL.');
        }

        if (isset($parts['query']) || isset($parts['fragment'])) {
            throw new InvalidArgumentException('Integration endpoint URLs cannot contain a query string or fragment.');
        }

        if (config('integrations.require_https') && $scheme !== 'https') {
            throw new InvalidArgumentException('Integration endpoints must use HTTPS.');
        }

        if (config('integrations.allow_private_networks')) {
            return;
        }

        if ($host === 'localhost' || str_ends_with($host, '.localhost')) {
            throw new InvalidArgumentException('Local integration endpoints are not allowed.');
        }

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $this->assertPublicIp($host);

            return;
        }

        if (app()->environment('testing')) {
            return;
        }

        $records = dns_get_record($host, DNS_A | DNS_AAAA);

        if ($records === false || $records === []) {
            throw new InvalidArgumentException('The integration host could not be resolved.');
        }

        foreach ($records as $record) {
            $address = $record['ip'] ?? $record['ipv6'] ?? null;

            if ($address) {
                $this->assertPublicIp($address);
            }
        }
    }

    private function assertPublicIp(string $address): void
    {
        if (! filter_var(
            $address,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        )) {
            throw new InvalidArgumentException('Private or reserved integration endpoints are not allowed.');
        }
    }
}
