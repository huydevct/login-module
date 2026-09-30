# Keystore Attestation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Thêm đăng ký thiết bị bằng Android Key Attestation và middleware `signed.device` kiểm tra chữ ký từng request vào module `Login`.

**Architecture:** Gắn vào device JWT sẵn có (`auth.api`). Bảng `devices` thêm 3 cột lưu public key đã attest. Logic attestation nằm trong `app/Services/Attestation` (parser ASN.1, danh sách thu hồi của Google, verifier 6 bước), lỗi là `AttestationException`; controller và middleware chỉ map lỗi sang JSON.

**Tech Stack:** PHP ^8.2, Laravel 11+, nwidart/laravel-modules, phpseclib/phpseclib ^3.0, ext-openssl.

**Spec:** `docs/superpowers/specs/2026-09-30-keystore-attestation-design.md`

## Global Constraints

- Không commit. Người dùng review bằng diff chưa commit; chỉ commit khi được yêu cầu, message không có dòng `Co-Authored-By`.
- Namespace `Modules\Login\...`; config key `login.*`; route name `login.api.*`; cache key tiền tố `login:`.
- Chuỗi ký: `METHOD\n/PATH\nQUERY\nTIMESTAMP\nNONCE\nDEVICE_ID\nAPP_ID\nSHA256_HEX(body)`, không có `\n` cuối.
- Lỗi middleware dạng `{code, message, status}` giống `ApiAuthenticate`; lỗi controller dạng `$this->response(['message' => ...], $status)`.
- Comment trong code theo kiểu file sẵn có: tiếng Việt không dấu trong docblock PHP.
- Chỉ chạy được test trong project host (tests extend `Tests\TestCase`).

## Review Focus

- Package name có dấu chấm (`com.example.app`) → không được đọc bằng `config("...signature_digests.$package")` (dấu chấm bị hiểu là cấp config). Test: controller test dùng package có dấu chấm và phải đăng ký thành công (Task 4).
- Digest dán từ Play Console dạng `AB:CD:...` chữ hoa → vẫn phải khớp. Verifier chuẩn hoá (bỏ `:`, chữ thường). Test ở Task 3.
- Cert rác (base64 đúng nhưng bytes không phải cert, hoặc DER sai cấu trúc) → 403 với lý do, không được 500. Test ở Task 2 và Task 3.
- Worker chạy lâu (Octane/queue): singleton `AuthApi` còn giữ thiết bị của request trước. `signed.device` không dựa vào singleton mà đọc request attribute `login_auth` do `ApiAuthenticate` gắn cho chính request đó. Test ở Task 5 (route chỉ có `signed.device` → 401 dù singleton còn dữ liệu cũ).
- App gửi timestamp mili-giây → bị 401 `request_expired`. Ghi rõ "epoch giây" trong README (Task 6); test timestamp lệch ở Task 5.

---

## Task 0: Project host để chạy test (đã dựng sẵn)

Project host nằm ở scratchpad, **không thuộc repo**:
`/tmp/claude-1000/-home-huyct-code-login-module/8f6699ef-e35f-46e1-bfae-b6c33011fc1b/scratchpad/host`
(gọi tắt là `$HOST`). Đã có: Laravel 13, module cài qua path repository dạng symlink
(`Modules/Login -> /home/huyct/code/login-module`), module đã enable, merge-plugin gộp `autoload-dev`
của module, `phpunit.xml` có testsuite `Modules` trỏ `Modules/Login/tests`, sqlite in-memory, cache `array`.

Nếu phải dựng lại:

```bash
cd <scratchpad> && composer create-project laravel/laravel host -q && cd host
composer config repositories.login '{"type":"path","url":"/home/huyct/code/login-module","options":{"symlink":true}}'
composer config allow-plugins.joshbrw/laravel-module-installer true
composer config allow-plugins.wikimedia/composer-merge-plugin true
composer config minimum-stability dev && composer config prefer-stable true
composer config extra.merge-plugin.include --json '["Modules/*/composer.json"]'
composer require huyct/login-module:@dev -W && composer update --lock
php artisan module:enable Login
# phpunit.xml: thêm <testsuite name="Modules"><directory>Modules/Login/tests</directory></testsuite>
```

Lệnh chạy test (dùng ở mọi task): `cd $HOST && php artisan test --testsuite=Modules`
Lọc một file: `cd $HOST && php artisan test Modules/Login/tests/Unit/KeyDescriptionParserTest.php`

- [ ] **Step 1: Kiểm tra host chạy được**

Run: `cd $HOST && php artisan test --testsuite=Modules`
Expected: 11 passed.

- [ ] **Step 2: Bỏ phpseclib khỏi host root** (lúc prototype đã cài thẳng vào host; phải để module kéo về)

Run: `cd $HOST && composer remove phpseclib/phpseclib -q`
Expected: không lỗi (Task 1 sẽ thêm lại qua module).

---

## Task 1: Dependency, config, migration, model, root của Google

**Files:**
- Modify: `composer.json` (require)
- Modify: `config/config.php` (thêm khối `attestation`)
- Create: `database/migrations/2026_09_30_000000_add_attestation_columns_to_devices_table.php`
- Modify: `app/Models/Device.php`
- Create: `resources/attestation/google_roots.pem`
- Test: `tests/Feature/AttestationSchemaTest.php`

**Interfaces:**
- Produces: config `login.attestation.{enabled, middleware_alias, signature_digests, roots_path, status_url, status_cache_ttl, challenge_ttl, timestamp_window}`; cột `devices.public_key_pem|security_level|attested_at`; `Device` fillable 3 cột + cast `attested_at` datetime; file `resources/attestation/google_roots.pem`.

- [ ] **Step 1: Viết test hỏng**

```php
<?php
// tests/Feature/AttestationSchemaTest.php

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
```

- [ ] **Step 2: Chạy test, xác nhận hỏng**

Run: `cd $HOST && php artisan test Modules/Login/tests/Feature/AttestationSchemaTest.php`
Expected: FAIL (thiếu cột, thiếu config).

- [ ] **Step 3: Thêm dependency**

Trong `composer.json`, khối `require` thêm dòng (giữ thứ tự chữ cái):

```json
        "nwidart/laravel-modules": "^11.0|^12.0|^13.0",
        "phpseclib/phpseclib": "^3.0"
```

