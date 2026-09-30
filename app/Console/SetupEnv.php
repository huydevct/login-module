<?php

namespace Modules\Login\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Them cac bien env cua module vao .env.example va .env. Chi them bien con thieu,
 * khong ghi de bien da co, nen chay lai bao nhieu lan cung duoc.
 */
class SetupEnv extends Command
{
    protected $signature = 'login:env';

    protected $description = 'Add Login module variables to .env.example and .env (missing keys only)';

    /**
     * Ghi ro gia tri mac dinh thay vi de trong: KEY= rong tra ve '' chu khong lay default
     * trong config (vd REQUIRE_VERIFIED_BOOT= rong se tat kiem tra bootloader).
     */
    private const DEFAULTS = [
        'JWT_OPENSSL_DEVICE_SECRET' => '',
        'AUTH_API_JWT_SECRET' => '',
        'LOGIN_MODULE_HOME' => 'login.admin',
        'LOGIN_MODULE_CMS_TITLE' => '"${APP_NAME} CMS"',
        'LOGIN_MODULE_CMS_FOOTER' => '"Powered by CoreUI"',
        'LOGIN_MODULE_ASSETS_URL' => '/modules/login',
        'LOGIN_MODULE_BLOCKED_DEVICES_KEY' => '',
        'LOGIN_MODULE_ATTESTATION_REQUIRE_VERIFIED_BOOT' => 'true',
        'LOGIN_MODULE_ATTESTATION_ROOTS' => '',
    ];

    public function handle(): int
    {
        $dir = $this->laravel->environmentPath();

        $this->fill("{$dir}/.env.example", self::DEFAULTS);

        $added = $this->fill($this->laravel->environmentFilePath(), array_merge(self::DEFAULTS, [
            'JWT_OPENSSL_DEVICE_SECRET' => Str::random(32),   // khoa AES-256: dung 32 byte
            'AUTH_API_JWT_SECRET' => Str::random(64),
        ]));

        if (in_array('JWT_OPENSSL_DEVICE_SECRET', $added, true)) {
            $this->warn('JWT_OPENSSL_DEVICE_SECRET vừa được sinh mới: app mobile phải dùng đúng khoá này để mã hoá secret gửi lên add-device.');
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<string, string>  $values
     * @return string[] cac key vua them
     */
    private function fill(string $path, array $values): array
    {
        $name = basename($path);
        if (! is_file($path)) {
            $this->warn("{$name} không tồn tại, bỏ qua.");

            return [];
        }

        $content = (string) file_get_contents($path);
        $missing = array_filter(
            $values,
            fn ($key) => ! preg_match('/^\s*(export\s+)?'.preg_quote($key, '/').'\s*=/m', $content),
            ARRAY_FILTER_USE_KEY
        );
        $present = array_diff(array_keys($values), array_keys($missing));

        if ($missing !== []) {
            $lines = array_map(fn ($key, $value) => "{$key}={$value}", array_keys($missing), $missing);
            $prefix = $content === '' ? '' : (str_ends_with($content, "\n") ? "\n" : "\n\n");
            file_put_contents($path, $content.$prefix."# Login module\n".implode("\n", $lines)."\n");
            $this->info("{$name}: đã thêm ".implode(', ', array_keys($missing)));
        }
        if ($present !== []) {
            $this->line("{$name}: đã có sẵn ".implode(', ', $present));
        }

        return array_keys($missing);
    }
}
