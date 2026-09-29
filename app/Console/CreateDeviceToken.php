<?php

namespace Modules\Login\Console;

use Illuminate\Console\Command;
use Modules\Login\Helpers\LoginHelper;

/**
 * Tao/giai ma secret gui len POST add-device, dung khi test API.
 */
class CreateDeviceToken extends Command
{
    protected $signature = 'login:create-device-token';

    protected $description = 'Encode or decode the add-device secret (Login)';

    public function handle(): int
    {
        $action = $this->choice('Choose action: ', ['encode', 'decode'], 0);
        $this->{$action}();

        return self::SUCCESS;
    }

    public function encode(): void
    {
        $data = json_encode([
            'client_id' => $this->ask('Client ID '),
            'platform' => $this->ask('Platform (android, ios)', 'android'),
            'package_id' => $this->ask('Package ID ', 'app_id_1'),
            'time' => time(),
        ]);
        $this->info("Json encode: $data");
        $this->info('Token: '.LoginHelper::encodeOpenSsl($data));
    }

    public function decode(): void
    {
        $this->line(LoginHelper::decodeOpenSsl((string) $this->ask('Token ')));
    }
}
