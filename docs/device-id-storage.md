# Lưu `device_id` tối ưu trên MySQL

Hướng dẫn thiết kế bảng thiết bị (device) cho backend game / mobile app: định danh thiết bị bằng một chuỗi do client gửi lên, cần tra cứu nhanh và đăng ký không bị trùng.

Tài liệu đúc kết từ dự án ArtDrop, gồm cả các lỗi đã gặp thật khi triển khai. Ví dụ code dùng Laravel nhưng nguyên tắc áp dụng cho mọi stack.

---

## TL;DR

- Lưu **hai cột**: `device_id` (chuỗi gốc, để đọc) và `device_id_hash` (`BINARY(16)`, `UNIQUE`, để tra cứu).
- Hash = `MD5` dạng **raw 16 byte** của chuỗi đã **chuẩn hoá** (`lowercase + trim`).
- Nếu thiết bị thuộc nhiều app/tenant: băm **cả cặp** `(app_id, device_id)` vào một cột.
- **Mọi** chỗ đọc/ghi hash phải gọi **một hàm duy nhất**.
- **Ẩn** cột binary khỏi JSON.
- **Chỉ đánh index thứ thật sự được query.**

---

## 1. Vấn đề

`device_id` từ client có định dạng không đồng nhất:

| Nguồn | Ví dụ | Độ dài |
|---|---|---|
| iOS IDFV | `E621E1F8-C36C-495A-93FC-0C247A3E6E5F` | 36 |
| Android ID | `9774d56d682e549c` | 16 |
| Unity `SystemInfo.deviceUniqueIdentifier` | tuỳ nền tảng | thay đổi |
| ID tự sinh của app | bất kỳ | bất kỳ |

Đánh `UNIQUE` thẳng lên cột `VARCHAR` thì:

- Khoá B-tree **dài và không cố định** → index to hơn khoảng 1.6–1.7 lần (đo thực tế, xem mục 2).
- Cùng một thiết bị có thể bị ghi thành nhiều dòng chỉ vì khác hoa/thường hoặc thừa khoảng trắng.
- Khi định danh là cặp `(app_id, device_id)` thì phải dùng composite index trên chuỗi.

> Lưu ý: khi index **vừa RAM**, tốc độ tra cứu và ghi giữa hai cách gần như **không khác nhau**. Khác biệt chỉ xuất hiện khi index **lớn hơn RAM** dành cho MySQL — xem số đo ở mục 2.

---

## 2. Giải pháp: tách "giá trị gốc" và "khoá tra cứu"

```sql
CREATE TABLE devices (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    app_id          INT UNSIGNED  NOT NULL DEFAULT 0,
    device_id       VARCHAR(64)   NOT NULL,          -- giá trị gốc, chỉ để đọc/debug
    device_id_hash  BINARY(16)    NOT NULL,          -- khoá tra cứu
    name            VARCHAR(50)   NULL,
    platform        TINYINT       NOT NULL DEFAULT 0,
    active          TINYINT       NOT NULL DEFAULT 1,
    created_at      TIMESTAMP     NULL,
    updated_at      TIMESTAMP     NULL,
    UNIQUE KEY uk_devices_device_id_hash (device_id_hash)
);
```

Laravel:

```php
Schema::create('devices', function (Blueprint $table) {
    $table->id();
    $table->unsignedInteger('app_id')->default(0);
    $table->string('device_id', 64);
    $table->binary('device_id_hash', length: 16, fixed: true);   // -> BINARY(16)
    $table->string('name', 50)->nullable();
    $table->tinyInteger('platform')->default(0);
    $table->tinyInteger('active')->default(1);
    $table->timestamps();

    $table->unique('device_id_hash', 'uk_devices_device_id_hash');
});
```

### Lợi ích thực sự — đã đo

Benchmark trên MySQL 8.0.41, SSD, `innodb_buffer_pool_size = 128 MB`. `device_id` là chuỗi UUID 36 ký tự **ngẫu nhiên** (giống IDFV / Android ID thật).

#### Kích thước unique index

