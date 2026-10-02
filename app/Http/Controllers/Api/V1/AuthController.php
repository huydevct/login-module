<?php

namespace Modules\Login\Http\Controllers\Api\V1;

use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Modules\Login\Helpers\JwtHelper;
use Modules\Login\Helpers\LoginHelper;
use Modules\Login\Http\Controllers\Controller;
use Modules\Login\Models\App;
use Modules\Login\Services\DeviceService\DeviceSet;

class AuthController extends Controller
{
    /**
     * Dang ky device va cap access token.
     *
     * secret = base64(iv + AES-256-CBC(json)) voi json gom client_id, platform, package_id, time.
     */
    public function addDevice(Request $request)
    {
        $request->validate([
            'secret' => ['required', 'string', 'min:1'],
        ]);
        $data_decode = LoginHelper::decodeOpenSsl($request->secret);
        if (empty($data_decode)) {
            return $this->response(['message' => 'Secret error!'], 401);
        }
        $data_decode = json_decode($data_decode, true);
        if (empty($data_decode) || ! isset($data_decode['client_id'], $data_decode['platform'], $data_decode['time'], $data_decode['package_id'])) {
            return $this->response(['message' => 'Secret error: payload data error!', 'payload' => $data_decode], 401);
        }
        if (! config('app.debug') && time() - $data_decode['time'] > config('login.api.secret_ttl')) {
            return $this->response(['message' => 'Secret error: Secret expire!'], 401);
        }

        $app = App::firstOrCreate(
            ['package_id' => $data_decode['package_id']],
            ['platform' => DeviceSet::platform($data_decode['platform']), 'name' => $data_decode['package_id']]
        );

        try {
            // (string): client_id dang so trong JSON (vd 12345) van bam duoc
            $device = DeviceSet::findOrCreate((string) $data_decode['client_id'], $app->id, $data_decode['platform']);
        } catch (QueryException $e) {
            report($e);
            // Message co binding BINARY(16) (device_id_hash) -> khong phai UTF-8, json_encode se chet theo.
            // Chi lo chi tiet khi debug: message chua SQL + host DB.
            $message = config('app.debug') && mb_check_encoding($e->getMessage(), 'UTF-8') ? $e->getMessage() : 'Register device error!';

            return $this->response(['message' => $message], 500);
        }
        $secret = $device->secret;

        Cache::put("device_secret_{$device->id}", $secret, config('login.api.device_secret_cache_ttl'));

        $jwt = JwtHelper::createAccessToken([
            'device_id' => $device->id,
            'platform' => $device->platform,
            'app_id' => $app->id,
            'data_id' => 0,
        ], $secret, config('login.api.token_ttl_days'));

        return $this->response([
            'access_token' => $jwt,
            'device' => [
                'id' => $device->id,
                'name' => $device->name,
                'client_id' => $device->client_id,
                'app_id' => $app->id,
            ],
        ]);
    }
}
