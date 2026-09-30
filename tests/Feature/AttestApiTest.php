<?php

namespace Modules\Login\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Modules\Login\Helpers\LoginHelper;
use Modules\Login\Models\Device;
use Modules\Login\Tests\Support\AttestationChain;
use Tests\TestCase;

class AttestApiTest extends TestCase
{
    use RefreshDatabase;

    private string $digest;

    protected function setUp(): void
    {
        parent::setUp();

        $this->digest = hash('sha256', 'signing-cert');
        config([
            'login.openssl.device_secret' => 'test-device-secret',
            'login.attestation.signature_digests' => ['com.example.app' => [$this->digest]],
        ]);
        Http::fake(['android.googleapis.com/*' => Http::response(['entries' => []])]);
    }

    /**
     * @return array{0: array<string, string>, 1: int} header Authorization, device id
     */
    private function login(string $package = 'com.example.app'): array
    {
        $data = $this->postJson('/api/v1/auth/add-device', ['secret' => LoginHelper::encodeOpenSsl(json_encode([
            'client_id' => 'client-abc', 'platform' => 'android', 'package_id' => $package, 'time' => time(),
        ]))])->assertOk()->json('data');

        return [['Authorization' => 'Bearer '.$data['access_token']], $data['device']['id']];
    }

    private function challenge(array $headers): string
    {
        return base64_decode($this->postJson('/api/v1/auth/attest/challenge', [], $headers)->assertOk()->json('data.challenge'));
    }

    private function chainFor(string $challenge, array $options = []): AttestationChain
    {
        $chain = AttestationChain::make(array_merge(['challenge' => $challenge, 'digests' => [$this->digest]], $options));
        config(['login.attestation.roots_path' => AttestationChain::rootsFile($chain->rootPem)]);

        return $chain;
    }

    public function test_challenge_then_register_stores_public_key(): void
    {
        [$headers, $deviceId] = $this->login();
        $chain = $this->chainFor($this->challenge($headers), ['security_level' => 2]);

        $this->postJson('/api/v1/auth/attest/register', ['chain' => $chain->chain], $headers)
            ->assertOk()
            ->assertJsonPath('data.security_level', 'StrongBox');

        $device = Device::findOrFail($deviceId);
        $this->assertStringContainsString('BEGIN PUBLIC KEY', $device->public_key_pem);
        $this->assertSame('StrongBox', $device->security_level);
        $this->assertNotNull($device->attested_at);
    }

    public function test_challenge_is_32_random_bytes(): void
    {
        [$headers] = $this->login();

        $first = $this->challenge($headers);
        $second = $this->challenge($headers);

        $this->assertSame(32, strlen($first));
        $this->assertNotSame($first, $second);
    }

    public function test_register_without_challenge_is_rejected(): void
    {
        [$headers] = $this->login();
        $chain = $this->chainFor('challenge');

        $this->postJson('/api/v1/auth/attest/register', ['chain' => $chain->chain], $headers)
            ->assertStatus(400)
            ->assertJsonPath('data.message', 'Challenge không tồn tại hoặc đã hết hạn');
    }

    public function test_challenge_can_only_be_used_once(): void
    {
        [$headers] = $this->login();
        $chain = $this->chainFor($this->challenge($headers));

        $this->postJson('/api/v1/auth/attest/register', ['chain' => $chain->chain], $headers)->assertOk();
        $this->postJson('/api/v1/auth/attest/register', ['chain' => $chain->chain], $headers)->assertStatus(400);
    }

    public function test_failed_verification_returns_403_and_keeps_device_unattested(): void
    {
        [$headers, $deviceId] = $this->login();
        $chain = $this->chainFor('not-the-issued-challenge');
        $this->challenge($headers);

        $this->postJson('/api/v1/auth/attest/register', ['chain' => $chain->chain], $headers)
            ->assertStatus(403)
            ->assertJsonPath('data.message', 'Attestation: Challenge không khớp');

        $this->assertNull(Device::findOrFail($deviceId)->public_key_pem);
    }

    public function test_package_without_configured_digests_is_rejected(): void
    {
        [$headers] = $this->login('com.unlisted.app');
        $chain = $this->chainFor($this->challenge($headers), ['package' => 'com.unlisted.app']);

        $this->postJson('/api/v1/auth/attest/register', ['chain' => $chain->chain], $headers)
            ->assertStatus(403)
            ->assertJsonPath('data.message', 'Attestation: Sai chữ ký app (có thể app đã bị đóng gói lại)');
    }

    public function test_register_again_replaces_public_key(): void
    {
        [$headers, $deviceId] = $this->login();
        $this->postJson('/api/v1/auth/attest/register', ['chain' => $this->chainFor($this->challenge($headers))->chain], $headers)->assertOk();
        $firstKey = Device::findOrFail($deviceId)->public_key_pem;

        $this->postJson('/api/v1/auth/attest/register', ['chain' => $this->chainFor($this->challenge($headers))->chain], $headers)->assertOk();

        $this->assertNotSame($firstKey, Device::findOrFail($deviceId)->public_key_pem);
    }

    public function test_register_validates_chain(): void
    {
        [$headers] = $this->login();

        $this->postJson('/api/v1/auth/attest/register', ['chain' => ['only-one']], $headers)->assertStatus(422);
        $this->postJson('/api/v1/auth/attest/register', [], $headers)->assertStatus(422);
    }

    public function test_attest_routes_require_device_token(): void
    {
        $this->postJson('/api/v1/auth/attest/challenge')->assertStatus(401);
        $this->postJson('/api/v1/auth/attest/register', ['chain' => ['a', 'b']])->assertStatus(401);
    }
}
