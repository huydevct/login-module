# Android Keystore attestation cho module Login — Design

Nguồn yêu cầu: `laravel-keystore-backend.md`. Tài liệu này ghi lại cách áp dụng nó vào module `Login`
và những chỗ cố ý làm khác tài liệu gốc.

## Mục tiêu

Project dùng module chặn được request giả mạo từ app Android:
- key giả lập bằng phần mềm, chain tự tạo, cert bị Google thu hồi;
- app bị sửa rồi ký lại (repackage), app khác package;
- request bị sửa (method, path, query, body), bị gửi lại, hoặc quá cũ.

Hoàn thành khi module có:
1. API đăng ký thiết bị: cấp challenge → kiểm tra chain attestation → lưu public key.
2. Middleware `signed.device` để project gắn vào các route cần bảo vệ.

Ngoài phạm vi: nghiệp vụ coin (mục 9 tài liệu gốc) — chỉ viết thành hướng dẫn trong README.
Play Integrity — không làm.

## Quyết định chính

| Chủ đề | Tài liệu gốc | Module |
|---|---|---|
| Xác thực | `auth:sanctum`, `$request->user()` | `auth.api` (device JWT sẵn có). Thiết bị lấy từ JWT, không nhận `device_id` từ app |
| Lưu thiết bị | Bảng `devices` mới có `user_id` | Thêm 3 cột vào bảng `devices` sẵn có |
| Package / digest | 1 `ANDROID_PACKAGE` + 1 danh sách digest | Package phải bằng `apps.package_id` của thiết bị; digest cho phép khai trong config theo từng package |
| Chống chiếm thiết bị (409) | Có | Bỏ — không có user; thiết bị đã được JWT xác định |
| Header | `X-Device-Id`, `X-Timestamp`, `X-Nonce`, `X-Signature` | Bỏ `X-Device-Id` |
| Chuỗi ký | METHOD, PATH, TS, NONCE, DEVICE_ID, USER_ID, SHA256(body) | METHOD, PATH, **QUERY**, TS, NONCE, DEVICE_ID, **APP_ID**, SHA256(body) |
| Lỗi | `abort()` | Verifier ném `AttestationException`; controller/middleware trả JSON theo format sẵn có |
| ASN.1 | phpseclib | phpseclib `^3.0` (dependency mới) |

## Dữ liệu

Migration mới `..._add_attestation_columns_to_devices_table.php`, cùng kiểu migration users sẵn có
(kiểm tra `hasColumn` trước khi thêm; `down()` xoá đúng các cột này):

| Cột | Kiểu | Ý nghĩa |
|---|---|---|
| `public_key_pem` | text, nullable | Public key của cert leaf; null = chưa attest |
| `security_level` | string(20), nullable | `TEE` hoặc `StrongBox` |
| `attested_at` | timestamp, nullable | Lần attest thành công gần nhất |

`Device` model: thêm 3 cột vào `$fillable`, cast `attested_at` → `datetime`.

Challenge và nonce lưu trong `Cache`, không có bảng:
- `login:attest:{device_id}` — challenge base64, TTL `challenge_ttl`.
- `login:nonce:{device_id}:{nonce}` — TTL `timestamp_window * 2`.

Production cần cache store có `add` nguyên tử (Redis). Ghi trong README.

## Config `login.attestation`

```php
'attestation' => [
    'enabled' => true,                              // bật route attest + middleware alias
    'middleware_alias' => 'signed.device',          // null = không đăng ký alias

    // package => danh sách SHA-256 (hex thường, không ':') của cert ký app đang phát hành.
    // Package không có trong map → từ chối đăng ký.
    'signature_digests' => [],

    // Bundle root certificate của Google (PEM, nhiều cert). Mặc định file đi kèm module.
    'roots_path' => null,                           // null = module_path('Login', 'resources/attestation/google_roots.pem')

    // Từ chối máy mở khoá bootloader / boot state khác Verified.
    'require_verified_boot' => env('LOGIN_MODULE_ATTESTATION_REQUIRE_VERIFIED_BOOT', true),

    'status_url' => 'https://android.googleapis.com/attestation/status',
    'status_cache_ttl' => 86400,

    'challenge_ttl' => 300,
    'timestamp_window' => 300,
],
```