| | `VARCHAR(64) utf8mb4_unicode_ci` | `BINARY(16)` | Chênh |
|---|---|---|---|
| 5 triệu dòng, insert ngẫu nhiên (trạng thái thật) | 410 MB | 263 MB | **−36%** |
| 1 triệu dòng sau `OPTIMIZE TABLE` (đã nén) | 56.7 MB | 32.6 MB | **−43%** |
| Độ dài khoá (`key_len` trong `EXPLAIN`) | 258 byte | 16 byte | |

#### Trường hợp 1 — index **vừa** RAM (1 triệu dòng)

| | µs / lượt tra cứu (trong MySQL) | µs / request từ PHP |
|---|---|---|
| `VARCHAR utf8mb4_unicode_ci` | 11.5 | 141 |
| `VARCHAR ascii_bin` | 12.5 | — |
| `BINARY(16)` md5 | 14.2 | 149 (gồm cả tính md5: 0.4 µs) |

→ **Không khác nhau có ý nghĩa.** Chênh lệch nằm trong nhiễu đo (trung vị 15 vòng, xoay thứ tự chạy). Ở mức request, ~90% thời gian là round-trip mạng. Collation `utf8mb4_unicode_ci` cũng **không** làm chậm đáng kể như hay được nói.

#### Trường hợp 2 — index **lớn hơn** RAM (5 triệu dòng, index 263–410 MB > buffer pool 128 MB)

Tỉ lệ này tương đương **50 triệu dòng trên server có ~1.3 GB buffer pool**.

| | Insert 5 triệu dòng | Số trang phải đọc ngoài buffer pool / lượt tra cứu |
|---|---|---|
| `VARCHAR` | **1234 giây** | **0.89** |
| `BINARY(16)` | **516 giây** | **0.56** |
| Chênh | binary nhanh **~2.4 lần** | binary đọc ít hơn **~38%** |

- **Ghi:** khác biệt rõ ràng. Khoá ngẫu nhiên khiến mỗi lần chèn rơi vào một trang bất kỳ; index nhỏ hơn thì xác suất trang đó đã có sẵn trong RAM cao hơn.
- **Đọc:** trên máy đo, MySQL dùng `innodb_flush_method=fsync` nên các lần "đọc đĩa" được page cache của OS phục vụ từ RAM, thời gian tra cứu vì thế gần bằng nhau. Để đo đọc đĩa thật, xem bảng dưới.

#### SELECT khi phải đọc đĩa thật (MySQL Docker, `O_DIRECT`, buffer pool 128 MB, 5 triệu dòng)

`O_DIRECT` bỏ qua page cache của OS, nên mỗi lần trượt buffer pool là một lần đọc SSD thật — giống server production có dữ liệu lớn hơn RAM.

| | Mỗi SELECT trong MySQL | Lần đọc đĩa / SELECT | Từ PHP — median | p95 | p99 |
|---|---|---|---|---|---|
| `VARCHAR` | 191 µs | 0.82 | 425 µs | 751 µs | 988 µs |
| `BINARY(16)` | **145 µs** | **0.59** | **398 µs** | 744 µs | 989 µs |
| Chênh | **nhanh hơn ~24%** | ít hơn ~29% | nhanh hơn ~6% | như nhau | như nhau |

- Trong DB, SELECT theo `BINARY(16)` nhanh hơn rõ ràng vì ít phải đọc đĩa hơn (mỗi lần đọc ~230–250 µs trên máy đo).
- Nhìn từ ứng dụng, mỗi request chỉ nhanh hơn ~6% ở median, vì phần lớn thời gian là chi phí kết nối/mạng.
- **Độ trễ đuôi (p95, p99) không khác nhau**: các request chậm nhất ở cả hai cách đều là request phải đọc đĩa.

#### Ngoại suy cho 50 triệu dòng

Từ số đo 5 triệu dòng insert ngẫu nhiên (~86 byte/dòng cho varchar, ~55 byte/dòng cho binary):

| | Unique index ước tính |
|---|---|
| `VARCHAR` | **~4.1 GB** |
| `BINARY(16)` | **~2.6 GB** |

Chưa tính clustered index (dữ liệu bảng) và các index khác — tất cả cùng tranh buffer pool. Ví dụ server 8 GB RAM, buffer pool ~5–6 GB dùng chung cho cả DB: 2.6 GB còn có cơ hội nằm trọn trong RAM, 4.1 GB thì khó.