Run: `cd $HOST && composer update huyct/login-module phpseclib/phpseclib -W -q && composer show phpseclib/phpseclib | head -3`
Expected: phpseclib 3.x được cài.

Rồi làm mới lock của module (lock bị gitignore, chỉ để `composer validate` sạch):
Run: `cd /home/huyct/code/login-module && composer update --lock -q && composer validate --no-check-publish`
Expected: `./composer.json is valid`.

- [ ] **Step 4: Thêm khối config**

Trong `config/config.php`, chèn trước khối `'openssl' => [`:

```php
    /*
    |--------------------------------------------------------------------------
    | Android Keystore attestation
    |--------------------------------------------------------------------------
    |
    | POST {api.prefix}/attest/challenge va /attest/register (sau auth.api) de
    | dang ky public key cua key nam trong Keystore (TEE/StrongBox). Route can bao
    | ve dung middleware ['auth.api', 'signed.device'].
    |
    */
    'attestation' => [
        'enabled' => true,
        'middleware_alias' => 'signed.device',

        /*
         | package => danh sach SHA-256 cua cert ky app ma nguoi dung thuc su cai
         | (Play App Signing: "App signing key certificate" trong Play Console).
         | Chap nhan dang "AB:CD:..." hoac hex thuong. Package khong co o day -> tu choi.
         | Vi du: 'com.cdt.game' => ['ab12...ef'],
         */
        'signature_digests' => [],

        // Bundle PEM root cua Google. null = file di kem module (resources/attestation/google_roots.pem).
        'roots_path' => env('LOGIN_MODULE_ATTESTATION_ROOTS'),

        'status_url' => 'https://android.googleapis.com/attestation/status',
        'status_cache_ttl' => 86400,

        // Thoi gian song cua challenge va cua so timestamp cua request ky (giay).
        'challenge_ttl' => 300,
        'timestamp_window' => 300,
    ],

```

- [ ] **Step 5: Migration**

```php
<?php
// database/migrations/2026_09_30_000000_add_attestation_columns_to_devices_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Public key cua key trong Android Keystore sau khi attest thanh cong.
 * public_key_pem = null nghia la device chua attest.
 */
return new class extends Migration
{
    private array $columns = ['public_key_pem', 'security_level', 'attested_at'];

    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            if (! Schema::hasColumn('devices', 'public_key_pem')) {
                $table->text('public_key_pem')->nullable();
            }
            if (! Schema::hasColumn('devices', 'security_level')) {
                $table->string('security_level', 20)->nullable()->comment('TEE | StrongBox');
            }
            if (! Schema::hasColumn('devices', 'attested_at')) {
                $table->timestamp('attested_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        foreach ($this->columns as $column) {
            if (Schema::hasColumn('devices', $column)) {
                Schema::table('devices', function (Blueprint $table) use ($column) {
                    $table->dropColumn($column);
                });
            }
        }
    }
};
```

- [ ] **Step 6: Model**

`app/Models/Device.php`: thêm 3 cột vào cuối `$fillable` và thêm `$casts`:

```php
    protected $fillable = [
        'name',
        'client_id',
        'client_id_md5',
        'app_id',
        'platform',
        'last_login',
        'secret',
        'public_key_pem',
        'security_level',
        'attested_at',
    ];

    protected $hidden = [
        'secret',
    ];

    protected $casts = [
        'attested_at' => 'datetime',
    ];
```

- [ ] **Step 7: Tải root của Google**

`/attestation/root` trả JSON là mảng các chuỗi PEM.

Run:
```bash
cd /home/huyct/code/login-module && mkdir -p resources/attestation && curl -sf https://android.googleapis.com/attestation/root \
 | php -r '$a = json_decode(stream_get_contents(STDIN), true); if (!is_array($a) || !$a) exit(1); echo implode("\n", array_map("trim", $a)), "\n";' \
 > resources/attestation/google_roots.pem && grep -c "BEGIN CERTIFICATE" resources/attestation/google_roots.pem \
 && php -r 'preg_match_all("/-----BEGIN CERTIFICATE-----.*?-----END CERTIFICATE-----/s", file_get_contents("resources/attestation/google_roots.pem"), $m); foreach ($m[0] as $p) { var_dump(openssl_pkey_get_public($p) !== false); }'
```
Expected: số cert ≥ 1 (lúc viết plan là 2) và mỗi cert in `bool(true)`.

- [ ] **Step 8: Chạy test**

Run: `cd $HOST && php artisan test --testsuite=Modules`
Expected: PASS toàn bộ (11 cũ + 3 mới).

---

## Task 2: Chain giả cho test, `AttestationException`, `KeyDescriptionParser`

**Files:**
- Create: `tests/Support/AttestationChain.php`
- Create: `app/Services/Attestation/AttestationException.php`
- Create: `app/Services/Attestation/KeyDescriptionParser.php`
- Test: `tests/Unit/KeyDescriptionParserTest.php`

**Interfaces:**
- Produces:
  - `Modules\Login\Services\Attestation\AttestationException extends \RuntimeException`
  - `KeyDescriptionParser::parse(string $leafDer): array{security_level:int, challenge:string, package:string, digests:string[]}` (digests hex thường); lỗi → `AttestationException`.
  - `KeyDescriptionParser::OID = '1.3.6.1.4.1.11129.2.1.17'`
  - Test helper `Modules\Login\Tests\Support\AttestationChain`:
    - `static make(array $options = []): self` — options: `challenge` (string raw, mặc định `'challenge'`), `security_level` (int, 1), `package` (?string, `'com.example.app'`; null = không có package_info), `digests` (string[] hex, `[str_repeat('ab', 32)]`), `with_extension` (bool, true), `with_app_id` (bool, true), `root` (?array `[pem, key]`, null = root mới).
    - thuộc tính: `array $chain` (base64 DER, leaf đầu, 3 cert: leaf, intermediate, root), `string $rootPem`, `string $leafSerialHex` (hex thường, không số 0 đầu), `string $leafDer`.
    - `static root(): array{0:string,1:\OpenSSLAsymmetricKey}` — root EC tự ký.
    - `static rootsFile(string ...$pems): string` — ghi file tạm, trả đường dẫn.

- [ ] **Step 1: Viết helper sinh chain**

