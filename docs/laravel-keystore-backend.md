# Backend Laravel tích hợp Android Keystore + Key Attestation

> Tài liệu này mô tả logic và code mẫu để backend Laravel xác minh request từ app Android có ký bằng
> key trong Android Keystore (TEE/StrongBox).
>
> ⚠️ Code mẫu **chưa được chạy thử**. Phần đọc ASN.1 (mục 7.4) dễ sai nhất, cần viết test với chain
> thật (có sẵn chain StrongBox thật trong `tools/tests/test_attest.php` của project SAG).

---

## 1. Ý tưởng tổng quan

- Mỗi điện thoại có một **private key** sinh ra và nằm trong phần cứng (TEE/StrongBox). Không ai lấy
  ra được, kể cả app. App chỉ nhờ phần cứng **ký hộ**.
- Backend **không giữ private key**. Backend chỉ lưu **public key** của từng thiết bị để kiểm tra chữ ký.
- Để tin public key đó, lúc đăng ký backend kiểm tra **Key Attestation**: một chuỗi certificate do
  Google ký, chứng minh key nằm trong phần cứng thật và thuộc đúng app của mình.

Backend có 2 giai đoạn:

| Giai đoạn | Khi nào | Backend làm gì |
|---|---|---|
| **Đăng ký thiết bị** | 1 lần (cài app, đổi máy, xoá data) | Cấp challenge → kiểm tra chain attestation → lưu public key |
| **Mỗi request** | Mọi API cần bảo vệ | Kiểm tra thời gian + nonce + chữ ký bằng public key đã lưu → chạy nghiệp vụ |

```
ĐĂNG KÝ (1 lần)
  App ── POST /attest/challenge {device_id} ──────────► BE: random_bytes(32), lưu cache 5 phút
  App ◄──────────────────────────── {challenge} ──────
  App: tạo key trong Keystore kèm challenge → lấy certificate chain
  App ── POST /attest/register {device_id, chain} ────► BE: kiểm tra chain (6 bước, mục 7)
                                                          → lưu public key vào bảng devices
MỖI REQUEST
  App: ký (method, path, ts, nonce, device_id, user_id, sha256(body)) trong Keystore
  App ── POST /coins/add + header X-Device-Id/X-Timestamp/X-Nonce/X-Signature ──►
                                                     BE middleware: ts, device, chữ ký, nonce
                                                     → Controller: nghiệp vụ
```

---

## 2. Backend có phải gọi Google không?

| Việc | Gọi Google? | Chi tiết |
|---|---|---|
| Root certificate của Google | **Không** gọi lúc chạy | Tải file root 1 lần từ tài liệu Key Attestation của Android (project cũ lấy từ `https://android.googleapis.com/attestation/root`), lưu vào `storage/`. Cập nhật khi Google công bố root mới. |
| Danh sách cert bị thu hồi | **Có** | `GET https://android.googleapis.com/attestation/status`. Không cần API key. Chỉ gọi lúc đăng ký, cache 24h. |
| Kiểm tra chữ ký mỗi request | **Không** | `openssl_verify` với public key đã lưu, offline hoàn toàn. |
| Play Integrity (tuỳ chọn) | Có | `playintegrity.googleapis.com`, cần service account. Là sản phẩm khác, không bắt buộc. |

→ Google chỉ tham gia **lúc đăng ký thiết bị**. Request thường không gọi Google.

Tài liệu tham khảo:
- https://developer.android.com/privacy-and-security/keystore
- https://developer.android.com/privacy-and-security/security-key-attestation

---

## 3. Cấu trúc file trong Laravel

```
config/attestation.php                        cấu hình package + chữ ký app + đường dẫn root
storage/app/attestation/google_roots.pem      root certificate của Google
database/migrations/..._create_devices        bảng thiết bị
database/migrations/..._create_coin_transactions
app/Models/Device.php
app/Models/CoinTransaction.php
app/Services/AttestationVerifier.php          kiểm tra chain attestation (phần khó nhất)
app/Http/Controllers/AttestController.php     API challenge + register
app/Http/Middleware/VerifyDeviceSignature.php kiểm tra chữ ký cho mọi API cần bảo vệ
app/Http/Controllers/CoinController.php       ví dụ nghiệp vụ
```

Cài thư viện đọc ASN.1:

```bash
composer require phpseclib/phpseclib:~3.0
```

