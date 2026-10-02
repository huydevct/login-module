# Login

Module cho [nwidart/laravel-modules](https://laravelmodules.com/docs/13/getting-started/introduction), gộp từ 2 module `Login` + `CoreUI` cũ:

- **CMS login**: trang `/login`, `/logout`, trang admin mặc định `/admin`, trang Swagger `/admin/api-docs` cho API của module và layout admin CoreUI (`login::layouts.master`) với sidebar/header/footer cấu hình bằng config.
- **API auth cho thiết bị**: `POST /api/v1/auth/add-device` cấp JWT theo device, middleware `auth.api` để bảo vệ route API của project, `LoginHelper::AuthApi()` để lấy device/app hiện tại.
- **Android Keystore attestation**: đăng ký public key của key nằm trong phần cứng (TEE/StrongBox) qua Key Attestation, middleware `signed.device` kiểm tra chữ ký từng request (chống sửa request, gửi lại, app bị đóng gói lại).
- Lệnh artisan `login:env` (điền biến env vào `.env` + `.env.example`), `login:create-user`, `login:create-device-token`.

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
php artisan login:env                          # thêm biến env của module (bước 4)
php artisan login:create-user
```

Migrations của module:

| Bảng | Ghi chú |
|---|---|
| `users` | Chỉ **thêm cột** `login_name`, `role`, `device_id`, `social_id`, `social_type`, `avatar`, `refresh_token`, `is_online`, `active` nếu chưa có. Tạo bảng nếu project chưa có `users`. |
| `devices` | Bỏ qua nếu bảng đã tồn tại. |
| `apps` | Bỏ qua nếu bảng đã tồn tại. |

### 4. `.env`

```sh
php artisan login:env
```

Lệnh thêm các biến của module vào cuối `.env.example` và `.env` (khối `# Login module`). Chỉ thêm biến còn thiếu, không ghi đè biến đã có, nên chạy lại lúc nào cũng được. File không tồn tại thì bỏ qua (lệnh không tạo `.env`).

| Biến | Giá trị lệnh ghi | Ý nghĩa |
|---|---|---|
| `JWT_OPENSSL_DEVICE_SECRET` | `.env`: sinh ngẫu nhiên 32 ký tự; `.env.example`: trống | Khoá AES-256-CBC giải mã secret gửi lên `add-device`. **App mobile dùng chung khoá này** — project đang chạy thì giữ khoá cũ |
| `AUTH_API_JWT_SECRET` | `.env`: sinh ngẫu nhiên 64 ký tự; `.env.example`: trống | Secret cho `LoginHelper::createJwtAuthUser()` |
| `LOGIN_MODULE_HOME` | `login.admin` | Trang chuyển tới sau khi login CMS: **tên route** (vd `login.admin`) hoặc **đường dẫn** (vd `/admin`). Xem [Trang admin](#trang-admin) |
| `LOGIN_MODULE_CMS_TITLE` | `"${APP_NAME} CMS"` | Tiêu đề CMS |
| `LOGIN_MODULE_CMS_FOOTER` | `"Powered by CoreUI"` | Footer CMS |
| `LOGIN_MODULE_ASSETS_URL` | `/modules/login` | URL assets đã publish |
| `LOGIN_MODULE_BLOCKED_DEVICES_KEY` | trống (tắt) | Redis set chứa id device bị khoá, vd `devices:blocked` |
| `LOGIN_MODULE_ATTESTATION_REQUIRE_VERIFIED_BOOT` | `true` | Xem mục Android Keystore attestation |
| `LOGIN_MODULE_ATTESTATION_ROOTS` | trống (file đi kèm module) | Xem mục Android Keystore attestation |

Biến có giá trị mặc định được ghi rõ giá trị thay vì để trống: `KEY=` rỗng trả về chuỗi rỗng chứ không lấy mặc định trong config (vd `LOGIN_MODULE_ATTESTATION_REQUIRE_VERIFIED_BOOT=` rỗng sẽ tắt kiểm tra bootloader).

## Sử dụng

### Trang admin

Sau khi login, module chuyển tới trang trong `LOGIN_MODULE_HOME` (mặc định `login.admin`).

**Trang admin mặc định.** Module có sẵn trang `GET /admin` (tên route `login.admin`, cần login): layout CoreUI, sidebar hiện menu trong `cms.menu`; menu rỗng thì sidebar chỉ có **API Docs** (nếu bật) và **Đăng xuất**, trang hiện hướng dẫn thêm menu. Đổi đường dẫn bằng `web.admin_path` (vd `'cms'` → `/cms`), tắt bằng `'admin_page' => false` trong `config/login.php`.

**`LOGIN_MODULE_HOME` nhận 2 dạng:**
- **tên route** đã đăng ký, vd `login.admin`, `admin.dashboard`;
- **đường dẫn**, vd `/admin`, `/cms/reports`.

Giá trị không phải tên route sẽ được coi là đường dẫn: vd `.admin` thành `/.admin`, trang đó không tồn tại nên login xong ra **404**. Gặp 404 sau login thì kiểm tra `php artisan route:list` xem tên route/đường dẫn có đúng không.

**Tự làm trang admin của project.** Module chỉ có trang admin mặc định + layout; các trang quản trị khác project tự tạo:

1. Route (chỉ cần middleware `auth` — chưa login sẽ bị chuyển về `route('login')`):
   ```php
   // routes/web.php
   Route::middleware('auth')->prefix('admin')->name('admin.')->group(function () {
       Route::get('/reports', [ReportController::class, 'index'])->name('reports.index');
   });
   ```
2. View kế thừa layout của module (mục [Layout CMS](#layout-cms)):
   ```blade
   {{-- resources/views/admin/reports/index.blade.php --}}
   @extends('login::layouts.master')

   @section('content')
       <div class="body flex-grow-1 px-3">
           <div class="container-lg">...</div>
       </div>
   @endsection
   ```
3. Thêm vào menu trong `config/login.php` (route phải đăng ký trước, nếu không trang CMS sẽ báo lỗi):
   ```php
   'cms' => [
       'menu' => [
           ['label' => 'Dashboard', 'route' => 'login.admin', 'icon' => 'cil-speedometer'],
           ['label' => 'Reports', 'route' => 'admin.reports.index', 'icon' => 'cil-chart'],
       ],
   ],
   ```
4. Muốn login xong vào thẳng trang của project: `LOGIN_MODULE_HOME=admin.reports.index`. Có dashboard riêng rồi thì tắt trang mặc định: `'admin_page' => false`.

### API Docs (Swagger)

Module có sẵn trang Swagger UI cho các API của module ở `GET /admin/api-docs` (tên route `login.api-docs`), spec OpenAPI 3 ở `GET /admin/api-docs/openapi.json` (`login.api-docs.spec`). Cả hai cần **login CMS** (middleware `auth`) — chưa login sẽ bị chuyển về `/login`. Sidebar tự có mục **API Docs** ngay trên **Đăng xuất**.

- Trang có 2 tab: **Hướng dẫn tích hợp app** (luồng tổng quan, tạo `secret` cho add-device, lưu/làm mới JWT, attest Android, ký request bằng OkHttp interceptor — code Kotlin, bảng mã lỗi → app cần làm gì; giá trị prefix/thời hạn lấy từ config đang chạy) và **API (Swagger)**.
- Nội dung Swagger: `add-device`, và khi bật attestation: `attest/challenge`, `attest/register`, kèm mô tả header và chuỗi ký của `signed.device`. Spec sinh theo config hiện tại, nên đổi `api.prefix` hay tắt attestation thì tài liệu tự đổi theo.
- **Try it out**: bấm **Authorize**, dán `access_token` từ `add-device` để gọi các API cần JWT. Tạo `secret` để thử `add-device`: `php artisan login:create-device-token`. Route có `signed.device` không thử được trên Swagger (cần ký bằng key trong Keystore).
- Swagger UI nạp từ CDN (`cdn.jsdelivr.net/npm/swagger-ui-dist@5.33.1`), trình duyệt mở trang cần truy cập được CDN này.
- Đổi đường dẫn: `'api_docs_path' => 'cms/docs'`; tắt trang (và mục sidebar): `'api_docs' => false` trong khối `web` của `config/login.php`. Trang cũng không có khi `api.enabled = false`.

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
        ['label' => 'Hướng dẫn', 'url' => '/guide', 'icon' => 'cil-book', 'target' => '_blank'],
    ],
    'header_menu' => [
        ['label' => 'Analytic', 'route' => 'admin.analytic.index'],
    ],
],
```

Item có `route` chưa đăng ký sẽ **báo lỗi** `RouteNotFoundException` khi render trang (vd `Menu 'Analytic': route [admin.analytic.index] chưa được đăng ký`) — tạo route đó trước, hoặc sửa tên route trong config (`php artisan route:list` để xem tên route). Icon lấy theo tên trong CoreUI `free.svg`, dùng trong view: `<x-login::vendors.icon name="cil-user" />`.

Route admin của project chỉ cần middleware `auth` — chưa login sẽ bị chuyển về `route('login')` (ví dụ đầy đủ ở mục [Trang admin](#trang-admin)).

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
| `web.home` | `login.admin` | Tên route hoặc đường dẫn sau khi login (env `LOGIN_MODULE_HOME`) |
| `web.admin_page` | `true` | Bật trang admin mặc định `login.admin`; `false` khi project tự làm trang admin |
| `web.admin_path` | `admin` | Đường dẫn của trang admin mặc định |
| `web.api_docs` | `true` | Bật trang Swagger `login.api-docs` + mục sidebar "API Docs" |
| `web.api_docs_path` | `admin/api-docs` | Đường dẫn trang Swagger (spec ở `<path>/openapi.json`) |
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

### Cách hoạt động

```
ĐĂNG KÝ (1 lần / thiết bị)
  App ── POST add-device ─────────────────────────────► BE: JWT (device_id, app_id)
  App ── POST attest/challenge  (Bearer JWT) ─────────► BE: random_bytes(32) → cache login:attest:{device_id}, 5 phút
  App ◄──────────────────────────── {challenge} ───────
  App: tạo key EC trong Keystore kèm challenge → KeyStore.getCertificateChain()
  App ── POST attest/register {chain} (Bearer JWT) ──► BE: lấy + xoá challenge → AttestationVerifier (bên dưới)
                                                          → lưu public key vào devices.public_key_pem
MỖI REQUEST CẦN BẢO VỆ
  App: ký chuỗi (method, path, query, ts, nonce, device_id, app_id, sha256(body)) bằng key trong Keystore
  App ── request + Bearer JWT + X-Timestamp/X-Nonce/X-Signature ──►
         auth.api (JWT) → signed.device (chữ ký, offline) → controller của project
```

**Backend tin dữ liệu từ đâu:**

| Dữ liệu | Lấy từ | Không lấy từ |
|---|---|---|
| Thiết bị (`device_id`, `app_id`) | JWT đã được `auth.api` xác minh | Body / header do app gửi |
| Package mong đợi | `apps.package_id` của thiết bị (package gửi lúc `add-device`) | Body của `attest/register` |
| Digest cert ký app được phép | `login.attestation.signature_digests[package]` | Chain do app gửi |
| Root tin cậy | `resources/attestation/google_roots.pem` (hoặc `roots_path`) | Chain do app gửi |
| Public key kiểm tra chữ ký | `devices.public_key_pem` (chỉ ghi sau khi chain qua đủ các bước) | Request |

#### Kiểm tra chain lúc đăng ký (`AttestationVerifier`)

Chain là mảng certificate DER base64, **leaf đứng đầu**, cert cuối là root. Các bước chạy theo thứ tự, sai ở bước nào thì dừng và trả `403 {"data": {"message": "Attestation: <lý do>"}}`:

| # | Kiểm tra | Lý do trả về khi sai | Chặn được |
|---|---|---|---|
| 0 | Mỗi phần tử decode base64 được; chain ≥ 2 cert | `Certificate #i không hợp lệ` / `Chain quá ngắn` | Dữ liệu rác |
| 1a | Cert `i` được ký bởi cert `i+1`, cert cuối tự ký | `Chữ ký certificate #i không hợp lệ` | Chain bị ghép, sửa |
| 1b | Mọi cert từ #1 trở đi là CA (`basicConstraints CA:TRUE`, `keyUsage` nếu có phải có Certificate Sign) | `Certificate #i không phải CA` | Key phần cứng **thật** của app khác ký một cert giả (key phần mềm + extension tự viết) rồi đặt lên đầu chain |
| 1c | Chỉ leaf mang extension attestation | `Certificate #i có extension attestation` | Như trên |
| 2 | Public key của cert cuối trùng một root của Google (so public key, không so cả cert — Google từng phát hành lại root với cùng key) | `Chain không kết thúc ở root của Google` | Chain tự tạo |
| 3 | Không serial nào nằm trong danh sách thu hồi của Google (`/attestation/status`, cache 24h; Google lỗi thì **từ chối**) | `Certificate #i đã bị Google thu hồi` / `Không lấy được danh sách thu hồi của Google` | Key/máy đã bị lộ |
| 4 | Đọc extension `1.3.6.1.4.1.11129.2.1.17` (`KeyDescription`) ở leaf | `Không có extension attestation` / `Thiếu attestationApplicationId` / `Không đọc được package name` | Cert không phải attestation |
| 5a | `attestationChallenge` == challenge đã cấp (so sánh hằng thời gian) | `Challenge không khớp` | Dùng lại chain cũ |
| 5b | `attestationSecurityLevel` **và** `keyMintSecurityLevel` là TEE (1) hoặc StrongBox (2) | `Key không nằm trong phần cứng` | Key sinh bằng phần mềm / giả lập |
| 5c | `rootOfTrust`: `deviceLocked = true` và `verifiedBootState = Verified` (tắt bằng `require_verified_boot`) | `Thiết bị đã mở khoá bootloader hoặc hệ điều hành không nguyên bản` | Máy root / ROM tự build: app thật bị hook để ký request tuỳ ý |
| 5d | Package trong attestation == package của thiết bị | `Sai package` | App khác, hoặc thiết bị app A đăng ký bằng app B |
| 5e | Một trong các digest cert ký app nằm trong `signature_digests[package]` (package không có trong map → luôn sai) | `Sai chữ ký app (có thể app đã bị đóng gói lại)` | App bị sửa rồi ký lại (repackage) |
| 6 | Lấy public key của leaf → `devices.public_key_pem`, `security_level`, `attested_at` | — | — |

Trước khi gọi verifier, controller trả `400` nếu challenge không có / hết hạn / đã dùng (challenge bị **xoá ngay khi đọc**, đăng ký thất bại cũng phải xin challenge mới), và `403 Attestation: Thiết bị không gắn với app` nếu thiết bị không có `app_id`. Đăng ký lại thành công sẽ **thay** public key cũ (cài lại app, xoá data).

#### Kiểm tra mỗi request (`signed.device`)

Chạy sau `auth.api`, từ rẻ đến đắt; sai ở bước nào thì dừng (mã lỗi ở bảng [Ký từng request](#ký-từng-request)):

1. Request có thông tin thiết bị do `auth.api` gắn **cho chính request đó** (`not_authenticated`) — không đọc biến tĩnh `AuthApi`, an toàn với worker chạy lâu (Octane, queue).
2. Body không phải `multipart/*` (`unsupported_content_type`) — PHP không cho đọc body gốc của multipart nên không ký được.
3. Đủ `X-Timestamp` (chỉ chữ số), `X-Nonce` (1–64 ký tự), `X-Signature` (`missing_signature_header`).
4. `|now − X-Timestamp| ≤ 300 giây` (`request_expired`).
5. Thiết bị đã có `public_key_pem` (`device_not_attested`).
6. Dựng lại chuỗi ký từ request thật (body được băm **theo stream**, không đọc cả body vào RAM) và `openssl_verify` (ECDSA-SHA256) bằng public key đã lưu (`invalid_signature`).
7. Đánh dấu nonce `login:nonce:{device_id}:{nonce}` bằng `Cache::add` (nguyên tử trên Redis, sống 600 giây) — đã có thì `replayed_request`. Nonce chỉ bị đánh dấu **sau** khi chữ ký đúng, nên request giả không "đốt" được nonce của request thật.

Qua đủ 7 bước, thiết bị được gắn vào `$request->attributes->get('login_device')` rồi request chạy vào controller.

**Dữ liệu được lưu:**

| Ở đâu | Nội dung | Thời gian sống |
|---|---|---|
| `devices.public_key_pem`, `security_level`, `attested_at` | Public key đã attest, `TEE`/`StrongBox`, lần attest gần nhất | Đến khi đăng ký lại |
| Cache `login:attest:{device_id}` | Challenge (base64) | `challenge_ttl` (300 giây), xoá khi đọc |
| Cache `login:nonce:{device_id}:{nonce}` | Nonce đã dùng | `timestamp_window × 2` (600 giây) |
| Cache `login:attest:google_status` | Danh sách serial bị Google thu hồi | `status_cache_ttl` (24 giờ) |

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

Body phải là JSON hoặc dạng khác đọc được nguyên văn; **không hỗ trợ `multipart/form-data`** (PHP không cho đọc body gốc của multipart nên không ký được) — upload file xem [Body lớn và upload file](#body-lớn-và-upload-file).

#### Body lớn và upload file

`SHA256_HEX(body)` luôn là chuỗi 64 ký tự dù body vài byte hay vài trăm MB, nên chuỗi được ký luôn ngắn; sửa 1 byte bất kỳ trong body là hash đổi hoàn toàn → `invalid_signature`. Không bỏ được dòng này: thiếu nó, ai chặn được request trên máy mình (mitmproxy, Frida) có thể giữ nguyên header chữ ký và sửa body tuỳ ý.

Chi phí theo kích thước body:

| Ở đâu | Chi phí |
|---|---|
| Băm SHA-256 trên server | ~0,06 giây / 100 MB. Middleware băm **theo stream** (`hash_update_stream`), bộ nhớ không tăng theo body — đã thử body 300 MB qua `auth.api` + `signed.device` với `memory_limit=128M`: thành công, đỉnh bộ nhớ ~6 MB |
| Ký trong Keystore trên app | Không phụ thuộc kích thước body (chỉ ký chuỗi ngắn chứa hash). App nên băm theo stream: `MessageDigest.getInstance("SHA-256")` + `update()` từng đoạn của file, không đọc cả file vào RAM |
| Giới hạn kích thước request | Do server quyết, không phải module: PHP `post_max_size` (mặc định **8 MB** → Laravel trả **413** trước khi tới module), nginx `client_max_body_size` (mặc định **1 MB**). Muốn nhận body lớn thì nâng các giới hạn này |
| Body JSON | **Laravel luôn đọc hết body JSON vào RAM** để parse (không liên quan module) — đừng gửi JSON lớn; body 300 MB dạng JSON với `memory_limit=128M` sẽ lỗi hết bộ nhớ dù route không có `signed.device` |

**Upload file qua `signed.device`:** gửi nội dung file làm body với `Content-Type: application/octet-stream` (không dùng multipart), chuỗi ký dùng `SHA256_HEX(nội dung file)`. Controller đọc body **theo stream**, không gọi `$request->getContent()` / `$request->all()` (sẽ đọc cả file vào RAM):

```php
Route::middleware(['auth.api', 'signed.device'])->post('/files', function (Request $request) {
    $path = storage_path('app/uploads/'.Str::uuid());
    $out = fopen($path, 'wb');
    stream_copy_to_stream($request->getContent(true), $out);   // stream, không nạp cả file
    fclose($out);

    return ['size' => filesize($path)];
});
```

**File rất lớn hoặc upload thẳng lên S3:** tách phần ký khỏi phần tải file:

1. App gọi API JSON nhỏ (có `signed.device`) khai báo `sha256` + kích thước file → server lưu lại, trả upload token dùng 1 lần hoặc presigned URL.
2. App upload file bằng endpoint riêng (`auth.api` + token) hoặc thẳng lên S3.
3. Server tính `hash_file('sha256', ...)` của file đã nhận, so với `sha256` trong request có ký ở bước 1 — khớp mới chấp nhận.


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

## Nâng cấp module

Ở project đã cài module, lên version mới:

```sh
composer update huyct/login-module          # hoặc composer require huyct/login-module:^1.2
php artisan migrate                         # migration mới (nếu có)
php artisan login:env                       # chỉ thêm biến env mới, không sửa biến đã có
php artisan optimize:clear                  # xoá cache config/route/view
```

`composer update` ghi đè toàn bộ `Modules/Login` (code, view, route, migration) nên phần mới có ngay. Những thứ **đã publish ra project thì không tự đổi**, kiểm tra thêm:

- **`.env`**: `login:env` không sửa giá trị đã có. Khi version mới đổi giá trị mặc định (vd `LOGIN_MODULE_HOME` từ `/` thành `login.admin`), tự sửa trong `.env` nếu muốn dùng mặc định mới.
- **`config/login.php`** (nếu đã `--tag=login-config`): key mới nằm trong khối đã có (vd `web.admin_page`, `web.admin_path`) không xuất hiện trong file của project — module tự dùng giá trị mặc định. Muốn chỉnh thì copy key đó từ `Modules/Login/config/config.php` sang, hoặc publish lại bằng `--force` (**ghi đè** các chỉnh sửa của project).
- **View đã publish** (`--tag=login-views`, `resources/views/modules/login`): ưu tiên hơn view của module, nên không nhận giao diện mới. Publish lại bằng `--force` hoặc tự gộp thay đổi.
- **Assets** (`public/modules/login`): khi version mới đổi CSS/JS, chạy `php artisan vendor:publish --tag=login-assets --force`.

## Publish module

1. Push repo này lên GitHub/GitLab/Bitbucket.
2. Gắn tag version: `git tag v1.0.0 && git push --tags`.
3. Public: submit URL repo tại https://packagist.org/packages/submit. Private: dùng `repositories` kiểu `vcs` như trên (hoặc Satis / Private Packagist).
4. Cập nhật ở project: `composer update huyct/login-module`. Không sửa trực tiếp trong `Modules/Login` của project — lần update sau sẽ bị ghi đè.