```php
<?php
// tests/Support/AttestationChain.php

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
            'root' => null,
        ], $options);

        [$rootPem, $rootKey] = $o['root'] ?? self::root();
        [$interPem, $interKey] = self::issue('Intermediate', true, $rootPem, $rootKey);
        [$leafPem, , $leafSerial] = self::issue('Leaf', false, $interPem, $interKey, $o['with_extension'] ? self::keyDescription($o) : null);

        $self = new self;
        $self->chain = array_map(fn ($pem) => base64_encode(self::pemToDer($pem)), [$leafPem, $interPem, $rootPem]);
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
        file_put_contents($config, "[req]\ndistinguished_name=dn\n[dn]\n[ext]\n"
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

        return self::seq(
            self::integer(4),                            // attestationVersion
            self::integer($o['security_level'], 0x0A),  // attestationSecurityLevel (ENUMERATED)
            self::integer(41),                           // keyMintVersion
            self::integer($o['security_level'], 0x0A),  // keyMintSecurityLevel
            self::octet($o['challenge']),                // attestationChallenge
            self::octet(''),                             // uniqueId
            $softwareEnforced,
            self::seq(),                                 // hardwareEnforced
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
```

- [ ] **Step 2: Viết test hỏng cho parser**

```php
<?php
// tests/Unit/KeyDescriptionParserTest.php

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
```

- [ ] **Step 3: Chạy test, xác nhận hỏng**

Run: `cd $HOST && composer dump-autoload -q && php artisan test Modules/Login/tests/Unit/KeyDescriptionParserTest.php`
Expected: FAIL — class `KeyDescriptionParser` không tồn tại.

- [ ] **Step 4: Exception**

```php
<?php
// app/Services/Attestation/AttestationException.php

namespace Modules\Login\Services\Attestation;

use RuntimeException;

/**
 * Chain attestation khong hop le. Message la ly do tra ve cho client.
 */
class AttestationException extends RuntimeException
{
}
```

- [ ] **Step 5: Parser**

```php
<?php
// app/Services/Attestation/KeyDescriptionParser.php

namespace Modules\Login\Services\Attestation;

use phpseclib3\File\ASN1;
use phpseclib3\File\X509;
use Throwable;

/**
 * Doc extension Key Attestation cua cert leaf.
 *
 * KeyDescription ::= SEQUENCE {
 *   attestationVersion, attestationSecurityLevel (0 Software, 1 TEE, 2 StrongBox),
 *   keyMintVersion, keyMintSecurityLevel, attestationChallenge OCTET STRING, uniqueId,
 *   softwareEnforced AuthorizationList, hardwareEnforced AuthorizationList }
 *
 * attestationApplicationId = [709] EXPLICIT OCTET STRING trong softwareEnforced, chua DER cua
 *   SEQUENCE { package_infos SET OF SEQUENCE { package_name, version }, signature_digests SET OF OCTET STRING }
 */
class KeyDescriptionParser
{
    public const OID = '1.3.6.1.4.1.11129.2.1.17';

    private const TAG_APPLICATION_ID = 709;

    /**
     * @return array{security_level: int, challenge: string, package: string, digests: string[]}
     */
    public function parse(string $leafDer): array
    {
        try {
            $cert = (new X509)->loadX509($leafDer);
        } catch (Throwable) {
            $cert = false;
        }
        if (! is_array($cert)) {
            throw new AttestationException('Không đọc được cert leaf');
        }

        $extension = null;
        foreach ($cert['tbsCertificate']['extensions'] ?? [] as $item) {
            if (($item['extnId'] ?? null) === self::OID) {
                $extension = $item;
                break;
            }
        }
        if ($extension === null) {
            throw new AttestationException('Không có extension attestation');
        }

        try {
            return $this->parseKeyDescription($extension['extnValue']);
        } catch (AttestationException $e) {
            throw $e;
        } catch (Throwable) {
            throw new AttestationException('Extension attestation sai cấu trúc');
        }
    }

    private function parseKeyDescription(string $der): array
    {
        $description = ASN1::decodeBER($der)[0]['content'] ?? null;
        if (! is_array($description) || count($description) < 8) {
            throw new AttestationException('Extension attestation sai cấu trúc');
        }

        $applicationId = null;
        foreach ($description[6]['content'] as $element) {
            if (($element['constant'] ?? null) === self::TAG_APPLICATION_ID) {
                $applicationId = $element;
                break;
            }
        }
        if ($applicationId === null) {
            throw new AttestationException('Thiếu attestationApplicationId');
        }

        // Ben trong [709] la 1 OCTET STRING chua DER -> decode lan nua
        $app = ASN1::decodeBER($applicationId['content'][0]['content'])[0]['content'];
        $package = $app[0]['content'][0]['content'][0]['content'] ?? null;
        if (! is_string($package) || $package === '') {
            throw new AttestationException('Không đọc được package name');
        }

        return [
            'security_level' => (int) $description[1]['content']->toString(),
            'challenge' => $description[4]['content'],
            'package' => $package,
            'digests' => array_map(fn ($digest) => bin2hex($digest['content']), $app[1]['content']),
        ];
    }
}
```

- [ ] **Step 6: Chạy test**

Run: `cd $HOST && php artisan test Modules/Login/tests/Unit/KeyDescriptionParserTest.php`
Expected: 5 passed. Nếu hỏng ở chỉ số mảng: `dump(ASN1::decodeBER(...))` từng tầng rồi chỉnh, không đổi cấu trúc DER của helper (helper theo đúng schema Android).

---

## Task 3: `GoogleAttestationStatus` và `AttestationVerifier`

**Files:**
- Create: `app/Services/Attestation/GoogleAttestationStatus.php`
- Create: `app/Services/Attestation/AttestationVerifier.php`
- Test: `tests/Unit/AttestationVerifierTest.php`

**Interfaces:**
- Consumes: `KeyDescriptionParser::parse()`, `AttestationException`, `AttestationChain` (Task 2); config `login.attestation.*` (Task 1).
- Produces:
  - `GoogleAttestationStatus::revokedSerials(): array<string, mixed>` (key = serial chuẩn hoá); `GoogleAttestationStatus::normalizeSerial(string $hex): string`.
  - `AttestationVerifier::__construct(KeyDescriptionParser $parser, GoogleAttestationStatus $status)` (resolve bằng container).
  - `AttestationVerifier::verify(array $chainB64, string $challenge, string $package, array $allowedDigests): array{public_key_pem: string, security_level: 'TEE'|'StrongBox'}`; lỗi → `AttestationException`.

- [ ] **Step 1: Viết test hỏng**

