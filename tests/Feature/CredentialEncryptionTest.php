<?php

namespace Tests\Feature;

use App\Models\ApiConfiguration;
use App\Models\DataSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CredentialEncryptionTest extends TestCase
{
    use RefreshDatabase;

    public function test_integration_credentials_are_encrypted_at_rest(): void
    {
        $source = DataSource::create([
            'name' => 'Test CRM',
            'type' => 'crm',
            'status' => 'draft',
        ]);

        $configuration = ApiConfiguration::create([
            'data_source_id' => $source->id,
            'auth_type' => 'api_key',
            'encrypted_credentials' => ['api_key' => 'top-secret-value'],
            'encrypted_headers' => ['X-Tenant' => 'example'],
        ]);

        $storedValue = DB::table('api_configurations')
            ->where('id', $configuration->id)
            ->value('encrypted_credentials');

        $this->assertStringNotContainsString('top-secret-value', $storedValue);
        $this->assertSame(
            'top-secret-value',
            $configuration->fresh()->encrypted_credentials['api_key']
        );
    }
}