> **Ở quy mô 50 triệu dòng, nên dùng `BINARY(16)`.** Lợi ích chắc chắn là ghi nhanh hơn (~2.4 lần khi index lớn hơn RAM) và cần ít RAM hơn ~36% để giữ index "nóng". Về đọc: thừa RAM thì như nhau; thiếu RAM thì SELECT trong DB nhanh hơn ~24%, nhưng mỗi request API chỉ nhanh hơn vài %, và độ trễ đuôi không đổi. Đừng chọn cách này chỉ vì kỳ vọng API đọc nhanh hơn hẳn.

#### Bẫy khi tự benchmark: thứ tự của khoá

Hàm `UUID()` của MySQL sinh **UUID v1 theo thời gian** — các giá trị liên tiếp gần như tuần tự. Lần đo đầu dùng nó cho kết quả hoàn toàn ngược: insert varchar **89 giây** so với binary 516 giây, vì varchar được ghi gần như nối đuôi còn MD5 thì xáo trộn.

Hệ quả thực tế:
- **Hash phá vỡ tính tuần tự của khoá.** Nếu ID của bạn **vốn tuần tự** (UUID v7, ID do server sinh theo thời gian…), lưu thẳng `BINARY(16)` của ID đó (vd `UUID_TO_BIN`, mục 7) sẽ tốt hơn băm MD5.
- ID thiết bị từ hệ điều hành (IDFV, Android ID) vốn **ngẫu nhiên**, nên băm không làm mất gì.
- Khi tự đo, phải dùng dữ liệu có **cùng phân bố** với dữ liệu thật.

### Vậy lợi ích nằm ở đâu?

1. **Index nhỏ hơn ~36–43%** → ghi nhanh hơn và cần ít RAM hơn khi bảng lớn (mục trên).
2. **Đúng đắn**: chuẩn hoá hoa/thường + khoảng trắng một lần, tường minh trước khi băm.
3. **Định danh nhiều cột gọn**: cặp `(app_id, device_id)` gộp vào 1 cột, 1 index cố định 16 byte.
4. **Khoá cố định**: không phụ thuộc client gửi ID dài bao nhiêu, định dạng gì.

Dùng `MD5(..., raw)` = **16 byte**. Nếu lưu dạng hex sẽ là 32 ký tự → index to gấp đôi mà không được gì.

> **Kết luận thực tế:** nếu bảng nhỏ (index vừa RAM) và chỉ định danh bằng một `device_id`, index thẳng `VARCHAR` là **hoàn toàn chấp nhận được** — đơn giản hơn, ít bẫy hơn (mục 5). Chọn cách hash khi cần định danh nhiều cột, cần chuẩn hoá chặt, hoặc bảng dự kiến lên hàng chục triệu dòng.

---

## 3. Hàm băm — đặt ở **một chỗ duy nhất**

```php
final class DeviceIdHasher
{
    /** Tăng khi đổi công thức băm. */
    public const VERSION = 1;

    /** Hash cho cột device_id_hash. MỌI nơi đọc/ghi đều phải đi qua hàm này. */
    public static function hashDevice(int|string $appId, string $deviceId): string
    {
        return md5(self::normalize($appId . $deviceId), true); // 16 byte raw
    }

    /** Hex 32 ký tự — chỉ để log/debug. */
    public static function hashHex(int|string $appId, string $deviceId): string
    {
        return bin2hex(self::hashDevice($appId, $deviceId));
    }

    /** Chuẩn hoá TRƯỚC khi băm, nếu không cùng 1 máy sẽ ra nhiều hash. */
    private static function normalize(string $value): string
    {
        return strtolower(trim($value));
    }
}
```

> **Bài học thật:** ban đầu hàm ghi (`findOrCreate`) băm `app_id . device_id`, nhưng 3 hàm tra cứu khác vẫn chỉ băm `device_id`. Kết quả: tra cứu **không bao giờ** tìm thấy thiết bị vừa tạo — và **không có lỗi nào** báo ra, chỉ trả `null`. Gom về một hàm duy nhất là cách duy nhất chống lệch.