Yêu cầu: PHP 8.1+ có extension `openssl`, cache driver **Redis** (để `Cache::add` là nguyên tử).

---

## 4. Cấu hình

```php
// config/attestation.php
return [
    // Package name của app Android
    'package' => env('ANDROID_PACKAGE'),                       // vd: com.cdt.game

    // SHA-256 (hex, chữ thường, không dấu ':') của cert ký app mà NGƯỜI DÙNG THỰC SỰ CÀI.
    // - Dùng Play App Signing: lấy "App signing key certificate" trong Play Console → App integrity
    //   (KHÔNG phải upload key).
    // - Tự ký: apksigner verify --print-certs app.apk
    // Có thể nhiều giá trị, cách nhau dấu phẩy.
    'signature_digests' => array_filter(explode(',', strtolower(env('ANDROID_SIG_DIGESTS', '')))),

    'roots_path' => storage_path('app/attestation/google_roots.pem'),

    // Thời gian sống của challenge và cửa sổ timestamp (giây)
    'challenge_ttl' => 300,
    'timestamp_window' => 300,
];
```

```dotenv
ANDROID_PACKAGE=com.cdt.game
ANDROID_SIG_DIGESTS=ab12cd...ef
CACHE_STORE=redis
```

---

## 5. Database

```php
// database/migrations/xxxx_create_devices_table.php
Schema::create('devices', function (Blueprint $t) {
    $t->id();
    $t->string('device_id', 64)->unique();
    $t->foreignId('user_id')->constrained()->cascadeOnDelete();
    $t->text('public_key_pem');           // public key của thiết bị ("mẫu dấu")
    $t->string('security_level', 20);     // TEE | StrongBox
    $t->timestamp('attested_at');
    $t->timestamps();
});

// database/migrations/xxxx_create_coin_transactions_table.php
Schema::create('coin_transactions', function (Blueprint $t) {
    $t->id();
    $t->foreignId('user_id')->constrained()->cascadeOnDelete();
    $t->uuid('request_id');
    $t->string('reason', 32);
    $t->integer('amount');
    $t->timestamps();
    $t->unique(['user_id', 'request_id']);   // chống cộng trùng khi app gửi lại
});
```

```php
// app/Models/Device.php
class Device extends Model
{
    protected $fillable = ['device_id', 'user_id', 'public_key_pem', 'security_level', 'attested_at'];
    protected $casts = ['attested_at' => 'datetime'];
}

// app/Models/CoinTransaction.php
class CoinTransaction extends Model
{
    protected $fillable = ['user_id', 'request_id', 'reason', 'amount'];
}
```

Nonce và challenge **không cần bảng**, lưu trong `Cache` (Redis) có TTL.

---

## 6. Routes

```php
// routes/api.php
use App\Http\Controllers\AttestController;
use App\Http\Controllers\CoinController;

Route::middleware('auth:sanctum')->group(function () {
    // Đăng ký thiết bị
    Route::post('/attest/challenge', [AttestController::class, 'challenge']);
    Route::post('/attest/register',  [AttestController::class, 'register']);

    // Các API cần bảo vệ bằng chữ ký thiết bị
    Route::middleware(['signed.device', 'throttle:60,60'])->group(function () {
        Route::post('/coins/add', [CoinController::class, 'add']);
        // Route::post('/items/buy', [ShopController::class, 'buy']);
    });
});
```

```php
// bootstrap/app.php (Laravel 11+)
->withMiddleware(function (Middleware $middleware) {
    $middleware->alias([
        'signed.device' => \App\Http\Middleware\VerifyDeviceSignature::class,
    ]);
})
```

**Quan trọng:** mọi route đều nằm sau `auth:sanctum`, nên `user_id` luôn lấy từ tài khoản đang đăng
nhập (`$request->user()`), **không bao giờ tin `user_id` do app gửi lên**.

---

## 7. Đăng ký thiết bị

### 7.1 Logic

**API 1: `POST /attest/challenge`**
1. Nhận `device_id`.
2. Sinh `challenge = random_bytes(32)`.
3. Lưu cache key `attest:{user_id}:{device_id}`, sống 5 phút.
4. Trả `challenge` (base64) cho app.

**API 2: `POST /attest/register`**
1. Nhận `device_id` + `chain` (mảng certificate base64, **leaf đứng đầu**).
2. Lấy challenge từ cache và **xoá luôn** (chỉ dùng 1 lần). Không có → 400.
3. Kiểm tra chain qua `AttestationVerifier` (6 bước bên dưới). Sai → 403.
4. Nếu `device_id` đã thuộc user khác → 409.
5. Lưu / cập nhật `devices` với public key của cert leaf.

