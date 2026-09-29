<?php

namespace Modules\Login\Helpers;

use Firebase\JWT\JWT;

class JwtHelper
{
    public static function createAccessToken(array $data, $secret, float $expire = 1): string
    {
        $time_now = time();
        $payload = [
            'iat' => $time_now,
            'data' => $data,
        ];
        if ($expire != -1) {
            $expire = 86400 * $expire; // Tính theo ngày
            $payload['exp'] = $time_now + $expire;
        }
        $jwt = JWT::encode($payload, $secret, 'HS256');

        return $jwt;
    }

    public static function getPayload($jwt)
    {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            return null; // Token không hợp lệ
        }

        $payload = $parts[1];
        $payload = str_replace(['-', '_'], ['+', '/'], $payload);
        $payload .= str_repeat('=', 3 - (strlen($payload) + 3) % 4);

        return json_decode(base64_decode($payload), true);
    }
}