### Có nên đưa `app_id` vào hash?

Có, nếu một thiết bị có thể dùng nhiều app (nhiều game của cùng studio, nhiều tenant). Khi đó định danh đúng là **cặp** `(app_id, device_id)`:

- Cùng máy, 2 app → **2 dòng riêng**.
- Vẫn chỉ **1 cột, 1 index** thay vì composite `(app_id, device_id)`.

Chỉ cần nhớ: mọi chỗ tra cứu phải **truyền `app_id` xuống**.

---

## 4. Tra cứu và tạo mới

### Scope tra cứu

```php
class Device extends Model
{
    protected $hidden = ['device_id_hash'];   // xem mục 5.1

    public function scopeForDeviceId(Builder $query, string $deviceId, int $appId): Builder
    {
        return $query->where('device_id_hash', DeviceIdHasher::hashDevice($appId, $deviceId));
    }
}

// Device::forDeviceId($deviceId, $appId)->first();
```

> Cố ý **không** dùng Eloquent cast cho cột hash: cast **không áp dụng cho `where()`**, nên chỉ cần một chỗ quên chuyển đổi là query im lặng trả rỗng. Scope tường minh an toàn hơn.

### Đăng ký idempotent, không cần transaction

```php
public static function findOrCreate(string $deviceId, int $appId, array $attrs): Device
{
    $hash = DeviceIdHasher::hashDevice($appId, $deviceId);

    if ($device = Device::where('device_id_hash', $hash)->first()) {
        return $device;
    }

    // insertOrIgnore bỏ qua Eloquent -> phải tự set timestamps.
    Device::insertOrIgnore([
        'app_id'         => $appId,
        'device_id'      => $deviceId,
        'device_id_hash' => $hash,
        'name'           => $attrs['name'] ?? null,
        'platform'       => $attrs['platform'] ?? 0,
        'created_at'     => now(),
        'updated_at'     => now(),
    ]);

    // Hai request đồng thời: một cái insert, một cái bị UNIQUE bỏ qua -> cả hai đọc ra cùng 1 dòng.
    return Device::where('device_id_hash', $hash)->firstOrFail();
}
```

`UNIQUE` + `INSERT IGNORE` + đọc lại = chống race condition mà không cần lock hay transaction.

### SQL thô

Binding binary qua prepared statement hoạt động bình thường:

```php
DB::selectOne('SELECT id, platform FROM devices WHERE device_id_hash = ? LIMIT 1', [$hash]);
```

Khi chỉ có chuỗi hex (vd copy từ log):

```sql
SELECT * FROM devices WHERE device_id_hash = UNHEX('86fb1c046a6891e54b3552fcdb7c9be1');
```

---

## 5. Các bẫy đã gặp

### 5.1. Cột binary làm hỏng JSON

`BINARY(16)` chứa byte bất kỳ, **không phải UTF-8 hợp lệ**. Trả model ra API sẽ ném:

```
InvalidArgumentException: Malformed UTF-8 characters, possibly incorrectly encoded
```

**Cách xử lý:** `protected $hidden = ['device_id_hash'];` trong model.

### 5.2. Message lỗi cũng có thể chứa binary

`QueryException` in luôn giá trị binding vào message. Nếu `catch` rồi trả `$e->getMessage()` ra JSON thì **response báo lỗi cũng chết** theo, che mất lỗi thật.

```php
} catch (\Exception $e) {
    $message = $e->getMessage();
    if (!mb_check_encoding($message, 'UTF-8')) {
        $message = 'Register device error!';
    }
    return response()->json(['message' => $message], 500);
}
```

### 5.3. `strict_types` và `device_id` dạng số

Nếu hasher khai báo `declare(strict_types=1)` mà client gửi `client_id` dạng số trong JSON, `hashDevice(string ...)` ném `TypeError`. Ép kiểu ở điểm vào:

```php
DeviceIdHasher::hashDevice($appId, (string) $payload['client_id']);
```

### 5.4. Đổi công thức băm = mồ côi dữ liệu cũ

Thêm `app_id`, thêm pepper, đổi cách chuẩn hoá… đều làm **toàn bộ hash cũ không còn khớp**. Thiết bị cũ đăng ký lại sẽ bị tạo **dòng mới**.