**6 bước kiểm tra chain trong `AttestationVerifier`:**

| Bước | Kiểm tra gì | Chặn được gì |
|---|---|---|
| 1 | Mỗi cert do cert kế tiếp ký, cert cuối tự ký | Chain bị ghép/sửa |
| 2 | Public key của cert cuối trùng một root của Google | Chain tự tạo, không phải của Google |
| 3 | Không cert nào nằm trong danh sách thu hồi của Google | Máy/key đã bị lộ, bị Google thu hồi |
| 4 | Đọc extension OID `1.3.6.1.4.1.11129.2.1.17` ở cert leaf | — (lấy dữ liệu) |
| 5a | `attestationChallenge` == challenge đã cấp | Dùng lại chain cũ |
| 5b | `attestationSecurityLevel` != 0 (Software) | Key giả lập bằng phần mềm |
| 5c | `packageName` == package của app | App khác |
| 5d | Digest cert ký app nằm trong danh sách cho phép | App bị sửa rồi ký lại (repackage) |
| 6 | Lấy public key của cert leaf để lưu | — |

### 7.2 Controller

```php
// app/Http/Controllers/AttestController.php
namespace App\Http\Controllers;

use App\Models\Device;
use App\Services\AttestationVerifier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class AttestController extends Controller
{
    public function challenge(Request $r)
    {
        $data = $r->validate(['device_id' => 'required|string|max:64']);

        $challenge = random_bytes(32);   // CSPRNG, KHÔNG dùng rand/mt_rand
        Cache::put(
            $this->key($r->user()->id, $data['device_id']),
            base64_encode($challenge),
            config('attestation.challenge_ttl')
        );

        return ['challenge' => base64_encode($challenge)];
    }

    public function register(Request $r, AttestationVerifier $verifier)
    {
        $data = $r->validate([
            'device_id' => 'required|string|max:64',
            'chain'     => 'required|array|min:2|max:10',
            'chain.*'   => 'required|string',
        ]);
        $userId = $r->user()->id;

        // pull = đọc xong xoá → challenge chỉ dùng 1 lần
        $challenge = Cache::pull($this->key($userId, $data['device_id']));
        abort_unless($challenge, 400, 'Challenge không tồn tại hoặc đã hết hạn');

        $result = $verifier->verify($data['chain'], base64_decode($challenge));

        // Không cho chiếm thiết bị đang thuộc tài khoản khác
        $existing = Device::where('device_id', $data['device_id'])->first();
        abort_if($existing && $existing->user_id !== $userId, 409, 'Thiết bị thuộc tài khoản khác');

        Device::updateOrCreate(
            ['device_id' => $data['device_id']],
            [
                'user_id'        => $userId,
                'public_key_pem' => $result['public_key_pem'],
                'security_level' => $result['security_level'],
                'attested_at'    => now(),
            ]
        );

        return ['ok' => true, 'security_level' => $result['security_level']];
    }

    private function key(int|string $userId, string $deviceId): string
    {
        return "attest:{$userId}:{$deviceId}";
    }
}
```

### 7.3 Service `AttestationVerifier`

