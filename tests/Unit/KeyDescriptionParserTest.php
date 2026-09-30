<?php

namespace Modules\Login\Tests\Unit;

use Modules\Login\Services\Attestation\AttestationException;
use Modules\Login\Services\Attestation\KeyDescriptionParser;
use Modules\Login\Tests\Support\AttestationChain;
use Tests\TestCase;

class KeyDescriptionParserTest extends TestCase
{
    public function test_reads_all_fields(): void
    {
        $digest = hash('sha256', 'signing-cert');
        $chain = AttestationChain::make([
            'challenge' => "raw\x00challenge",
            'security_level' => 2,
            'package' => 'com.cdt.game',
            'digests' => [$digest, str_repeat('cd', 32)],
        ]);

        $result = (new KeyDescriptionParser)->parse($chain->leafDer);

        $this->assertSame(2, $result['security_level']);
        $this->assertSame("raw\x00challenge", $result['challenge']);
        $this->assertSame('com.cdt.game', $result['package']);
        $this->assertEqualsCanonicalizing([$digest, str_repeat('cd', 32)], $result['digests']);
    }

    public function test_reads_keymint_level_and_root_of_trust(): void
    {
        $chain = AttestationChain::make([
            'security_level' => 2,
            'keymint_security_level' => 1,
            'root_of_trust' => ['locked' => false, 'state' => 1],
        ]);

        $result = (new KeyDescriptionParser)->parse($chain->leafDer);

        $this->assertSame(1, $result['keymint_security_level']);
        $this->assertFalse($result['device_locked']);
        $this->assertSame(1, $result['verified_boot_state']);
    }

    public function test_missing_root_of_trust_is_null(): void
    {
        $result = (new KeyDescriptionParser)->parse(AttestationChain::make(['root_of_trust' => null])->leafDer);

        $this->assertNull($result['device_locked']);
        $this->assertNull($result['verified_boot_state']);
    }

    public function test_rejects_cert_without_extension(): void
    {
        $this->expectExceptionObject(new AttestationException('Không có extension attestation'));

        (new KeyDescriptionParser)->parse(AttestationChain::make(['with_extension' => false])->leafDer);
    }

    public function test_rejects_missing_application_id(): void
    {
        $this->expectExceptionObject(new AttestationException('Thiếu attestationApplicationId'));

        (new KeyDescriptionParser)->parse(AttestationChain::make(['with_app_id' => false])->leafDer);
    }

    public function test_rejects_missing_package_name(): void
    {
        $this->expectExceptionObject(new AttestationException('Không đọc được package name'));

        (new KeyDescriptionParser)->parse(AttestationChain::make(['package' => null])->leafDer);
    }

    public function test_rejects_garbage_bytes(): void
    {
        $this->expectException(AttestationException::class);

        (new KeyDescriptionParser)->parse(random_bytes(64));
    }
}