## Thành phần

Mỗi unit một việc, test độc lập được.

### `Services/Attestation/KeyDescriptionParser`
- `parse(string $leafDer): array{security_level:int, challenge:string, package:string, digests:string[]}`
- Đọc extension OID `1.3.6.1.4.1.11129.2.1.17` → `KeyDescription`; lấy tag `[709]`
  (`attestationApplicationId`) trong `softwareEnforced`, decode DER bên trong.
- `digests` là hex thường. Package lấy từ phần tử đầu của `package_infos`.
- Thiếu extension / thiếu `[709]` / không đọc được package → ném `AttestationException`.

### `Services/Attestation/GoogleAttestationStatus`
- `revokedSerials(): array<string, mixed>` — `GET status_url`, lấy `entries`, cache `status_cache_ttl`.
- Google lỗi hoặc timeout (10s) → ném `AttestationException` (đăng ký thất bại, chặn cho an toàn).
- Serial so sánh ở dạng hex thường, bỏ số 0 đầu.

### `Services/Attestation/AttestationVerifier`
- `verify(array $chainB64, string $challenge, string $package, array $allowedDigests): array{public_key_pem:string, security_level:string}`
- Các bước:
  1. Decode base64 từng cert (strict); cert[i] do cert[i+1] ký, cert cuối tự ký. Mọi cert từ #1 trở đi
     phải là CA (`basicConstraints CA:TRUE`, `keyUsage` nếu có phải có Certificate Sign) và **không** mang
     extension attestation — chặn cert giả do key phần cứng thật của app khác ký rồi đặt lên đầu chain.
  2. Public key cert cuối trùng một root trong `roots_path`.
  3. Không serial nào nằm trong danh sách thu hồi.
  4. Parse `KeyDescription` của leaf.
  5. `hash_equals` challenge; `attestationSecurityLevel` và `keyMintSecurityLevel` ∈ {1, 2}; nếu
     `require_verified_boot` (mặc định bật): rootOfTrust `[704]` trong `hardwareEnforced` phải có
     `deviceLocked = true` và `verifiedBootState = Verified (0)`; package khớp; có digest nằm trong `$allowedDigests`.
  6. Trả public key PEM của leaf, `security_level` = `StrongBox` (2) hoặc `TEE` (1).
- Mọi lỗi → `AttestationException` với message nói rõ bước hỏng.

### `Services/Attestation/AttestationException`
Exception riêng của module, message dùng làm lý do trả về client.

### `Http/Controllers/Api/V1/AttestController`
Route (trong `routes/api.php`, prefix `login.api.prefix`, middleware `auth.api`, chỉ đăng ký khi
`attestation.enabled`):
- `POST attest/challenge` → name `login.api.attest.challenge`
- `POST attest/register` → name `login.api.attest.register`

### `Http/Middleware/VerifyDeviceSignature`
Alias `attestation.middleware_alias`, đăng ký trong `LoginServiceProvider` giống `auth.api`.

## Luồng

### Challenge
1. Thiết bị = `device_id` trong request attribute `login_auth` (do `auth.api` gắn).
2. `random_bytes(32)` → lưu cache `login:attest:{device_id}` (base64), TTL `challenge_ttl`.
3. Trả `{challenge}` qua `$this->response()`.

### Register
1. Validate `chain`: array 2–10 phần tử, mỗi phần tử string.
2. `Cache::pull` challenge. Không có → 400.
3. Lấy device (active) + app. `allowed = signature_digests[app.package_id] ?? []`.
4. `AttestationVerifier::verify(chain, challenge, app.package_id, allowed)`.
   `AttestationException` → 403 `{message: 'Attestation: <lý do>'}`.
