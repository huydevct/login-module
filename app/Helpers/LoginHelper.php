<?php

namespace Modules\Login\Helpers;

use Firebase\JWT\JWT;

class LoginHelper
{
    public static function encodeOpenSsl(string $string, $key = null): string
    {
        $ivSize = openssl_cipher_iv_length('AES-256-CBC');
        $iv = openssl_random_pseudo_bytes($ivSize);
        $key_verify = ! empty($key) ? $key : config('login.openssl.device_secret');
        $encrypted = openssl_encrypt($string, 'AES-256-CBC', (string) $key_verify, OPENSSL_RAW_DATA, $iv);

        return base64_encode($iv.$encrypted);
    }

    public static function decodeOpenSsl(string $string, $key = null): string
    {
        $decoded = base64_decode($string);
        $ivSize = openssl_cipher_iv_length('AES-256-CBC');
        $iv = substr($decoded, 0, $ivSize);
        $encrypted = substr($decoded, $ivSize);
        $key_verify = ! empty($key) ? $key : config('login.openssl.device_secret');
        if (strlen($iv) !== $ivSize) {
            return '';
        }

        return (string) openssl_decrypt($encrypted, 'AES-256-CBC', (string) $key_verify, OPENSSL_RAW_DATA, $iv);
    }

    public static function AuthApi(): AuthApi
    {
        return AuthApi::getInstance();
    }

    public static function createJwtAuthUser($user_id, int $exp = 86400): string
    {
        $payload = [
            'data' => [
                'user_id' => $user_id,
            ],
            'exp' => time() + $exp,
        ];

        return JWT::encode($payload, config('login.api.secret'), 'HS256');
    }
}
