<?php

namespace Modules\Login\Helpers;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Support\Facades\Cache;
use Modules\Login\Services\DeviceService\DeviceGet;

/**
 * Thong tin device cua request API hien tai, duoc middleware ApiAuthenticate
 * dien vao sau khi verify JWT. Lay ra bang LoginHelper::AuthApi()->getDeviceId().
 */
class AuthApi
{
    use SingletonTrait;

    private int $device_id = 0;

    private int $platform = 1;

    private bool $verify = false;

    private int $user_id = 0;

    private int $app_id = 0;

    private int $data_id = 0;

    public function verify(string $token): bool
    {
        $info = JwtHelper::getPayload($token);
        if (empty($info['data']['device_id'])) {
            return false;
        }
        $device_id = (int) $info['data']['device_id'];
        $cache_secret = "device_secret_{$device_id}";
        $secret = Cache::get($cache_secret);
        if (empty($secret)) {
            $device = DeviceGet::getById($device_id);
            if (empty($device)) {
                return false;
            }
            $secret = $device->secret;
            Cache::put($cache_secret, $secret, config('login.api.device_secret_cache_ttl'));
        }
        try {
            $token_info = JWT::decode($token, new Key($secret, 'HS256'));
            $this->setData($token_info);

            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    public function setData($data): void
    {
        $this->device_id = (int) $data->data->device_id;
        $this->platform = (int) $data->data->platform;
        $this->data_id = (int) $data->data->data_id;
        $this->app_id = (int) $data->data->app_id;
        $this->verify = true;
    }

    public function isVerified(): bool
    {
        return $this->verify;
    }

    public function getUserId(): int
    {
        return $this->user_id;
    }

    public function getDeviceId(): int
    {
        return $this->device_id;
    }

    public function getPlatform(): int
    {
        return $this->platform;
    }

    public function getDataId(): int
    {
        return $this->data_id;
    }

    public function getAppId(): int
    {
        return $this->app_id;
    }

    public function reset(): void
    {
        $this->device_id = 0;
        $this->platform = 1;
        $this->verify = false;
        $this->user_id = 0;
        $this->app_id = 0;
        $this->data_id = 0;
    }
}
