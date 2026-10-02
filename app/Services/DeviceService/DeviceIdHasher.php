<?php

namespace Modules\Login\Services\DeviceService;

/**
 * Khoa tra cuu thiet bi (cot devices.device_id_hash, BINARY(16) UNIQUE).
 * MOI noi doc/ghi hash phai di qua class nay — lech cong thuc o 1 cho la tra cuu tra null am tham.
 *
 * Doi cong thuc (them pepper, doi chuan hoa...) = hash cu khong con khop: tang VERSION va chay
 * `php artisan login:backfill-device-hash --all`.
 */
final class DeviceIdHasher
{
    public const VERSION = 1;

    /**
     * MD5 raw 16 byte cua cap (app_id, device_id). Chuan hoa TUNG phan roi moi ghep (trim ca chuoi
     * da ghep se bo sot khoang trang dau device_id). Dau ':' tach 2 phan: noi thang thi (1, "2abc")
     * va (12, "abc") trung nhau.
     */
    public static function hashDevice(int|string $appId, string $deviceId): string
    {
        return md5(self::normalize((string) $appId).':'.self::normalize($deviceId), true);
    }

    /** Hex 32 ky tu — chi de log/debug. */
    public static function hashHex(int|string $appId, string $deviceId): string
    {
        return bin2hex(self::hashDevice($appId, $deviceId));
    }

    /** Chuan hoa TRUOC khi bam, neu khong cung 1 may (khac hoa/thuong, thua khoang trang) ra nhieu hash. */
    private static function normalize(string $value): string
    {
        return strtolower(trim($value));
    }
}
