<?php

namespace Modules\Login\Console;

use Illuminate\Console\Command;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Modules\Login\Services\DeviceService\DeviceIdHasher;
use Modules\Login\Services\DeviceService\DeviceSet;

/**
 * Dien devices.device_id_hash cho dong cu (hoac tinh lai toan bo khi tang DeviceIdHasher::VERSION).
 * Duyet id tang dan: dong cu nhat nhan hash; dong trung hash sau chuan hoa giu NULL va duoc liet ke.
 * Chay lai bao nhieu lan cung duoc.
 */
class BackfillDeviceHash extends Command
{
    protected $signature = 'login:backfill-device-hash
        {--chunk=1000 : So dong moi lo}
        {--all : Tinh lai ca dong da co hash (khi doi cong thuc bam)}';

    protected $description = 'Fill devices.device_id_hash for existing devices (Login)';

    private const MAX_LISTED = 100;

    public function handle(): int
    {
        $all = (bool) $this->option('all');
        $filled = 0;
        $duplicates = [];
        $unexpected = [];

        $query = DB::table('devices')->select(['id', 'client_id', 'app_id'])
            ->when(! $all, fn ($q) => $q->whereNull('device_id_hash'));

        $query->chunkById(max(1, (int) $this->option('chunk')), function ($rows) use ($all, &$filled, &$duplicates, &$unexpected) {
            foreach ($rows as $row) {
                ['device_id' => $deviceId, 'expected_format' => $expected] = DeviceSet::storedDeviceId((string) $row->client_id, (int) $row->app_id);
                if (! $expected) {
                    $unexpected[] = "#{$row->id} client_id \"{$row->client_id}\" không có hậu tố _{$row->app_id} (băm nguyên giá trị)";
                }
                $hash = DeviceIdHasher::hashDevice((int) $row->app_id, $deviceId);

                try {
                    DB::table('devices')->where('id', $row->id)->update(['device_id_hash' => $hash]);
                    $filled++;
                } catch (UniqueConstraintViolationException) {
                    $holder = DB::table('devices')->where('device_id_hash', $hash)->value('id');
                    $duplicates[] = "#{$row->id} trùng hash với #{$holder} (giữ NULL, thiết bị sẽ dùng #{$holder})";
                    if ($all) {
                        // dang giu hash cong thuc cu: bo di de khong khop nham
                        DB::table('devices')->where('id', $row->id)->update(['device_id_hash' => null]);
                    }
                }
            }
        });

        $this->info("{$filled} dòng đã điền hash (công thức v".DeviceIdHasher::VERSION.').');
        $this->report('dòng trùng hash sau chuẩn hoá', $duplicates);
        $this->report('dòng client_id không đúng định dạng', $unexpected);

        return self::SUCCESS;
    }

    /**
     * @param  string[]  $lines
     */
    private function report(string $title, array $lines): void
    {
        if ($lines === []) {
            return;
        }
        $this->warn(count($lines)." {$title}:");
        foreach (array_slice($lines, 0, self::MAX_LISTED) as $line) {
            $this->line("  {$line}");
        }
        if (count($lines) > self::MAX_LISTED) {
            $this->line('  … và '.(count($lines) - self::MAX_LISTED).' dòng khác');
        }
    }
}
