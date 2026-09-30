<?php

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
