<?php

namespace Modules\Login\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Modules\Login\Models\Device;
use Tests\TestCase;

class AttestationSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_devices_table_has_attestation_columns(): void
    {
        $this->assertTrue(Schema::hasColumns('devices', ['public_key_pem', 'security_level', 'attested_at']));
    }

    public function test_device_accepts_attestation_fields(): void
    {
        $device = Device::create([
            'name' => 'd', 'client_id' => 'c', 'client_id_md5' => md5('c'), 'app_id' => 1,
            'platform' => 1, 'last_login' => 0, 'secret' => 's',
        ]);

        $device->update(['public_key_pem' => 'PEM', 'security_level' => 'TEE', 'attested_at' => now()]);

        $fresh = $device->fresh();
        $this->assertSame('PEM', $fresh->public_key_pem);
        $this->assertSame('TEE', $fresh->security_level);
        $this->assertInstanceOf(Carbon::class, $fresh->attested_at);
    }

    public function test_attestation_config_defaults(): void
    {
        $this->assertTrue(config('login.attestation.enabled'));
        $this->assertSame('signed.device', config('login.attestation.middleware_alias'));
        $this->assertSame([], config('login.attestation.signature_digests'));
        $this->assertSame(300, config('login.attestation.timestamp_window'));
        $this->assertFileExists(module_path('Login', 'resources/attestation/google_roots.pem'));
    }
}
