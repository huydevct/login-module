<?php

namespace Modules\Login\Services\DeviceService;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Login\Helpers\StringHelper;
use Modules\Login\Models\Device;

class DeviceSet
{
    /**
     * Tim device theo (client_id, app_id), chua co thi tao moi. $device->is_new = true khi vua tao.
     *
     * 1. Tim theo device_id_hash.
     * 2. Du phong cho dong cu chua backfill hash: tim theo client_id_md5, dien hash cho dong do.
     * 3. createOrFirst theo device_id_hash: insert bi trung UNIQUE (2 request song song, hoac SELECT doc
     *    tu replica bi tre) thi doc lai qua ket noi ghi thay vi loi 500.
     *
     * @param  string  $clientId  client_id app gui len (chua loc, chua them hau to)
     */
    public static function findOrCreate(string $clientId, int $appId, int|string $platform): Device
    {
        ['device_id' => $deviceId, 'client_id' => $storedClientId] = self::identity($clientId, $appId);
        $hash = DeviceIdHasher::hashDevice($appId, $deviceId);

        $device = Device::where('device_id_hash', $hash)->first()
            ?? self::claimLegacy($storedClientId, $hash)
            ?? self::create($storedClientId, $hash, $appId, $platform);
        $device['is_new'] = $device->wasRecentlyCreated;

        return $device;
    }

    private static function create(string $storedClientId, string $hash, int $appId, int|string $platform): Device
    {
        try {
            return Device::query()->createOrFirst(['device_id_hash' => $hash], [
                'name' => 'Device_'.Str::random(5),
                'client_id' => $storedClientId,
                'client_id_md5' => md5($storedClientId),   // giu de rollback ve ban cu van chay
                'app_id' => $appId,
                'platform' => self::platform($platform),
                'last_login' => time(),
                'secret' => Str::random(32),
            ]);
        } catch (UniqueConstraintViolationException $e) {
            // Trung client_id_md5 (khong phai hash): code cu (deploy cuon chieu) vua tao cung thiet bi
            // ma chua co hash -> nhan dong do. Doc qua ket noi ghi: dong vua duoc tao.
            return self::claimLegacy($storedClientId, $hash, fresh: true) ?? throw $e;
        }
    }

    /**
     * device_id dua vao hash va client_id luu trong DB (dinh dang cu) cho 1 client_id app gui len.
     * App trong api.raw_client_id_app_ids giu nguyen client_id (chi trim); app khac: loc + hau to "_{app_id}".
     *
     * @return array{device_id: string, client_id: string}
     */
    public static function identity(string $clientId, int $appId): array
    {
        if (in_array($appId, (array) config('login.api.raw_client_id_app_ids', []))) {
            $deviceId = trim($clientId);

            return ['device_id' => $deviceId, 'client_id' => $deviceId];
        }
        $deviceId = StringHelper::filter($clientId);

        return ['device_id' => $deviceId, 'client_id' => $deviceId.'_'.$appId];
    }

    /**
     * device_id dua vao hash, tinh tu client_id DANG LUU trong DB (dung cho backfill va tim o trang admin).
     * App raw: client_id nguyen ven; app khac: bo hau to "_{app_id}" (gia tri da duoc loc khi luu).
     * expected_format = false khi app khong raw ma client_id khong co hau to (du lieu la / config da doi).
     *
     * @return array{device_id: string, expected_format: bool}
     */
    public static function storedDeviceId(string $storedClientId, int $appId): array
    {
        $storedClientId = trim($storedClientId);
        if (in_array($appId, (array) config('login.api.raw_client_id_app_ids', []))) {
            return ['device_id' => $storedClientId, 'expected_format' => true];
        }
        $suffix = '_'.$appId;
        if (str_ends_with($storedClientId, $suffix)) {
            return ['device_id' => substr($storedClientId, 0, -strlen($suffix)), 'expected_format' => true];
        }

        return ['device_id' => $storedClientId, 'expected_format' => false];
    }

    /**
     * Dong do code cu tao (chua co hash): nhan lam device cua hash nay. Neu dong khac da giu hash
     * (dong trung sau chuan hoa, hoac request song song vua nhan) thi tra ve dong dang giu hash.
     */
    private static function claimLegacy(string $storedClientId, string $hash, bool $fresh = false): ?Device
    {
        $query = Device::query();
        if ($fresh) {
            $query->useWritePdo();
        }
        $legacy = $query->where('client_id_md5', md5($storedClientId))->whereNull('device_id_hash')->first();
        if ($legacy === null) {
            return null;
        }

        try {
            // transaction long nhau = savepoint: loi UNIQUE khong lam hong transaction ben ngoai
            DB::transaction(fn () => $legacy->update(['device_id_hash' => $hash]));

            return $legacy;
        } catch (UniqueConstraintViolationException) {
            return Device::query()->useWritePdo()->where('device_id_hash', $hash)->first();
        }
    }

    /**
     * 1: Android, 2: iOS. Nhan ca 'android'/'ios' lan 1/2.
     */
    public static function platform($platform): int
    {
        return in_array(strtolower((string) $platform), ['android', '1'], true) ? 1 : 2;
    }

    public static function createOrUpdate(array $data, string $id = '')
    {
        $device = new Device;
        if ($id != '') {
            $device = Device::where('id', $id)->first();
            if (empty($device)) {
                return false;
            }
        }
        foreach ($data as $key => $value) {
            $device->{$key} = $value;
        }
        $device->save();

        return $device;
    }
}