5. Cập nhật `public_key_pem`, `security_level`, `attested_at = now()`. Đăng ký lại ghi đè key cũ.
6. Trả `{security_level}`.

### Mỗi request (`signed.device`, chạy sau `auth.api`)

Chuỗi ký — nối bằng `\n`, không có `\n` cuối:
```
METHOD           $request->method()                 vd POST
/PATH            '/' . $request->path()             vd /api/coins/add
QUERY            query string gốc (phần sau '?'), rỗng nếu không có
TIMESTAMP        header X-Timestamp (epoch giây)
NONCE            header X-Nonce
DEVICE_ID        từ JWT
APP_ID           từ JWT
SHA256_HEX(body) hash('sha256', $request->getContent())
```

Kiểm tra (rẻ → đắt), lỗi trả `{code, message, status}` giống `ApiAuthenticate`:

| # | Kiểm tra | HTTP | `status` |
|---|---|---|---|
| 0 | Request có attribute `login_auth` do `auth.api` gắn cho chính request đó (không đọc singleton `AuthApi`) | 401 | `not_authenticated` |
| 1a | Content-Type không phải `multipart/*` (PHP không đọc được body gốc → không ký được) | 400 | `unsupported_content_type` |
| 1 | Đủ `X-Timestamp` (số), `X-Nonce` (1–64 ký tự), `X-Signature` | 400 | `missing_signature_header` |
| 2 | `abs(time() - ts) <= timestamp_window` | 401 | `request_expired` |
| 3 | Device có `public_key_pem` | 403 | `device_not_attested` |
| 4 | `openssl_verify(..., OPENSSL_ALGO_SHA256) === 1` (chữ ký ECDSA DER, base64) | 401 | `invalid_signature` |
| 5 | `Cache::add` nonce thành công (chỉ sau khi chữ ký đúng) | 401 | `replayed_request` |

Thành công → `$request->attributes->set('login_device', $device)` rồi chạy tiếp.

## Root certificate của Google

Tải từ `https://android.googleapis.com/attestation/root` một lần, lưu
`resources/attestation/google_roots.pem`. README ghi cách cập nhật và cách trỏ `roots_path` sang file khác.

## Test

Tests dùng `Tests\TestCase` của project host → chạy trong một project Laravel tạm (scratchpad) cài
module qua path repository. Chain dùng trong test là **tự tạo**; README ghi rõ phải kiểm tra lại với
chain từ thiết bị thật (TEE + StrongBox) trước production.

Helper test sinh: root test (tự ký) → intermediate → leaf EC P-256 có extension `KeyDescription`
(tham số hoá challenge, security level, package, digests, có/không `[709]`).

- `KeyDescriptionParser`: đọc đúng các trường; thiếu extension; thiếu `[709]`.
- `AttestationVerifier` (roots_path → root test, `Http::fake` status): hợp lệ (TEE, StrongBox); chain
  ghép sai; root lạ; serial bị thu hồi; Google lỗi; challenge sai; Software; sai package; sai digest;
  base64 hỏng.
- `AttestController`: challenge → register thành công, lưu cột; register không có challenge (400);
  challenge dùng lần 2 (400); package không có trong map (403); không có JWT (401).
- `VerifyDeviceSignature`: hợp lệ; thiếu header; timestamp cũ; device chưa attest; sửa body; đổi path;
  sửa query; đổi method; nonce gửi lại; chữ ký của key khác.
- Các test sẵn có (`ApiAuthTest`, `WebLoginTest`) vẫn pass.

## README

Thêm mục "Android Keystore attestation": cấu hình (`signature_digests`, cách lấy digest từ Play Console),
2 API, header + chuỗi ký phía app (kèm ghi chú Android: EC secp256r1, `SHA256withECDSA`,
`setAttestationChallenge`, ưu tiên StrongBox), dùng middleware `auth.api` + `signed.device`, nguyên tắc
viết controller nghiệp vụ (server tự quyết giá trị, idempotent theo `request_id`, transaction),
cập nhật root, yêu cầu Redis, checklist production.