```php
<?php
// tests/Unit/AttestationVerifierTest.php

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

    public function test_rejects_when_no_digest_is_allowed(): void
    {
        $this->expectFailure('Sai chữ ký app (có thể app đã bị đóng gói lại)');
        $this->verify($this->chain(), digests: []);
    }
}
```

- [ ] **Step 2: Chạy test, xác nhận hỏng**

Run: `cd $HOST && php artisan test Modules/Login/tests/Unit/AttestationVerifierTest.php`
Expected: FAIL — class `AttestationVerifier` không tồn tại.

- [ ] **Step 3: `GoogleAttestationStatus`**

```php
<?php
// app/Services/Attestation/GoogleAttestationStatus.php

namespace Modules\Login\Services\Attestation;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Danh sach cert attestation bi Google thu hoi (khong can API key), cache theo status_cache_ttl.
 * Google loi -> nem AttestationException (chan dang ky cho an toan).
 */
class GoogleAttestationStatus
{
    private const CACHE_KEY = 'login:attest:google_status';

    /**
     * @return array<string, mixed> serial hex thuong, khong so 0 dau => thong tin thu hoi
     */
    public function revokedSerials(): array
    {
        $cached = Cache::get(self::CACHE_KEY);
        if (is_array($cached)) {
            return $cached;
        }

        try {
            $entries = Http::timeout(10)->get(config('login.attestation.status_url'))->throw()->json('entries');
        } catch (Throwable) {
            throw new AttestationException('Không lấy được danh sách thu hồi của Google');
        }
        if (! is_array($entries)) {
            throw new AttestationException('Danh sách thu hồi của Google sai định dạng');
        }

        $serials = [];
        foreach ($entries as $serial => $info) {
            $serials[self::normalizeSerial((string) $serial)] = $info;
        }
        Cache::put(self::CACHE_KEY, $serials, (int) config('login.attestation.status_cache_ttl'));

        return $serials;
    }

    public static function normalizeSerial(string $hex): string
    {
        $serial = ltrim(strtolower($hex), '0');

        return $serial === '' ? '0' : $serial;
    }
}
```

- [ ] **Step 4: `AttestationVerifier`**

```php
<?php
// app/Services/Attestation/AttestationVerifier.php

namespace Modules\Login\Services\Attestation;

/**
 * Kiem tra chain Key Attestation (leaf dau tien) theo 6 buoc:
 * 1 chuoi ky noi tiep, 2 root cua Google, 3 khong bi thu hoi, 4 doc KeyDescription,
 * 5 challenge / phan cung / package / digest cert ky app, 6 tra public key cua leaf.
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
        if (! in_array($description['security_level'], [1, 2], true)) {
            throw new AttestationException('Key không nằm trong phần cứng');
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
```

- [ ] **Step 5: Chạy test**

Run: `cd $HOST && php artisan test Modules/Login/tests/Unit/AttestationVerifierTest.php`
Expected: 16 passed.

Rồi chạy toàn bộ: `cd $HOST && php artisan test --testsuite=Modules` → PASS.

---

## Task 4: `AttestController` và route

**Files:**
- Modify: `app/Http/Middleware/ApiAuthenticate.php` (gắn request attribute `login_auth`)
- Create: `app/Http/Controllers/Api/V1/AttestController.php`
- Modify: `routes/api.php`
- Test: `tests/Feature/AttestApiTest.php`

**Interfaces:**
- Consumes: `AttestationVerifier::verify()`, `AttestationException` (Task 3); `DeviceGet::getById()`, `Device::app` (sẵn có).
- Produces:
  - Route `POST {login.api.prefix}/attest/challenge` (`login.api.attest.challenge`) → `{data: {challenge: base64}, status: 200}`.
  - Route `POST {login.api.prefix}/attest/register` (`login.api.attest.register`), body `{chain: string[]}` → `{data: {security_level}, status: 200}`.
  - `AttestController::challengeKey(int $deviceId): string` = `"login:attest:{$deviceId}"`.
  - `ApiAuthenticate` gắn `$request->attributes->get('login_auth') === ['device_id' => int, 'app_id' => int]` khi JWT hợp lệ (Task 5 dùng).

- [ ] **Step 1: Viết test hỏng**

```php
<?php
// tests/Feature/AttestApiTest.php

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
```

- [ ] **Step 2: Chạy test, xác nhận hỏng**

Run: `cd $HOST && php artisan test Modules/Login/tests/Feature/AttestApiTest.php`
Expected: FAIL — route `attest/challenge` 404.

- [ ] **Step 3: `ApiAuthenticate` gắn thông tin vào request**

Trong `app/Http/Middleware/ApiAuthenticate.php`, ngay trước `return $next($request);` cuối cùng:

```php
        // Gan vao chinh request nay (khong dua vao singleton AuthApi, an toan voi worker chay lau).
        $request->attributes->set('login_auth', ['device_id' => $device_id, 'app_id' => $auth->getAppId()]);

        return $next($request);
```

- [ ] **Step 4: Controller**

```php
<?php
// app/Http/Controllers/Api/V1/AttestController.php

namespace Modules\Login\Http\Controllers\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Modules\Login\Http\Controllers\Controller;
use Modules\Login\Services\Attestation\AttestationException;
use Modules\Login\Services\Attestation\AttestationVerifier;
use Modules\Login\Services\DeviceService\DeviceGet;

/**
 * Dang ky public key cua key trong Android Keystore cho device dang dang nhap (JWT).
 */
class AttestController extends Controller
{
    public static function challengeKey(int $deviceId): string
    {
        return "login:attest:{$deviceId}";
    }

    public function challenge(Request $request)
    {
        $challenge = random_bytes(32);
        Cache::put(
            self::challengeKey($this->deviceId($request)),
            base64_encode($challenge),
            (int) config('login.attestation.challenge_ttl')
        );

        return $this->response(['challenge' => base64_encode($challenge)]);
    }

    public function register(Request $request, AttestationVerifier $verifier)
    {
        $data = $request->validate([
            'chain' => ['required', 'array', 'min:2', 'max:10'],
            'chain.*' => ['required', 'string'],
        ]);
        $deviceId = $this->deviceId($request);

        // pull = doc xong xoa -> challenge chi dung 1 lan
        $challenge = Cache::pull(self::challengeKey($deviceId));
        if (empty($challenge)) {
            return $this->response(['message' => 'Challenge không tồn tại hoặc đã hết hạn'], 400);
        }

        $device = DeviceGet::getById($deviceId);
        $package = $device?->app?->package_id;
        if (empty($package)) {
            return $this->response(['message' => 'Attestation: Thiết bị không gắn với app'], 403);
        }

        // Khong dung config("...signature_digests.$package"): package co dau cham.
        $allDigests = config('login.attestation.signature_digests') ?? [];
        $digests = (array) ($allDigests[$package] ?? []);

        try {
            $result = $verifier->verify($data['chain'], base64_decode($challenge), $package, $digests);
        } catch (AttestationException $e) {
            return $this->response(['message' => 'Attestation: '.$e->getMessage()], 403);
        }

        $device->update([
            'public_key_pem' => $result['public_key_pem'],
            'security_level' => $result['security_level'],
            'attested_at' => now(),
        ]);

        return $this->response(['security_level' => $result['security_level']]);
    }

    /**
     * Device cua chinh request nay (ApiAuthenticate gan), khong doc singleton AuthApi.
     */
    private function deviceId(Request $request): int
    {
        return (int) ($request->attributes->get('login_auth')['device_id'] ?? 0);
    }
}
```

