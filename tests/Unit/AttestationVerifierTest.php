<?php

namespace Modules\Login\Tests\Unit;

use Illuminate\Support\Facades\Http;
use Modules\Login\Services\Attestation\AttestationException;
use Modules\Login\Services\Attestation\AttestationVerifier;
use Modules\Login\Tests\Support\AttestationChain;
use Tests\TestCase;

class AttestationVerifierTest extends TestCase
{
    private string $digest;

    /** Response gia cua Google status; test doi 2 bien nay truoc khi verify. */
    private mixed $statusBody = ['entries' => []];

    private int $statusCode = 200;

    protected function setUp(): void
    {
        parent::setUp();

        $this->digest = hash('sha256', 'signing-cert');
        // Mot stub duy nhat doc thuoc tinh (goi Http::fake lan 2 khong ghi de stub cu).
        Http::fake(['android.googleapis.com/*' => fn () => Http::response($this->statusBody, $this->statusCode)]);
    }

    private function chain(array $options = []): AttestationChain
    {
        $chain = AttestationChain::make(array_merge(['digests' => [$this->digest]], $options));
        config(['login.attestation.roots_path' => AttestationChain::rootsFile($chain->rootPem)]);

        return $chain;
    }

    private function verify(AttestationChain $chain, string $challenge = 'challenge', string $package = 'com.example.app', ?array $digests = null): array
    {
        return app(AttestationVerifier::class)->verify($chain->chain, $challenge, $package, $digests ?? [$this->digest]);
    }

    private function expectFailure(string $message): void
    {
        $this->expectExceptionObject(new AttestationException($message));
    }

    public function test_accepts_tee_chain_and_returns_leaf_public_key(): void
    {
        $chain = $this->chain();

        $result = $this->verify($chain);

        $this->assertSame('TEE', $result['security_level']);
        $leafPem = "-----BEGIN CERTIFICATE-----\n".chunk_split($chain->chain[0], 64, "\n")."-----END CERTIFICATE-----\n";
        $this->assertSame(openssl_pkey_get_details(openssl_pkey_get_public($leafPem))['key'], $result['public_key_pem']);
    }

    public function test_accepts_strongbox_chain(): void
    {
        $this->assertSame('StrongBox', $this->verify($this->chain(['security_level' => 2]))['security_level']);
    }

    public function test_accepts_digest_in_play_console_format(): void
    {
        $playConsole = strtoupper(implode(':', str_split($this->digest, 2)));

        $this->assertSame('TEE', $this->verify($this->chain(), digests: [$playConsole])['security_level']);
    }

    public function test_rejects_non_base64_certificate(): void
    {
        $chain = $this->chain();
        $chain->chain[1] = '%%%';

        $this->expectFailure('Certificate #1 không hợp lệ');
        $this->verify($chain);
    }

    public function test_rejects_garbage_certificate_bytes(): void
    {
        $chain = $this->chain();
        $chain->chain[0] = base64_encode(random_bytes(200));

        $this->expectFailure('Chữ ký certificate #0 không hợp lệ');
        $this->verify($chain);
    }

    public function test_rejects_spliced_chain(): void
    {
        $chain = $this->chain();
        $chain->chain[1] = AttestationChain::make()->chain[1];   // intermediate cua chain khac

        $this->expectFailure('Chữ ký certificate #0 không hợp lệ');
        $this->verify($chain);
    }

    public function test_rejects_unknown_root(): void
    {
        $chain = $this->chain();
        config(['login.attestation.roots_path' => AttestationChain::rootsFile(AttestationChain::root()[0])]);

        $this->expectFailure('Chain không kết thúc ở root của Google');
        $this->verify($chain);
    }

    public function test_rejects_missing_roots_file(): void
    {
        $chain = $this->chain();
        config(['login.attestation.roots_path' => '/nonexistent/roots.pem']);

        $this->expectFailure('Không đọc được file root của Google');
        $this->verify($chain);
    }