```php
// app/Services/AttestationVerifier.php
namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use phpseclib3\File\ASN1;
use phpseclib3\File\X509;

class AttestationVerifier
{
    const OID = '1.3.6.1.4.1.11129.2.1.17';   // OID extension Key Attestation của Android
    const STATUS_URL = 'https://android.googleapis.com/attestation/status';

    /**
     * @param string[] $chainB64  certificate DER dạng base64, leaf đứng đầu
     * @param string   $challenge bytes challenge đã cấp (raw)
     * @return array{public_key_pem: string, security_level: string}
     */
    public function verify(array $chainB64, string $challenge): array
    {
        $ders = array_map(
            fn ($b) => base64_decode($b, true) ?: $this->fail('Certificate không hợp lệ'),
            $chainB64
        );
        $pems = array_map(fn ($d) => $this->toPem($d), $ders);

        // B1. Chuỗi ký nối tiếp: cert[i] do cert[i+1] ký; cert cuối tự ký
        foreach ($pems as $i => $pem) {
            $issuer = $pems[$i + 1] ?? $pem;
            if (openssl_x509_verify($pem, openssl_pkey_get_public($issuer)) !== 1) {
                $this->fail("Chữ ký certificate #$i không hợp lệ");
            }
        }

        // B2. Cert cuối phải là root của Google (so public key, không so cả cert)
        $rootKey = $this->publicKeyPem(end($pems));
        if (!in_array($rootKey, $this->googleRootKeys(), true)) {
            $this->fail('Chain không kết thúc ở root của Google');
        }

        // B3. Không cert nào bị Google thu hồi
        $revoked = $this->revokedSerials();
        foreach ($pems as $pem) {
            $serial = strtolower(ltrim(openssl_x509_parse($pem)['serialNumberHex'], '0'));
            if (isset($revoked[$serial])) {
                $this->fail('Certificate đã bị Google thu hồi');
            }
        }

        // B4. Đọc thông tin attestation ở cert leaf
        $desc = $this->parseKeyDescription($ders[0]);

        // B5. Kiểm tra từng thông tin
        if (!hash_equals($challenge, $desc['challenge'])) {
            $this->fail('Challenge không khớp');
        }
        if ($desc['security_level'] === 0) {
            $this->fail('Key không nằm trong phần cứng');
        }
        if ($desc['package'] !== config('attestation.package')) {
            $this->fail('Sai package');
        }
        if (!array_intersect($desc['digests'], config('attestation.signature_digests'))) {
            $this->fail('Sai chữ ký app (có thể app đã bị đóng gói lại)');
        }

        // B6. Public key của leaf = key sẽ dùng để kiểm tra chữ ký mọi request sau này
        return [
            'public_key_pem' => $this->publicKeyPem($pems[0]),
            'security_level' => $desc['security_level'] === 2 ? 'StrongBox' : 'TEE',
        ];
    }

    // ── Gọi Google: danh sách cert bị thu hồi (cache 24h) ──────────────────────
    private function revokedSerials(): array
    {
        // Response dạng: { "entries": { "<serial hex>": { "status": "REVOKED", "reason": "..." } } }
        // Google lỗi → throw → đăng ký thất bại (chặn cho an toàn). Muốn nới thì tự xử lý.
        return Cache::remember('android_attest_status', now()->addDay(), fn () =>
            Http::timeout(10)->get(self::STATUS_URL)->throw()->json('entries', [])
        );
    }

    // ── Root của Google (đọc từ file, cache trong process) ────────────────────
    private function googleRootKeys(): array
    {
        static $keys = null;
        if ($keys !== null) return $keys;

        $bundle = file_get_contents(config('attestation.roots_path'));
        preg_match_all('/-----BEGIN CERTIFICATE-----.*?-----END CERTIFICATE-----/s', $bundle, $m);

        return $keys = array_map(fn ($pem) => $this->publicKeyPem($pem), $m[0]);
    }

    // ── Helpers ─────────────────────────────────────────────────────────────
    private function toPem(string $der): string
    {
        return "-----BEGIN CERTIFICATE-----\n"
            . chunk_split(base64_encode($der), 64, "\n")
            . "-----END CERTIFICATE-----\n";
    }

    private function publicKeyPem(string $certPem): string
    {
        $key = openssl_pkey_get_public($certPem) ?: $this->fail('Không đọc được public key');
        return openssl_pkey_get_details($key)['key'];
    }

    private function fail(string $message): never
    {
        abort(403, 'Attestation: ' . $message);
    }
```

### 7.4 Đọc extension attestation (ASN.1)

Extension OID `1.3.6.1.4.1.11129.2.1.17` chứa cấu trúc `KeyDescription`:

```
KeyDescription ::= SEQUENCE {
  [0] attestationVersion         INTEGER
  [1] attestationSecurityLevel   ENUMERATED   0=Software, 1=TrustedEnvironment, 2=StrongBox
  [2] keyMintVersion             INTEGER
  [3] keyMintSecurityLevel       ENUMERATED
  [4] attestationChallenge       OCTET STRING  ← phải == challenge server cấp
  [5] uniqueId                   OCTET STRING
  [6] softwareEnforced           AuthorizationList  ← chứa tag [709] attestationApplicationId
  [7] hardwareEnforced           AuthorizationList
}

attestationApplicationId (tag [709]) = OCTET STRING chứa DER của:
AttestationApplicationId ::= SEQUENCE {
  package_infos     SET OF SEQUENCE { package_name OCTET STRING, version INTEGER }
  signature_digests SET OF OCTET STRING   ← SHA-256 cert ký app
}
```

