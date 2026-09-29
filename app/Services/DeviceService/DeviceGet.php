<?php

namespace Modules\Login\Services\DeviceService;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Request;
use Modules\Login\Models\Device;

class DeviceGet
{
    /**
     * So device moi theo tung ngay trong thang (ngay cu duoc cache 30 ngay).
     */
    public static function countData($month = null, $year = null): array
    {
        $day_start = 1;
        if (empty($month)) {
            $month = date('m');
        }
        if (empty($year)) {
            $year = date('Y');
        }
        if ($month != date('m')) {
            $day_end = cal_days_in_month(CAL_GREGORIAN, $month, $year);
        } else {
            $day_end = date('d');
        }
        $data_return = [];
        $list_date = [];
        $today = date('d');

        for ($day_get = $day_start; $day_get <= $day_end; $day_get++) {
            $date_get = "$year-$month-$day_get";
            $key = $date_get.'device';
            $label = "$day_get/$month";
            if ($day_get != $today) {
                if (Cache::has($key)) {
                    $data_cache = Cache::get($key);
                    $data_return[$label] = $data_cache['data'];
                    $list_date[] = $label;

                    continue;
                }
            }
            $amount = Device::where('created_at', '>', "$date_get 00:00:00")
                ->where('created_at', '<', "$date_get 23:59:00")
                ->where('active', 1)
                ->count();

            $data = [
                'device' => [
                    'amount' => $amount,
                ],
            ];

            $data_return[$label] = $data;
            $list_date[] = $label;
            if ($day_get != $today) {
                Cache::remember($key, 2592000, function () use ($data, $label) {
                    return [
                        'data' => $data,
                        'label' => $label,
                    ];
                });
            }
        }

        return [
            'label' => $list_date,
            'data' => $data_return,
        ];
    }

    public static function getById(int $db_id): ?Device
    {
        return Device::where('active', 1)->where('id', $db_id)->first();
    }

    public static function getDeviceByAdmin()
    {
        $devices = Device::select('*');

        $device_id = Request::get('device_id', null);
        if ($device_id != null) {
            $devices->where('id', $device_id);
        }

        $active = Request::get('active', null);
        if ($active != null) {
            $devices->where('active', $active);
        }

        $client_id = Request::get('client_id', null);
        if ($client_id != null) {
            $devices->where('client_id_md5', md5(trim($client_id)));
        }

        $app_id = Request::get('app_id', null);
        if ($app_id != null) {
            $devices->where('app_id', $app_id);
        }

        $dateFilter = Request::get('datefilter', null);
        if ($dateFilter != null) {
            $dates = explode(' - ', $dateFilter);
            $devices->whereDate('created_at', '>=', $dates[0])->whereDate('created_at', '<=', $dates[1]);
        }

        return $devices->orderBy('id', 'desc')->paginate(30);
    }
}