    public function test_rejects_revoked_certificate(): void
    {
        $chain = $this->chain();
        // '0' dau: serial phai duoc chuan hoa truoc khi so
        $this->statusBody = ['entries' => [
            '0'.$chain->leafSerialHex => ['status' => 'REVOKED', 'reason' => 'KEY_COMPROMISE'],
        ]];

        $this->expectFailure('Certificate #0 đã bị Google thu hồi');
        $this->verify($chain);
    }

    public function test_rejects_when_google_status_unavailable(): void
    {
        $chain = $this->chain();
        $this->statusBody = 'down';
        $this->statusCode = 503;

        $this->expectFailure('Không lấy được danh sách thu hồi của Google');
        $this->verify($chain);
    }

    public function test_caches_google_status(): void
    {
        $chain = $this->chain();
        $this->verify($chain);
        $this->verify($chain);

        Http::assertSentCount(1);
    }

    public function test_rejects_wrong_challenge(): void
    {
        $this->expectFailure('Challenge không khớp');
        $this->verify($this->chain(), 'other-challenge');
    }

    public function test_rejects_software_key(): void
    {
        $this->expectFailure('Key không nằm trong phần cứng');
        $this->verify($this->chain(['security_level' => 0]));
    }

    public function test_rejects_wrong_package(): void
    {
        $this->expectFailure('Sai package');
        $this->verify($this->chain(), package: 'com.other.app');
    }

    public function test_rejects_wrong_signature_digest(): void
    {
        $this->expectFailure('Sai chữ ký app (có thể app đã bị đóng gói lại)');
        $this->verify($this->chain(), digests: [str_repeat('00', 32)]);
    }

    public function test_rejects_forged_leaf_signed_by_attested_hardware_key(): void
    {
        // Key phan cung that cua app ke tan cong ky cert gia khai la app nan nhan, StrongBox
        $chain = $this->chain([
            'package' => 'com.attacker.app',
            'forged_leaf' => ['package' => 'com.example.app', 'security_level' => 2],
        ]);

        $this->expectFailure('Certificate #1 không phải CA');
        $this->verify($chain);
    }

    public function test_rejects_attestation_extension_outside_leaf(): void
    {
        $this->expectFailure('Certificate #1 có extension attestation');
        $this->verify($this->chain(['intermediate_extension' => true]));
    }

    public function test_rejects_software_keymint_level(): void
    {
        $this->expectFailure('Key không nằm trong phần cứng');
        $this->verify($this->chain(['keymint_security_level' => 0]));
    }

    public function test_rejects_unlocked_bootloader(): void
    {
        $this->expectFailure('Thiết bị đã mở khoá bootloader hoặc hệ điều hành không nguyên bản');
        $this->verify($this->chain(['root_of_trust' => ['locked' => false, 'state' => 0]]));
    }

    public function test_rejects_unverified_boot_state(): void
    {
        $this->expectFailure('Thiết bị đã mở khoá bootloader hoặc hệ điều hành không nguyên bản');
        $this->verify($this->chain(['root_of_trust' => ['locked' => true, 'state' => 2]]));
    }

    public function test_rejects_missing_root_of_trust(): void
    {
        $this->expectFailure('Thiết bị đã mở khoá bootloader hoặc hệ điều hành không nguyên bản');
        $this->verify($this->chain(['root_of_trust' => null]));
    }

    public function test_boot_state_check_can_be_disabled(): void
    {
        config(['login.attestation.require_verified_boot' => false]);

        $this->assertSame('TEE', $this->verify($this->chain(['root_of_trust' => ['locked' => false, 'state' => 2]]))['security_level']);
    }

    public function test_rejects_when_no_digest_is_allowed(): void
    {
        $this->expectFailure('Sai chữ ký app (có thể app đã bị đóng gói lại)');
        $this->verify($this->chain(), digests: []);
    }
}
