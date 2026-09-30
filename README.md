# Login

Module cho [nwidart/laravel-modules](https://laravelmodules.com/docs/13/getting-started/introduction), gộp từ 2 module `Login` + `CoreUI` cũ:

- **CMS login**: trang `/login`, `/logout`, layout admin CoreUI (`login::layouts.master`) với sidebar/header/footer cấu hình bằng config.
- **API auth cho thiết bị**: `POST /api/v1/auth/add-device` cấp JWT theo device, middleware `auth.api` để bảo vệ route API của project, `LoginHelper::AuthApi()` để lấy device/app hiện tại.
- **Android Keystore attestation**: đăng ký public key của key nằm trong phần cứng (TEE/StrongBox) qua Key Attestation, middleware `signed.device` kiểm tra chữ ký từng request (chống sửa request, gửi lại, app bị đóng gói lại).
- Lệnh artisan `login:create-user`, `login:create-device-token`.

Yêu cầu: PHP ^8.2 (ext `openssl`), Laravel 11+, `nwidart/laravel-modules` ^11 | ^12 | ^13. Dùng attestation thì cache store phải là Redis (`Cache::add` nguyên tử).

## Cài đặt

### 1. Chuẩn bị project (chỉ làm 1 lần)

```sh
composer require nwidart/laravel-modules joshbrw/laravel-module-installer
php artisan vendor:publish --provider="Nwidart\Modules\LaravelModulesServiceProvider" --tag=config
```

Thêm vào `composer.json` của project:

```json
"extra": {
    "merge-plugin": {
        "include": ["Modules/*/composer.json"]
    }
},
"config": {
    "allow-plugins": {
        "joshbrw/laravel-module-installer": true,
        "wikimedia/composer-merge-plugin": true
    }
}
```

### 2. Cài module

Package public trên Packagist:

```sh
composer require huyct/login-module
```

Hoặc từ repo private (GitHub/GitLab/Bitbucket), thêm vào `composer.json` rồi `composer require`:

```json
"repositories": [
    { "type": "vcs", "url": "git@github.com:huyct/login-module.git" }
]
```

Module được `joshbrw/laravel-module-installer` đặt vào `Modules/Login` (tên thư mục suy ra từ tên package `login-module`: bỏ hậu tố `-module` → `Login`).

### 3. Bật module, publish assets, migrate

```sh
php artisan module:enable Login
php artisan vendor:publish --tag=login-assets   # CoreUI css/js/icon -> public/modules/login
php artisan vendor:publish --tag=login-config   # (tuỳ chọn) config/login.php
php artisan migrate
php artisan login:create-user
```

Migrations của module:

| Bảng | Ghi chú |
|---|---|
| `users` | Chỉ **thêm cột** `login_name`, `role`, `device_id`, `social_id`, `social_type`, `avatar`, `refresh_token`, `is_online`, `active` nếu chưa có. Tạo bảng nếu project chưa có `users`. |
| `devices` | Bỏ qua nếu bảng đã tồn tại. |
| `apps` | Bỏ qua nếu bảng đã tồn tại. |

### 4. `.env`

```dotenv
JWT_OPENSSL_DEVICE_SECRET=   # key AES-256-CBC để giải mã secret gửi lên add-device (app mobile dùng chung key)
AUTH_API_JWT_SECRET=         # secret cho LoginHelper::createJwtAuthUser()
LOGIN_MODULE_HOME=/          # route name hoặc URL sau khi login CMS
LOGIN_MODULE_CMS_TITLE="My CMS"
LOGIN_MODULE_BLOCKED_DEVICES_KEY=   # (tuỳ chọn) Redis set chứa id device bị khoá, vd devices:blocked
```

## Sử dụng

### Layout CMS

```blade
@extends('login::layouts.master')

@section('title', 'Dashboard')

@section('content')
    ...
@endsection

@push('scripts') ... @endpush
```

Trang không cần sidebar/header: thêm `@section('guest', true)`.

Menu sidebar/header khai báo trong `config/login.php`:

```php
'cms' => [
    'menu' => [
        ['label' => 'Analytic', 'route' => 'admin.analytic.index', 'icon' => 'cil-speedometer'],
        ['label' => 'API Docs', 'url' => '/api-docs', 'icon' => 'cil-description', 'target' => '_blank'],
    ],
    'header_menu' => [
        ['label' => 'Analytic', 'route' => 'admin.analytic.index'],
    ],
],
```

Item có `route` chưa đăng ký sẽ tự ẩn. Icon lấy theo tên trong CoreUI `free.svg`, dùng trong view: `<x-login::vendors.icon name="cil-user" />`.

Route admin của project chỉ cần middleware `auth` — chưa login sẽ bị chuyển về `route('login')`.

### API auth

App mobile mã hoá payload rồi gửi lên:

```
POST /api/v1/auth/add-device
secret = base64(iv + AES-256-CBC(json, JWT_OPENSSL_DEVICE_SECRET))
json   = {"client_id": "...", "platform": "android|ios", "package_id": "com.example.app", "time": 1700000000}
```

Response: `data.access_token` (JWT, hạn `api.token_ttl_days` ngày) và `data.device`. Secret chỉ hợp lệ trong `api.secret_ttl` giây (bỏ qua khi `APP_DEBUG=true`). Tạo secret để test: `php artisan login:create-device-token`.

Bảo vệ route API của project:

```php
use Modules\Login\Helpers\LoginHelper;

Route::middleware('auth.api')->group(function () {
    Route::get('/me', fn () => [
        'device_id' => LoginHelper::AuthApi()->getDeviceId(),
        'app_id' => LoginHelper::AuthApi()->getAppId(),
    ]);
});
```

Token gửi qua header `Authorization: Bearer <token>` hoặc tham số `access_token`.

### Config chính (`config/login.php`)

| Key | Mặc định | Ý nghĩa |
|---|---|---|
| `web.enabled` / `api.enabled` | `true` | Tắt route web / API của module |
| `web.admin_role` | `1` | Giá trị `users.role` được phép vào CMS |
| `web.home` | `/` | Route name hoặc URL sau khi login |
| `web.user_model` | `null` | Model cho `login:create-user` (mặc định `auth.providers.users.model`) |
| `cms.title`, `cms.footer`, `cms.assets_url` | | Tiêu đề, footer, URL assets đã publish |
| `api.prefix` | `api/v1/auth` | Prefix route add-device |
| `api.middleware_alias` | `auth.api` | Tên middleware; `null` để không đăng ký |
| `api.raw_client_id_app_ids` | `[]` | App id giữ nguyên `client_id` (app khác được thêm hậu tố `_{app_id}`) |
| `api.blocked_devices_redis_key` | `null` | Redis set các device bị khoá |

Views có thể override: `php artisan vendor:publish --tag=login-views` → `resources/views/modules/login`.

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
| `attestation.require_verified_boot` | `true` | Từ chối máy mở khoá bootloader / `verifiedBootState` khác Verified. Env `LOGIN_MODULE_ATTESTATION_REQUIRE_VERIFIED_BOOT=false` khi test trên máy dev, emulator |
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
   - `403 {"data": {"message": "Attestation: <lý do>"}}` → chain không hợp lệ (kể cả máy đã mở khoá bootloader khi bật `require_verified_boot`).

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
/api/coins/add             path (xem ghi chú bên dưới)
amount=10&x=1              query string gốc (phần sau '?', giữ nguyên như trong URL); rỗng nếu không có
1727668800                 X-Timestamp
9f2c...                    X-Nonce
123                        device id (data.device.id từ add-device)
4                          app id (data.device.app_id từ add-device)
e3b0c442...                SHA-256 hex chữ thường của body gốc (body rỗng → hash của chuỗi rỗng)
```

Ghi chú về path:
- Tính **từ gốc ứng dụng Laravel**: app chạy ở `https://host/sub/` thì request `https://host/sub/api/coins/add` ký `/api/coins/add`.
- Giữ **dạng đã encode** như trong URL (OkHttp: `url.encodedPath`, không dùng `url.path`).
- **Không có `/` cuối** (Laravel bỏ dấu `/` cuối); gốc ứng dụng ký là `/`.

Body phải là JSON hoặc dạng khác đọc được nguyên văn; **không hỗ trợ `multipart/form-data`** (PHP không cho đọc body gốc của multipart nên không ký được) — upload file thì làm ở API riêng hoặc gửi base64 trong JSON.

Lỗi trả `{"code", "message", "status"}`:

| HTTP | `status` | Nguyên nhân |
|---|---|---|
| 401 | `not_authenticated` | Route thiếu `auth.api` trước `signed.device` |
| 400 | `unsupported_content_type` | Body `multipart/*` |
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

## Chuyển từ module `Login` + `CoreUI` cũ

| Cũ | Mới |
|---|---|
| `Modules\Login\app\...` | `Modules\Login\...` — bỏ `app\` (vd `Modules\Login\Helpers\LoginHelper`) |
| `App\Helpers\AppHelper::AuthApi()` | `Modules\Login\Helpers\LoginHelper::AuthApi()` |
| `App\Models\App` | `Modules\Login\Models\App` (hoặc giữ model riêng của project, cùng bảng `apps`) |
| `@extends('coreui::layouts.master')` | `@extends('login::layouts.master')` |
| `<x-coreui::vendors.icon>` | `<x-login::vendors.icon>` |
| `/modules/coreui/...` | `/modules/login/...` (sau `vendor:publish --tag=login-assets`) |
| `config('auth.openssl.device_secret')` | `config('login.openssl.device_secret')` (cùng env `JWT_OPENSSL_DEVICE_SECRET`) |
| `config('login.api.secret')` | `config('login.api.secret')` |
| Group `auth.api` trong `bootstrap/app.php` | Module tự đăng ký alias `auth.api` — xoá khai báo cũ |
| Menu sidebar hard-code | `login.cms.menu` |
| Redirect sau login `admin.analytic.index` | `LOGIN_MODULE_HOME=admin.analytic.index` |
| `if ($app->id !== 3)` giữ client_id | `'raw_client_id_app_ids' => [3]` |
| Redis `devices:blocked` luôn kiểm tra | `LOGIN_MODULE_BLOCKED_DEVICES_KEY=devices:blocked` |

## Test

Tests nằm trong `tests/Feature` và `tests/Unit` (namespace `Modules\Login\Tests`, extends `Tests\TestCase` của project; helper sinh chain attestation giả ở `tests/Support`). Project cần merge-plugin như bước cài đặt để nạp `autoload-dev` của module (`composer update --lock` sau khi cấu hình). Chạy trong project đã cài module, thêm vào `phpunit.xml`:

```xml
<testsuite name="Modules">
    <directory>Modules/Login/tests</directory>
</testsuite>
```

```sh
php artisan test --testsuite=Modules
```

## Publish module

1. Push repo này lên GitHub/GitLab/Bitbucket.
2. Gắn tag version: `git tag v1.0.0 && git push --tags`.
3. Public: submit URL repo tại https://packagist.org/packages/submit. Private: dùng `repositories` kiểu `vcs` như trên (hoặc Satis / Private Packagist).
4. Cập nhật ở project: `composer update huyct/login-module`. Không sửa trực tiếp trong `Modules/Login` của project — lần update sau sẽ bị ghi đè.