- [ ] **Step 5: Route**

`routes/api.php` thay toàn bộ bằng:

```php
<?php

use Illuminate\Support\Facades\Route;
use Modules\Login\Http\Controllers\Api\V1\AttestController;
use Modules\Login\Http\Controllers\Api\V1\AuthController;
use Modules\Login\Http\Middleware\ApiAuthenticate;

/*
| Prefix lay tu config login.api.prefix (mac dinh api/v1/auth).
*/

Route::post('/add-device', [AuthController::class, 'addDevice'])->name('login.api.add-device');

if (config('login.attestation.enabled')) {
    Route::middleware(ApiAuthenticate::class)->prefix('attest')->group(function () {
        Route::post('/challenge', [AttestController::class, 'challenge'])->name('login.api.attest.challenge');
        Route::post('/register', [AttestController::class, 'register'])->name('login.api.attest.register');
    });
}
```

- [ ] **Step 6: Chạy test**

Run: `cd $HOST && php artisan test --testsuite=Modules`
Expected: PASS toàn bộ (gồm 9 test `AttestApiTest`).

---

## Task 5: Middleware `signed.device`

**Files:**
- Create: `app/Http/Middleware/VerifyDeviceSignature.php`
- Modify: `app/Providers/LoginServiceProvider.php` (`registerMiddleware`)
- Test: `tests/Feature/SignedRequestTest.php`

**Interfaces:**
- Consumes: request attribute `login_auth` (Task 4); `DeviceGet::getById()`; cột `public_key_pem` (Task 1).
- Produces: alias `login.attestation.middleware_alias` (`signed.device`) → `VerifyDeviceSignature`; request attribute `login_device` (`Device`) khi hợp lệ.

- [ ] **Step 1: Viết test hỏng**