```php
    // (tiếp trong class AttestationVerifier)
    private function parseKeyDescription(string $leafDer): array
    {
        $cert = (new X509())->loadX509($leafDer) ?: $this->fail('Không đọc được cert leaf');
        $ext = collect($cert['tbsCertificate']['extensions'] ?? [])->firstWhere('extnId', self::OID)
            ?? $this->fail('Không có extension attestation');

        $kd = ASN1::decodeBER($ext['extnValue'])[0]['content'];   // 8 phần tử KeyDescription

        // Tìm tag [709] trong softwareEnforced
        $tag709 = collect($kd[6]['content'])->firstWhere('constant', 709)
            ?? $this->fail('Thiếu attestationApplicationId');

        // Bên trong [709] là 1 OCTET STRING chứa DER → decode lần nữa
        $appId = ASN1::decodeBER($tag709['content'][0]['content'])[0]['content'];

        $package = $appId[0]['content'][0]['content'][0]['content'] ?? null;
        $package ?? $this->fail('Không đọc được package name');   // không có → TỪ CHỐI, không bỏ qua

        return [
            'security_level' => (int) $kd[1]['content']->toString(),
            'challenge'      => $kd[4]['content'],
            'package'        => $package,
            'digests'        => array_map(fn ($d) => bin2hex($d['content']), $appId[1]['content']),
        ];
    }
}
```

> Các chỉ số mảng (`[0]['content'][0]...`) phụ thuộc cách phpseclib trả dữ liệu. **Bắt buộc test**
> với chain thật và `dd()` từng tầng khi chỉnh.

---

## 8. Kiểm tra chữ ký mỗi request

### 8.1 Logic

App gửi thêm 4 header:

| Header | Nội dung |
|---|---|
| `X-Device-Id` | device_id đã đăng ký |
| `X-Timestamp` | epoch giây |
| `X-Nonce` | chuỗi ngẫu nhiên (vd 32 ký tự hex), mỗi request một giá trị |
| `X-Signature` | base64 chữ ký ECDSA-SHA256 (DER) |

Chuỗi được ký (nối bằng `\n`, không có `\n` cuối):

```
METHOD            vd: POST
/PATH             vd: /api/coins/add
TIMESTAMP
NONCE
DEVICE_ID
USER_ID
SHA256_HEX(body gốc)
```

Lý do chọn cách này thay vì "canonical JSON" như project SAG cũ: ký trên **hash của body gốc** nên
không cần hai phía serialize JSON giống nhau từng byte (chỗ rất dễ lỗi). Có `METHOD` + `PATH` nên
không đổi được endpoint, có `USER_ID` nên không ký hộ user khác.

Thứ tự kiểm tra (rẻ → đắt):
1. Đủ 4 header → không thì 400.
2. `|now - timestamp| ≤ 300s` → không thì 401.
3. Thiết bị tồn tại **và thuộc user đang đăng nhập** → không thì 403.
4. Dựng lại chuỗi, `openssl_verify` bằng public key đã lưu → sai thì 401.
5. `Cache::add` nonce (chỉ thêm được nếu chưa có) → đã có thì 401 (replay).

### 8.2 Middleware

```php
// app/Http/Middleware/VerifyDeviceSignature.php
namespace App\Http\Middleware;

use App\Models\Device;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class VerifyDeviceSignature
{
    public function handle(Request $r, Closure $next)
    {
        $deviceId = $r->header('X-Device-Id');
        $ts       = $r->header('X-Timestamp');
        $nonce    = $r->header('X-Nonce');
        $sig      = $r->header('X-Signature');
        abort_unless($deviceId && $ts && $nonce && $sig, 400, 'Thiếu header chữ ký');

        // 1. Request không quá cũ / quá xa tương lai
        abort_if(abs(time() - (int) $ts) > config('attestation.timestamp_window'), 401, 'Request hết hạn');

        // 2. Thiết bị đã đăng ký VÀ thuộc đúng user đang đăng nhập
        $device = Device::where('device_id', $deviceId)
            ->where('user_id', $r->user()->id)
            ->first();
        abort_unless($device, 403, 'Thiết bị chưa đăng ký');

        // 3. Dựng lại chuỗi app đã ký — app phải dựng Y HỆT
        $payload = implode("\n", [
            $r->method(),
            '/' . $r->path(),
            $ts,
            $nonce,
            $deviceId,
            $r->user()->id,
            hash('sha256', $r->getContent()),   // body gốc, chưa parse
        ]);

        // 4. Kiểm tra chữ ký (chữ ký ECDSA DER từ Android dùng thẳng được)
        $raw = base64_decode($sig, true) ?: '';
        $ok = openssl_verify($payload, $raw, $device->public_key_pem, OPENSSL_ALGO_SHA256);
        abort_unless($ok === 1, 401, 'Chữ ký không hợp lệ');

        // 5. Nonce dùng 1 lần — Cache::add nguyên tử trên Redis, chỉ đánh dấu SAU khi chữ ký đúng
        abort_unless(
            Cache::add("nonce:{$deviceId}:{$nonce}", 1, config('attestation.timestamp_window') * 2),
            401,
            'Request bị gửi lại'
        );

        $r->attributes->set('device', $device);   // controller có thể dùng nếu cần
        return $next($r);
    }
}
```