Quy trình khi đổi:
1. Tăng `DeviceIdHasher::VERSION`.
2. Viết migration/command **backfill** tính lại `device_id_hash` cho mọi dòng.
3. Deploy backfill **cùng lúc** với code mới.

Để backfill được, phải giữ cột `device_id` gốc — đây là một lý do nữa để không chỉ lưu mỗi hash.

### 5.5. Thừa index

Lỗi rất hay gặp: đã tối ưu khoá tra cứu xuống 16 byte, nhưng migration lại `->index()` hàng loạt cột "cho chắc". Ở ArtDrop từng có:

| Index | Cardinality | Có query dùng? |
|---|---|---|
| `device_id_hash` (UNIQUE) | cao | ✅ |
| `device_id` | cao | ❌ |
| `app_id` | thấp | ❌ |
| `created_at` | cao | ❌ |
| `platform` | **2** | ❌ |
| `active` | **1** | ❌ |

- Mỗi `INSERT` phải cập nhật **mọi** B-tree → 7 cây thay vì 2. Đăng ký thiết bị là endpoint **ghi nhiều**, nên đây là chi phí thật.
- Index trên cột `TINYINT` vài giá trị (`platform`, `active`) gần như **không bao giờ** được optimizer chọn — full scan còn rẻ hơn.

**Quy tắc:** chỉ thêm index khi đã có câu query cụ thể cần nó, và ưu tiên composite theo đúng query, vd thống kê theo app → `INDEX (app_id, created_at)`.

---

## 6. Bảo mật

- `MD5` ở đây dùng để **rút gọn khoá**, không phải để bảo mật.
- Không thể đảo ngược từ hash ra `device_id`, nhưng nếu DB bị lộ, kẻ tấn công **xác nhận được** một `device_id` đang nghi ngờ bằng cách tự băm rồi so.
- Nếu `device_id` được coi là dữ liệu nhạy cảm, thêm **pepper** (hằng số bí mật, không lưu trong DB):

  ```php
  return md5(self::PEPPER . self::normalize($appId . $deviceId), true);
  ```

  Nhớ bump `VERSION` và backfill (mục 5.4).

- Lo ngại xung đột MD5 có chủ đích: dùng `substr(hash('sha256', $value, true), 0, 16)` — vẫn 16 byte, không đổi schema. Với nhu cầu định danh thiết bị thông thường, MD5 128 bit là đủ (xác suất trùng ngẫu nhiên không đáng kể).

---

## 7. Khi nào **không** cần hash?

Nếu **chắc chắn** `device_id` luôn là UUID chuẩn (vd chỉ có iOS IDFV), có thể bỏ hash và chuyển thẳng UUID về 16 byte:

```sql
-- MySQL 8
INSERT INTO devices (device_uuid) VALUES (UUID_TO_BIN('E621E1F8-C36C-495A-93FC-0C247A3E6E5F'));
SELECT BIN_TO_UUID(device_uuid) FROM devices;
```

Ngay khi có nhiều nguồn định dạng khác nhau (Android ID 16 hex, ID tự sinh…) thì hash là cách đồng nhất gọn nhất.

---

## 8. Checklist khi mang sang dự án mới

- [ ] Có cả `device_id` (gốc) và `device_id_hash BINARY(16) UNIQUE`.
- [ ] Băm bằng **raw** 16 byte, không phải hex.
- [ ] Chuẩn hoá `lowercase + trim` **trước** khi băm.
- [ ] Định danh nhiều app/tenant → băm cả `(app_id, device_id)`.
- [ ] **Một** hàm duy nhất ghép + băm; mọi query đi qua nó.
- [ ] `$hidden` cột hash khỏi JSON.
- [ ] `catch` lỗi kiểm tra `mb_check_encoding` trước khi trả message.
- [ ] Ép `(string)` `device_id` ở điểm vào.
- [ ] Đăng ký dùng `INSERT IGNORE` + đọc lại theo hash.
- [ ] Có `VERSION` cho công thức băm và kế hoạch backfill.
- [ ] Không đánh index cho cột chưa có query dùng; tránh index cột cardinality thấp.
