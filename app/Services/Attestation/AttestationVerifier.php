<?php

namespace Modules\Login\Services\Attestation;

/**
 * Kiem tra chain Key Attestation (leaf dau tien) theo 6 buoc:
 * 1 chuoi ky noi tiep, moi issuer la CA, chi leaf co extension attestation,
 * 2 root cua Google, 3 khong bi thu hoi, 4 doc KeyDescription,
 * 5 challenge / phan cung / boot state / package / digest cert ky app, 6 tra public key cua leaf.
 */
class AttestationVerifier
{
    public function __construct(
        private KeyDescriptionParser $parser,
        private GoogleAttestationStatus $status,
    ) {}

    /**
     * @param  string[]  $chainB64  certificate DER dang base64, leaf dung dau
     * @param  string  $challenge  bytes challenge da cap (raw)
     * @param  string[]  $allowedDigests  SHA-256 cert ky app duoc phep (hex, chap nhan dang AB:CD:..)
     * @return array{public_key_pem: string, security_level: string}
     */
    public function verify(array $chainB64, string $challenge, string $package, array $allowedDigests): array
    {
        $ders = [];
        foreach (array_values($chainB64) as $i => $b64) {
            $der = is_string($b64) ? base64_decode($b64, true) : false;
            if ($der === false || $der === '') {
                throw new AttestationException("Certificate #$i không hợp lệ");
            }
            $ders[] = $der;
        }
        if (count($ders) < 2) {
            throw new AttestationException('Chain quá ngắn');
        }
        $pems = array_map(fn ($der) => $this->toPem($der), $ders);

        // B1. cert[i] do cert[i+1] ky; cert cuoi tu ky
        foreach ($pems as $i => $pem) {
            $issuerKey = @openssl_pkey_get_public($pems[$i + 1] ?? $pem);
            if ($issuerKey === false || @openssl_x509_verify($pem, $issuerKey) !== 1) {
                throw new AttestationException("Chữ ký certificate #$i không hợp lệ");
            }
        }
        // Chi CA duoc ky cert khac va chi leaf duoc mang extension attestation. Neu khong, key phan
        // cung that cua app bat ky co the ky mot cert gia (key phan mem + extension tu viet) dat len dau chain.
        foreach (array_slice($pems, 1, null, true) as $i => $pem) {
            $extensions = openssl_x509_parse($pem)['extensions'] ?? [];
            $isCa = str_contains($extensions['basicConstraints'] ?? '', 'CA:TRUE')
                && (! isset($extensions['keyUsage']) || str_contains($extensions['keyUsage'], 'Certificate Sign'));
            if (! $isCa) {
                throw new AttestationException("Certificate #$i không phải CA");
            }
            if (isset($extensions[KeyDescriptionParser::OID])) {
                throw new AttestationException("Certificate #$i có extension attestation");
            }
        }

        // B2. Cert cuoi la root cua Google (so public key, khong so ca cert)
        if (! in_array($this->publicKeyPem(end($pems)), $this->googleRootKeys(), true)) {
            throw new AttestationException('Chain không kết thúc ở root của Google');
        }

        // B3. Khong cert nao bi Google thu hoi
        $revoked = $this->status->revokedSerials();
        foreach ($pems as $i => $pem) {
            $serial = GoogleAttestationStatus::normalizeSerial((string) (openssl_x509_parse($pem)['serialNumberHex'] ?? ''));
            if (isset($revoked[$serial])) {
                throw new AttestationException("Certificate #$i đã bị Google thu hồi");
            }
        }

        // B4. Thong tin attestation o cert leaf
        $description = $this->parser->parse($ders[0]);

        // B5
        if (! hash_equals($challenge, $description['challenge'])) {
            throw new AttestationException('Challenge không khớp');
        }
        if (! in_array($description['security_level'], [1, 2], true)
            || ! in_array($description['keymint_security_level'], [1, 2], true)) {
            throw new AttestationException('Key không nằm trong phần cứng');
        }
        // Bootloader da mo khoa / ROM khong nguyen ban -> app that van bi hook de ky request tuy y
        if (config('login.attestation.require_verified_boot', true)
            && ($description['device_locked'] !== true || $description['verified_boot_state'] !== 0)) {
            throw new AttestationException('Thiết bị đã mở khoá bootloader hoặc hệ điều hành không nguyên bản');
        }
        if ($description['package'] !== $package) {
            throw new AttestationException('Sai package');
        }
        $allowed = array_map(fn ($digest) => strtolower(str_replace(':', '', (string) $digest)), $allowedDigests);
        if (! array_intersect($description['digests'], $allowed)) {
            throw new AttestationException('Sai chữ ký app (có thể app đã bị đóng gói lại)');
        }

        // B6. Public key cua leaf dung de kiem tra chu ky moi request sau nay
        return [
            'public_key_pem' => $this->publicKeyPem($pems[0]),
            'security_level' => $description['security_level'] === 2 ? 'StrongBox' : 'TEE',
        ];
    }

    /**
     * @return string[] public key PEM cua cac root trong roots_path
     */
    private function googleRootKeys(): array
    {
        $path = config('login.attestation.roots_path') ?: module_path('Login', 'resources/attestation/google_roots.pem');
        $bundle = is_readable($path) ? file_get_contents($path) : false;
        if (empty($bundle)) {
            throw new AttestationException('Không đọc được file root của Google');
        }
        preg_match_all('/-----BEGIN CERTIFICATE-----.*?-----END CERTIFICATE-----/s', $bundle, $matches);

        $keys = [];
        foreach ($matches[0] as $pem) {
            $key = @openssl_pkey_get_public($pem);
            if ($key !== false) {
                $keys[] = openssl_pkey_get_details($key)['key'];
            }
        }

        return $keys;
    }

    private function publicKeyPem(string $certPem): string
    {
        $key = @openssl_pkey_get_public($certPem);
        if ($key === false) {
            throw new AttestationException('Không đọc được public key');
        }

        return openssl_pkey_get_details($key)['key'];
    }

    private function toPem(string $der): string
    {
        return "-----BEGIN CERTIFICATE-----\n"
            .chunk_split(base64_encode($der), 64, "\n")
            ."-----END CERTIFICATE-----\n";
    }
}