---

## 9. Controller nghiệp vụ (ví dụ cộng coin)

Controller **không cần biết gì về chữ ký**. Nguyên tắc:
- **Server tự quyết giá trị** (số coin, giá item), không tin số app gửi.
- **Idempotent** theo `request_id`: gửi lại cùng request chỉ xử lý 1 lần.
- Chạy trong **transaction**.

```php
// app/Http/Controllers/CoinController.php
namespace App\Http\Controllers;

use App\Models\CoinTransaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CoinController extends Controller
{
    private const REWARDS = ['watch_ad' => 10, 'daily_login' => 5];

    public function add(Request $r)
    {
        $data = $r->validate([
            'reason'     => 'required|in:' . implode(',', array_keys(self::REWARDS)),
            'request_id' => 'required|uuid',
        ]);
        $amount = self::REWARDS[$data['reason']];   // server quyết, KHÔNG lấy từ request

        return DB::transaction(function () use ($r, $data, $amount) {
            $tx = CoinTransaction::firstOrCreate(
                ['user_id' => $r->user()->id, 'request_id' => $data['request_id']],
                ['reason' => $data['reason'], 'amount' => $amount],
            );

            if ($tx->wasRecentlyCreated) {
                $r->user()->increment('coins', $amount);
            }

            return [
                'added'   => $tx->wasRecentlyCreated ? $amount : 0,
                'balance' => $r->user()->fresh()->coins,
            ];
        });
    }
}
```

---

## 10. Phía app cần làm gì (tóm tắt)

1. Đăng nhập → có token Sanctum.
2. Chưa có key hoặc chưa đăng ký:
   - `POST /attest/challenge` → nhận `challenge`.
   - Tạo key trong Keystore: EC `secp256r1`, `PURPOSE_SIGN`, `DIGEST_SHA256`,
     `setAttestationChallenge(challenge)`, ưu tiên `setIsStrongBoxBacked(true)` (lỗi thì bỏ, dùng TEE).
   - `KeyStore.getCertificateChain(alias)` → base64 từng cert → `POST /attest/register`.
3. Mỗi request: dựng chuỗi ở mục 8.1, ký bằng `Signature.getInstance("SHA256withECDSA")` với
   private key trong Keystore, gửi kèm 4 header.

`sag/src/main/java/com/mct/sag/SagKeystore.java` trong project SAG vẫn dùng lại được cho phần tạo key,
lấy chain và ký — chỉ cần đổi nội dung được ký theo mục 8.1.

---

## 11. Checklist trước khi lên production

- [ ] `ANDROID_PACKAGE` và `ANDROID_SIG_DIGESTS` đúng với app release (Play App Signing → lấy app signing key).
- [ ] File `google_roots.pem` đầy đủ các root hiện hành của Google; có lịch kiểm tra cập nhật.
- [ ] Cache driver là Redis (nonce + challenge cần thao tác nguyên tử).
- [ ] Toàn bộ API chạy qua HTTPS.
- [ ] Test `AttestationVerifier` với chain thật (StrongBox + TEE), chain sai package, chain sai challenge.
- [ ] Test middleware: sửa body, đổi path, gửi lại nonce, timestamp cũ, device của user khác → đều bị chặn.
- [ ] Rate limit cho các API nhạy cảm (`throttle`).
- [ ] (Tuỳ chọn) Bắt đăng ký lại định kỳ (vd 30 ngày); action giá trị cao chỉ cho `StrongBox`.
