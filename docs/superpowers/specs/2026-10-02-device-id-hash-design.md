# Định danh thiết bị bằng `device_id_hash` — Design

Nguồn: `docs/device-id-storage.md`. Ghi lại cách áp dụng vào module `Login` và các chỗ cố ý khác tài liệu.

## Quyết định

| Chủ đề | Quyết định |
|---|---|
| Hàm băm | `DeviceIdHasher::hashDevice($appId, $deviceId)` = `md5(norm($appId).':'.norm($deviceId), true)` với `norm = strtolower(trim())` áp dụng **từng phần** (trim cả chuỗi đã ghép sẽ bỏ sót khoảng trắng đầu `device_id` — lỗi này cũng có trong công thức của tài liệu gốc) (16 byte), `VERSION = 1`, `hashHex()` cho log. Mọi chỗ đọc/ghi hash đi qua hàm này. |
| Khác tài liệu: dấu `:` | Tài liệu nối thẳng `$appId.$deviceId` → `(1, "2abc")` và `(12, "abc")` trùng hash. Thêm `:` để tách. |
| `device_id` đưa vào hash | Như cách module đang tính `client_id`, bỏ hậu tố: app trong `api.raw_client_id_app_ids` → `trim(client_id)`; app khác → `StringHelper::filter(client_id)`. |
| Cột `client_id` | Giữ định dạng cũ (`<base>_<app_id>`, hoặc `<base>` cho app raw) để tương thích. |
| Schema | Migration mới: `device_id_hash BINARY(16) NULL`, UNIQUE `uk_devices_device_id_hash`. Không sửa migration cũ. Giữ `client_id_md5` (vẫn ghi) + các index cũ để rollback được; dọn ở bản sau. |
| Model | `$hidden` thêm `device_id_hash`; scope `forDeviceId($deviceId, $appId)`; không dùng cast cho cột hash. |
| Đăng ký | Tìm theo hash → dự phòng theo `client_id_md5` trong dòng chưa có hash (điền hash) → `createOrFirst(['device_id_hash' => …])`; nếu INSERT vướng UNIQUE `client_id_md5` (máy chạy code cũ vừa tạo cùng thiết bị trong lúc deploy) thì nhận dòng đó (đọc qua kết nối ghi) (createOrFirst: chống race, đọc lại qua kết nối ghi). Không dùng `insertOrIgnore` (đọc lại có thể rơi vào replica, và nuốt lỗi ghi khác). |
| Lỗi | `add-device` bắt lỗi DB: `report($e)`, trả 500 `Register device error!`; chỉ hiện message gốc khi `APP_DEBUG=true` **và** message là UTF-8 hợp lệ (tài liệu gốc trả message gốc cả trên production — lộ SQL và host DB). |
| Backfill | `php artisan login:backfill-device-hash {--chunk=1000} {--all}`, duyệt id tăng dần, dòng cũ nhất nhận hash; dòng trùng giữ NULL và được liệt kê; liệt kê dòng `client_id` không đúng định dạng; chạy lại an toàn; `--all` tính lại toàn bộ (khi tăng `VERSION`). |
| Tìm ở trang admin | `client_id` + `app_id` → theo hash (chấp nhận `client_id` có/không hậu tố); chỉ `client_id` → theo cột `client_id`. |

## Quy trình nâng cấp

1. `php artisan migrate` (chỉ thêm cột + index).
2. Deploy code mới (add-device tự điền hash cho thiết bị cũ khi chúng đăng ký lại).
3. `php artisan login:backfill-device-hash` — xem báo cáo dòng trùng / sai định dạng.
4. Bản sau: migration xoá `client_id_md5` và các index không dùng (`app_id`, `active`, `platform`).

## Test

Hasher (chuẩn hoá, tách app, dấu `:`, ID số); add-device (khác hoa/thường → cùng thiết bị, khác app → 2 thiết bị, dòng cũ chưa có hash, dòng trùng, race, JSON không chứa cột binary, lỗi DB trả message hợp lệ); backfill (thường, trùng, sai định dạng, chạy lại, `--all`); tìm kiếm admin; PHP 8.2–8.5 + dependency thấp nhất.
