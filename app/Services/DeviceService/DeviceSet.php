<?php

namespace Modules\Login\Services\DeviceService;

use Illuminate\Support\Str;
use Modules\Login\Models\Device;

class DeviceSet
{
    /**
     * Tim device theo client_id, chua co thi tao moi. $device->is_new = true khi vua tao.
     *
     * firstOrCreate -> createOrFirst: insert bi trung unique client_id_md5 (2 request add-device
     * song song, hoac doc tu replica bi tre) thi doc lai ban ghi qua ket noi ghi thay vi loi 500.
     */
    public static function findOrCreate(array $data): Device
    {
        $client_id = trim($data['client_id']);
        $client_id_md5 = md5($client_id);
        $device = Device::firstOrCreate(['client_id_md5' => $client_id_md5], [
            'name' => 'Device_'.Str::random(5),
            'client_id' => $client_id,
            'app_id' => $data['app_id'] ?? 0,
            'platform' => self::platform($data['platform']),
            'last_login' => time(),
            'secret' => Str::random(32),
        ]);
        $device['is_new'] = $device->wasRecentlyCreated;

        return $device;
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
