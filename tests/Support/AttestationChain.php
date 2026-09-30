<?php

namespace Modules\Login\Tests\Support;

use OpenSSLAsymmetricKey;

/**
 * Sinh chain Key Attestation GIA de test: root (tu ky) -> intermediate -> leaf EC P-256
 * co extension KeyDescription (OID 1.3.6.1.4.1.11129.2.1.17).
 * Khong thay the duoc viec kiem tra voi chain that tu thiet bi.
 */
class AttestationChain
{
    /** @var string[] base64 DER, leaf dau tien */
    public array $chain;

    public string $rootPem;

    public string $leafSerialHex;

    public string $leafDer;

    public static function make(array $options = []): self
    {
        $o = array_merge([
            'challenge' => 'challenge',
            'security_level' => 1,
            'package' => 'com.example.app',
            'digests' => [str_repeat('ab', 32)],
            'with_extension' => true,
            'with_app_id' => true,
            'keymint_security_level' => null,                   // null = bang security_level
            'root_of_trust' => ['locked' => true, 'state' => 0], // null = khong co [704]; state 0 = Verified
            'intermediate_extension' => false,                  // intermediate cung mang extension attestation
            'forged_leaf' => null,                              // mang option: them cert gia do key cua leaf ky, dat len dau chain
            'root' => null,
        ], $options);

        [$rootPem, $rootKey] = $o['root'] ?? self::root();
        [$interPem, $interKey] = self::issue('Intermediate', true, $rootPem, $rootKey, $o['intermediate_extension'] ? self::keyDescription($o) : null);
        [$leafPem, $leafKey, $leafSerial] = self::issue('Leaf', false, $interPem, $interKey, $o['with_extension'] ? self::keyDescription($o) : null);
        $pems = [$leafPem, $interPem, $rootPem];

        if ($o['forged_leaf'] !== null) {
            // Tan cong: dung key phan cung that (leaf) ky mot cert chua key phan mem + extension tu viet
            [$leafPem, , $leafSerial] = self::issue('Forged', false, $pems[0], $leafKey, self::keyDescription(array_merge($o, $o['forged_leaf'])));
            array_unshift($pems, $leafPem);
        }

        $self = new self;
        $self->chain = array_map(fn ($pem) => base64_encode(self::pemToDer($pem)), $pems);
        $self->rootPem = $rootPem;
        $self->leafSerialHex = $leafSerial;
        $self->leafDer = self::pemToDer($leafPem);

        return $self;
    }

    /**
     * @return array{0: string, 1: OpenSSLAsymmetricKey}
     */
    public static function root(): array
    {
        [$pem, $key] = self::issue('Root', true, null, null);

        return [$pem, $key];
    }

    public static function rootsFile(string ...$pems): string
    {
        $path = tempnam(sys_get_temp_dir(), 'roots');
        file_put_contents($path, implode("\n", $pems));

        return $path;
    }

    /**
     * @return array{0: string, 1: OpenSSLAsymmetricKey, 2: string} pem, private key, serial hex
     */
    private static function issue(string $cn, bool $ca, ?string $issuerPem, ?OpenSSLAsymmetricKey $issuerKey, ?string $extDer = null): array
    {
        $config = tempnam(sys_get_temp_dir(), 'cnf');
        // default_bits: PHP < 8.4 kiem tra do dai key ca voi EC, lay tu config nay (thieu = 0 -> loi)
        file_put_contents($config, "[req]\ndefault_bits=2048\ndistinguished_name=dn\n[dn]\n[ext]\n"
            .($ca ? "basicConstraints=critical,CA:true\n" : "basicConstraints=CA:false\n")
            .($extDer !== null ? '1.3.6.1.4.1.11129.2.1.17=DER:'.bin2hex($extDer)."\n" : ''));
        $options = [
            'config' => $config,
            'x509_extensions' => 'ext',
            'digest_alg' => 'sha256',
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name' => 'prime256v1',
        ];

        $key = openssl_pkey_new($options);
        $csr = openssl_csr_new(['commonName' => $cn], $key, $options);
        $serial = random_int(1, PHP_INT_MAX);
        $cert = openssl_csr_sign($csr, $issuerPem, $issuerKey ?? $key, 1, $options, $serial);
        openssl_x509_export($cert, $pem);
        unlink($config);

        return [$pem, $key, dechex($serial)];
    }

    private static function keyDescription(array $o): string
    {
        $packageInfos = $o['package'] === null ? self::set() : self::set(self::seq(self::octet($o['package']), self::integer(1)));
        $digests = self::set(...array_map(fn ($hex) => self::octet(hex2bin($hex)), $o['digests']));
        $applicationId = self::seq($packageInfos, $digests);
        $softwareEnforced = $o['with_app_id'] ? self::seq(self::tlv(0xBF, self::octet($applicationId), 709)) : self::seq();
        $rootOfTrust = $o['root_of_trust'] === null ? '' : self::tlv(0xBF, self::seq(
            self::octet(str_repeat("\x11", 32)),                        // verifiedBootKey
            self::tlv(0x01, $o['root_of_trust']['locked'] ? "\xFF" : "\x00"), // deviceLocked
            self::integer($o['root_of_trust']['state'], 0x0A),         // verifiedBootState
            self::octet(str_repeat("\x22", 32)),                        // verifiedBootHash
        ), 704);

        return self::seq(
            self::integer(4),                            // attestationVersion
            self::integer($o['security_level'], 0x0A),  // attestationSecurityLevel (ENUMERATED)
            self::integer(41),                           // keyMintVersion
            self::integer($o['keymint_security_level'] ?? $o['security_level'], 0x0A),  // keyMintSecurityLevel
            self::octet($o['challenge']),                // attestationChallenge
            self::octet(''),                             // uniqueId
            $softwareEnforced,
            self::seq($rootOfTrust),                     // hardwareEnforced
        );
    }

    private static function tlv(int $tag, string $content, ?int $highTagNumber = null): string
    {
        $out = chr($tag);
        if ($highTagNumber !== null) {
            $bytes = [$highTagNumber & 0x7F];
            for ($n = $highTagNumber >> 7; $n > 0; $n >>= 7) {
                $bytes[] = ($n & 0x7F) | 0x80;
            }
            $out .= implode('', array_map('chr', array_reverse($bytes)));
        }
        $length = strlen($content);
        if ($length < 128) {
            $out .= chr($length);
        } else {
            $lengthBytes = ltrim(pack('N', $length), "\0");
            $out .= chr(0x80 | strlen($lengthBytes)).$lengthBytes;
        }

        return $out.$content;
    }

    private static function integer(int $value, int $tag = 0x02): string
    {
        return self::tlv($tag, $value === 0 ? "\0" : ltrim(pack('N', $value), "\0"));
    }

    private static function octet(string $value): string
    {
        return self::tlv(0x04, $value);
    }

    private static function seq(string ...$items): string
    {
        return self::tlv(0x30, implode('', $items));
    }

    private static function set(string ...$items): string
    {
        return self::tlv(0x31, implode('', $items));
    }

    private static function pemToDer(string $pem): string
    {
        return base64_decode(preg_replace('/-----[^-]+-----|\s/', '', $pem));
    }
}