```php
<?php
// tests/Feature/SignedRequestTest.php

namespace Modules\Login\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Modules\Login\Helpers\LoginHelper;
use Modules\Login\Models\Device;
use OpenSSLAsymmetricKey;
use Tests\TestCase;

class SignedRequestTest extends TestCase
{
    use RefreshDatabase;

    private OpenSSLAsymmetricKey $key;

    private string $token;

    private int $deviceId;

    private int $appId;

    protected function setUp(): void
    {
        parent::setUp();

        config(['login.openssl.device_secret' => 'test-device-secret']);
        Route::middleware(['auth.api', 'signed.device'])->match(['GET', 'POST'], '/_login/signed', fn (Request $request) => [
            'device_id' => $request->attributes->get('login_device')->id,
        ]);
        Route::middleware('signed.device')->post('/_login/signed-only', fn () => ['ok' => true]);

        $data = $this->postJson('/api/v1/auth/add-device', ['secret' => LoginHelper::encodeOpenSsl(json_encode([
            'client_id' => 'client-abc', 'platform' => 'android', 'package_id' => 'com.example.app', 'time' => time(),
        ]))])->json('data');
        $this->token = $data['access_token'];
        $this->deviceId = $data['device']['id'];
        $this->appId = $data['device']['app_id'];

        $this->key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        Device::findOrFail($this->deviceId)->update([
            'public_key_pem' => openssl_pkey_get_details($this->key)['key'],
            'security_level' => 'TEE',
        ]);
    }

    /**
     * Dung chuoi ky giong app: METHOD, /PATH, QUERY, TS, NONCE, DEVICE_ID, APP_ID, SHA256(body).
     */
    private function sign(string $method, string $path, string $query, string $body, array $override = []): array
    {
        $fields = array_merge([
            'ts' => (string) time(),
            'nonce' => bin2hex(random_bytes(16)),
            'device_id' => $this->deviceId,
            'app_id' => $this->appId,
            'key' => $this->key,
        ], $override);
        $payload = implode("\n", [$method, $path, $query, $fields['ts'], $fields['nonce'], $fields['device_id'], $fields['app_id'], hash('sha256', $body)]);
        openssl_sign($payload, $signature, $fields['key'], OPENSSL_ALGO_SHA256);

        return [
            'Authorization' => 'Bearer '.$this->token,
            'X-Timestamp' => $fields['ts'],
            'X-Nonce' => $fields['nonce'],
            'X-Signature' => base64_encode($signature),
        ];
    }

    private function send(string $method, string $uri, string $body, array $headers): TestResponse
    {
        return $this->withHeaders($headers + ['Content-Type' => 'application/json', 'Accept' => 'application/json'])
            ->call($method, $uri, [], [], [], [], $body);
    }

    public function test_accepts_valid_signed_post(): void
    {
        $body = '{"reason":"watch_ad"}';

        $this->send('POST', '/_login/signed', $body, $this->sign('POST', '/_login/signed', '', $body))
            ->assertOk()
            ->assertJson(['device_id' => $this->deviceId]);
    }

    public function test_accepts_valid_signed_get_with_query(): void
    {
        $this->send('GET', '/_login/signed?amount=10', '', $this->sign('GET', '/_login/signed', 'amount=10', ''))
            ->assertOk();
    }

    public function test_rejects_missing_headers(): void
    {
        $headers = $this->sign('POST', '/_login/signed', '', '{}');
        unset($headers['X-Nonce']);

        $this->send('POST', '/_login/signed', '{}', $headers)->assertStatus(400)->assertJsonPath('status', 'missing_signature_header');
    }

    public function test_rejects_too_long_nonce(): void
    {
        $headers = $this->sign('POST', '/_login/signed', '', '{}', ['nonce' => str_repeat('a', 65)]);

        $this->send('POST', '/_login/signed', '{}', $headers)->assertStatus(400)->assertJsonPath('status', 'missing_signature_header');
    }

    public function test_rejects_old_or_millisecond_timestamp(): void
    {
        $old = $this->sign('POST', '/_login/signed', '', '{}', ['ts' => (string) (time() - 301)]);
        $this->send('POST', '/_login/signed', '{}', $old)->assertStatus(401)->assertJsonPath('status', 'request_expired');

        $millis = $this->sign('POST', '/_login/signed', '', '{}', ['ts' => (string) (time() * 1000)]);
        $this->send('POST', '/_login/signed', '{}', $millis)->assertStatus(401)->assertJsonPath('status', 'request_expired');
    }

    public function test_rejects_device_not_attested(): void
    {
        Device::findOrFail($this->deviceId)->update(['public_key_pem' => null]);

        $this->send('POST', '/_login/signed', '{}', $this->sign('POST', '/_login/signed', '', '{}'))
            ->assertStatus(403)->assertJsonPath('status', 'device_not_attested');
    }

    public function test_rejects_modified_body(): void
    {
        $headers = $this->sign('POST', '/_login/signed', '', '{"amount":1}');

        $this->send('POST', '/_login/signed', '{"amount":1000}', $headers)->assertStatus(401)->assertJsonPath('status', 'invalid_signature');
    }

    public function test_rejects_other_path(): void
    {
        $headers = $this->sign('POST', '/_login/other', '', '{}');

        $this->send('POST', '/_login/signed', '{}', $headers)->assertStatus(401)->assertJsonPath('status', 'invalid_signature');
    }

    public function test_rejects_modified_query(): void
    {
        $headers = $this->sign('GET', '/_login/signed', 'amount=10', '');

        $this->send('GET', '/_login/signed?amount=1000', '', $headers)->assertStatus(401)->assertJsonPath('status', 'invalid_signature');
    }

    public function test_rejects_other_method(): void
    {
        $headers = $this->sign('GET', '/_login/signed', '', '');

        $this->send('POST', '/_login/signed', '', $headers)->assertStatus(401)->assertJsonPath('status', 'invalid_signature');
    }

    public function test_rejects_signature_from_other_key(): void
    {
        $other = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        $headers = $this->sign('POST', '/_login/signed', '', '{}', ['key' => $other]);

        $this->send('POST', '/_login/signed', '{}', $headers)->assertStatus(401)->assertJsonPath('status', 'invalid_signature');
    }

    public function test_rejects_signature_for_other_device_or_app(): void
    {
        $otherDevice = $this->sign('POST', '/_login/signed', '', '{}', ['device_id' => $this->deviceId + 1]);
        $this->send('POST', '/_login/signed', '{}', $otherDevice)->assertStatus(401)->assertJsonPath('status', 'invalid_signature');

        $otherApp = $this->sign('POST', '/_login/signed', '', '{}', ['app_id' => $this->appId + 1]);
        $this->send('POST', '/_login/signed', '{}', $otherApp)->assertStatus(401)->assertJsonPath('status', 'invalid_signature');
    }

    public function test_rejects_non_base64_signature(): void
    {
        $headers = $this->sign('POST', '/_login/signed', '', '{}');
        $headers['X-Signature'] = '%%%';

        $this->send('POST', '/_login/signed', '{}', $headers)->assertStatus(401)->assertJsonPath('status', 'invalid_signature');
    }

    public function test_rejects_replayed_nonce(): void
    {
        $headers = $this->sign('POST', '/_login/signed', '', '{}');

        $this->send('POST', '/_login/signed', '{}', $headers)->assertOk();
        $this->send('POST', '/_login/signed', '{}', $headers)->assertStatus(401)->assertJsonPath('status', 'replayed_request');
    }

    public function test_invalid_signature_does_not_burn_nonce(): void
    {
        $nonce = bin2hex(random_bytes(16));
        $bad = $this->sign('POST', '/_login/signed', '', '{"a":1}', ['nonce' => $nonce]);
        $this->send('POST', '/_login/signed', '{"a":2}', $bad)->assertStatus(401);

        $good = $this->sign('POST', '/_login/signed', '', '{}', ['nonce' => $nonce]);
        $this->send('POST', '/_login/signed', '{}', $good)->assertOk();
    }

    public function test_requires_auth_api_on_same_request(): void
    {
        // Lam "ban" singleton AuthApi nhu request truoc trong worker chay lau
        $this->send('POST', '/_login/signed', '{}', $this->sign('POST', '/_login/signed', '', '{}'))->assertOk();

        $this->send('POST', '/_login/signed-only', '{}', $this->sign('POST', '/_login/signed-only', '', '{}'))
            ->assertStatus(401)->assertJsonPath('status', 'not_authenticated');
    }
}
```

- [ ] **Step 2: Chạy test, xác nhận hỏng**

Run: `cd $HOST && php artisan test Modules/Login/tests/Feature/SignedRequestTest.php`
Expected: FAIL — middleware `signed.device` không tồn tại (`Target class [signed.device] does not exist`).

- [ ] **Step 3: Middleware**

