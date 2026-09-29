# Login

Module cho [nwidart/laravel-modules](https://laravelmodules.com/docs/13/getting-started/introduction), gộp từ 2 module `Login` + `CoreUI` cũ:

- **CMS login**: trang `/login`, `/logout`, layout admin CoreUI (`login::layouts.master`) với sidebar/header/footer cấu hình bằng config.
- **API auth cho thiết bị**: `POST /api/v1/auth/add-device` cấp JWT theo device, middleware `auth.api` để bảo vệ route API của project, `LoginHelper::AuthApi()` để lấy device/app hiện tại.
- Lệnh artisan `login:create-user`, `login:create-device-token`.

Yêu cầu: PHP ^8.2, Laravel 11+, `nwidart/laravel-modules` ^11 | ^12 | ^13.

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

Tests nằm trong `tests/Feature` (namespace `Modules\Login\Tests`, extends `Tests\TestCase` của project). Chạy trong project đã cài module, thêm vào `phpunit.xml`:

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