```php
<?php
// app/Http/Middleware/VerifyDeviceSignature.php

namespace Modules\Login\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Response;
use Modules\Login\Services\DeviceService\DeviceGet;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Kiem tra chu ky request bang public key da attest cua device. Dat SAU auth.api.
 *
 * Header: X-Timestamp (epoch giay), X-Nonce (1-64 ky tu), X-Signature (base64 ECDSA-SHA256 DER).
 * Chuoi ky (noi bang \n, khong co \n cuoi):
 *   METHOD, /PATH, QUERY (chuoi query goc, rong neu khong co), TIMESTAMP, NONCE, DEVICE_ID, APP_ID, SHA256_HEX(body)
 */
class VerifyDeviceSignature
{
    /**
     * @param  Closure(Request): (SymfonyResponse)  $next
     */
    public function handle(Request $request, Closure $next): SymfonyResponse
    {
        // Doc tu request (ApiAuthenticate gan), khong doc singleton AuthApi.
        $auth = $request->attributes->get('login_auth');
        if (empty($auth['device_id'])) {
            return $this->deny(401, 'Not auth', 'not_authenticated');
        }

        $timestamp = (string) $request->header('X-Timestamp', '');
        $nonce = (string) $request->header('X-Nonce', '');
        $signature = (string) $request->header('X-Signature', '');
        if (! ctype_digit($timestamp) || $nonce === '' || strlen($nonce) > 64 || $signature === '') {
            return $this->deny(400, 'Missing signature header', 'missing_signature_header');
        }

        $window = (int) config('login.attestation.timestamp_window');
        if (abs(time() - (int) $timestamp) > $window) {
            return $this->deny(401, 'Request expired', 'request_expired');
        }

        $device = DeviceGet::getById((int) $auth['device_id']);
        if (empty($device?->public_key_pem)) {
            return $this->deny(403, 'Device not attested', 'device_not_attested');
        }

        $payload = implode("\n", [
            $request->method(),
            '/'.ltrim($request->path(), '/'),
            (string) $request->server('QUERY_STRING', ''),
            $timestamp,
            $nonce,
            $auth['device_id'],
            $auth['app_id'],
            hash('sha256', $request->getContent()),
        ]);
        $raw = base64_decode($signature, true);
        if ($raw === false || @openssl_verify($payload, $raw, $device->public_key_pem, OPENSSL_ALGO_SHA256) !== 1) {
            return $this->deny(401, 'Invalid signature', 'invalid_signature');
        }

        // Nonce dung 1 lan; chi danh dau SAU khi chu ky dung. Cache::add nguyen tu tren Redis.
        if (! Cache::add("login:nonce:{$auth['device_id']}:{$nonce}", 1, $window * 2)) {
            return $this->deny(401, 'Replayed request', 'replayed_request');
        }

        $request->attributes->set('login_device', $device);

        return $next($request);
    }

    private function deny(int $code, string $message, string $status): SymfonyResponse
    {
        return Response::json(['code' => $code, 'message' => $message.',[VerifyDeviceSignature]', 'status' => $status], $code);
    }
}
```

- [ ] **Step 4: Đăng ký alias**

`app/Providers/LoginServiceProvider.php`: thêm `use Modules\Login\Http\Middleware\VerifyDeviceSignature;` (theo thứ tự chữ cái sau `ApiAuthenticate`) và thay `registerMiddleware()`:

```php
    protected function registerMiddleware(): void
    {
        $router = $this->app->make(Router::class);

        $alias = config('login.api.middleware_alias');
        if (! empty($alias)) {
            $router->aliasMiddleware($alias, ApiAuthenticate::class);
        }

        $signedAlias = config('login.attestation.middleware_alias');
        if (config('login.attestation.enabled') && ! empty($signedAlias)) {
            $router->aliasMiddleware($signedAlias, VerifyDeviceSignature::class);
        }
    }
```

- [ ] **Step 5: Chạy test**

Run: `cd $HOST && php artisan test --testsuite=Modules`
Expected: PASS toàn bộ (gồm 16 test `SignedRequestTest`).

---

## Task 6: README

**Files:**
- Modify: `README.md`

- [ ] **Step 1: Mô tả tính năng**

Trong danh sách đầu README (sau gạch đầu dòng "API auth cho thiết bị"), thêm:

```markdown
- **Android Keystore attestation**: đăng ký public key của key nằm trong phần cứng (TEE/StrongBox) qua Key Attestation, middleware `signed.device` kiểm tra chữ ký từng request (chống sửa request, gửi lại, app bị đóng gói lại).
```

Và dòng yêu cầu thành:

```markdown
Yêu cầu: PHP ^8.2 (ext `openssl`), Laravel 11+, `nwidart/laravel-modules` ^11 | ^12 | ^13. Dùng attestation thì cache store phải là Redis (`Cache::add` nguyên tử).
```

- [ ] **Step 2: Thêm mục mới**

Chèn trước mục `## Chuyển từ module `Login` + `CoreUI` cũ`:

````markdown
## Android Keystore attestation

Mỗi điện thoại sinh một private key trong phần cứng (TEE/StrongBox); backend chỉ lưu public key. Lúc đăng ký, backend kiểm tra chuỗi certificate Key Attestation do Google ký để chắc key nằm trong phần cứng thật và thuộc đúng app. Sau đó mọi request cần bảo vệ phải được ký bằng key đó.

Google chỉ được gọi lúc đăng ký (danh sách cert bị thu hồi, cache 24h). Kiểm tra chữ ký từng request chạy offline.

### Cấu hình

Publish config (`--tag=login-config`) rồi khai báo digest cert ký app theo từng package:

```php
// config/login.php
'attestation' => [
    'signature_digests' => [
        'com.cdt.game' => ['AB:CD:...:EF'],   // chấp nhận dạng Play Console hoặc hex thường
    ],
],
```

- Package phải trùng `apps.package_id` của thiết bị (package gửi lúc `add-device`). Package không có trong map → từ chối đăng ký.
- Digest là SHA-256 của **cert ký app mà người dùng thực sự cài**: dùng Play App Signing thì lấy "App signing key certificate" trong Play Console → App integrity (**không** phải upload key); tự ký thì `apksigner verify --print-certs app.apk`. Khi đổi key ký, thêm digest mới vào mảng trước khi phát hành.
- Root certificate của Google đi kèm module ở `resources/attestation/google_roots.pem`. Cập nhật khi Google công bố root mới:
  ```sh
  curl -s https://android.googleapis.com/attestation/root \
    | php -r 'echo implode("\n", json_decode(stream_get_contents(STDIN), true)), "\n";' > google_roots.pem
  ```
  rồi trỏ `LOGIN_MODULE_ATTESTATION_ROOTS=/đường/dẫn/google_roots.pem` (hoặc cập nhật module).

| Key | Mặc định | Ý nghĩa |
|---|---|---|
| `attestation.enabled` | `true` | Bật route attest + alias middleware |
| `attestation.middleware_alias` | `signed.device` | Tên middleware kiểm tra chữ ký; `null` để không đăng ký |
| `attestation.signature_digests` | `[]` | `package => [digest, ...]` |
| `attestation.roots_path` | file đi kèm module | Env `LOGIN_MODULE_ATTESTATION_ROOTS` |
| `attestation.challenge_ttl` | `300` | Thời gian sống của challenge (giây) |
| `attestation.timestamp_window` | `300` | Độ lệch cho phép của `X-Timestamp` (giây) |

### Đăng ký thiết bị (1 lần: cài app, đổi máy, xoá data)

Cả hai API cần header `Authorization: Bearer <access_token>` lấy từ `add-device`.

1. `POST /api/v1/auth/attest/challenge` → `{"data": {"challenge": "<base64 32 byte>"}}`.
2. App tạo key trong Keystore: EC `secp256r1`, `PURPOSE_SIGN`, `DIGEST_SHA256`, `setAttestationChallenge(<challenge đã decode>)`, ưu tiên `setIsStrongBoxBacked(true)` (lỗi `StrongBoxUnavailableException` thì bỏ, dùng TEE).
3. `KeyStore.getCertificateChain(alias)` → base64 từng cert (DER), leaf đứng đầu → `POST /api/v1/auth/attest/register` với `{"chain": ["...", "...", ...]}`.
   - `200 {"data": {"security_level": "TEE"|"StrongBox"}}`
   - `400` challenge không có / hết hạn / đã dùng → xin challenge mới.
   - `403 {"data": {"message": "Attestation: <lý do>"}}` → chain không hợp lệ.

Challenge chỉ dùng 1 lần, sống 5 phút. Đăng ký lại sẽ thay public key cũ.

### Ký từng request

Bảo vệ route của project:

```php
Route::middleware(['auth.api', 'signed.device', 'throttle:60,1'])->group(function () {
    Route::post('/coins/add', [CoinController::class, 'add']);
});
```

`signed.device` phải đứng **sau** `auth.api`. App gửi thêm 3 header:

| Header | Nội dung |
|---|---|
| `X-Timestamp` | epoch **giây** (không phải mili-giây) |
| `X-Nonce` | chuỗi ngẫu nhiên mới cho mỗi request, tối đa 64 ký tự (vd 32 ký tự hex) |
| `X-Signature` | base64 chữ ký `SHA256withECDSA` (DER) bằng key trong Keystore |

Chuỗi được ký, nối bằng `\n`, **không** có `\n` cuối:

```
POST                       method viết hoa
/api/coins/add             path, không có query
amount=10&x=1              query string gốc (phần sau '?', giữ nguyên như trong URL); rỗng nếu không có
1727668800                 X-Timestamp
9f2c...                    X-Nonce
123                        device id (data.device.id từ add-device)
4                          app id (data.device.app_id từ add-device)
e3b0c442...                SHA-256 hex chữ thường của body gốc (body rỗng → hash của chuỗi rỗng)
```

Lỗi trả `{"code", "message", "status"}`:

| HTTP | `status` | Nguyên nhân |
|---|---|---|
| 401 | `not_authenticated` | Route thiếu `auth.api` trước `signed.device` |
| 400 | `missing_signature_header` | Thiếu/sai header |
| 401 | `request_expired` | `X-Timestamp` lệch quá 300 giây |
| 403 | `device_not_attested` | Thiết bị chưa đăng ký key → chạy lại bước đăng ký |
| 401 | `invalid_signature` | Chữ ký sai (body/path/query/method bị sửa, hoặc app dựng chuỗi khác server) |
| 401 | `replayed_request` | Nonce đã dùng |

Controller lấy thiết bị đã xác minh qua `$request->attributes->get('login_device')`.

### Viết controller nghiệp vụ

Chữ ký chỉ chứng minh request đến từ app thật trên máy thật; controller vẫn phải:

- **Server tự quyết giá trị** (số coin, giá item), không lấy số từ request.
- **Idempotent** theo `request_id` (UUID do app sinh): bảng giao dịch có unique `(device_id, request_id)`, dùng `firstOrCreate` và chỉ cộng khi `wasRecentlyCreated`.
- Chạy trong `DB::transaction`.

```php
private const REWARDS = ['watch_ad' => 10, 'daily_login' => 5];

public function add(Request $request)
{
    $data = $request->validate([
        'reason' => 'required|in:'.implode(',', array_keys(self::REWARDS)),
        'request_id' => 'required|uuid',
    ]);
    $device = $request->attributes->get('login_device');
    $amount = self::REWARDS[$data['reason']];

    return DB::transaction(function () use ($device, $data, $amount) {
        $tx = CoinTransaction::firstOrCreate(
            ['device_id' => $device->id, 'request_id' => $data['request_id']],
            ['reason' => $data['reason'], 'amount' => $amount],
        );
        if ($tx->wasRecentlyCreated) {
            // cộng coin cho tài khoản gắn với $device
        }

        return ['added' => $tx->wasRecentlyCreated ? $amount : 0];
    });
}
```

### Checklist trước production

- [ ] `signature_digests` đúng app release (Play App Signing → app signing key).
- [ ] `google_roots.pem` có đủ root hiện hành; có lịch kiểm tra cập nhật.
- [ ] Cache store là Redis.
- [ ] Toàn bộ API chạy qua HTTPS.
- [ ] **Đã thử đăng ký với chain thật từ thiết bị (TEE và StrongBox)**. Test của module dùng chain tự tạo nên chưa chứng minh được việc đọc chain thật.
- [ ] Rate limit (`throttle`) cho API nhạy cảm.
````

- [ ] **Step 3: Cập nhật mục Test**

Trong mục `## Test`, thay câu đầu bằng:

```markdown
Tests nằm trong `tests/Feature` và `tests/Unit` (namespace `Modules\Login\Tests`, extends `Tests\TestCase` của project; helper sinh chain attestation giả ở `tests/Support`). Project cần merge-plugin như bước cài đặt để nạp `autoload-dev` của module (`composer update --lock` sau khi cấu hình). Chạy trong project đã cài module, thêm vào `phpunit.xml`:
```

- [ ] **Step 4: Kiểm tra toàn bộ lần cuối**

Run:
```bash
cd $HOST && php artisan test --testsuite=Modules
cd /home/huyct/code/login-module && composer validate --no-check-publish && for f in $(find app config database routes tests -name '*.php'); do php -l $f > /dev/null || echo "FAIL $f"; done
cd $HOST && php artisan route:list --path=api/v1/auth
```
Expected: toàn bộ test PASS (11 cũ + 3 + 5 + 16 + 9 + 16 = 60); composer valid; không có FAIL lint; route list có `add-device`, `attest/challenge`, `attest/register`.

- [ ] **Step 5: Báo lại người dùng** — liệt kê file thay đổi (`git status`), không commit.
