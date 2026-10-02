<?php

namespace Modules\Login\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Login\Models\App;
use Modules\Login\Models\Device;
use Modules\Login\Services\DeviceService\DeviceIdHasher;
use Tests\TestCase;

class BackfillDeviceHashTest extends TestCase
{
    use RefreshDatabase;

    private int $appId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->appId = App::create(['package_id' => 'com.example.app', 'name' => 'x', 'platform' => 1])->id;
    }

    /**
     * Dong do code cu tao (chua co device_id_hash).
     */
    private function legacy(string $clientId, ?int $appId = null): int
    {
        return DB::table('devices')->insertGetId([
            'name' => 'd', 'client_id' => $clientId, 'app_id' => $appId ?? $this->appId, 'client_id_md5' => md5($clientId),
            'platform' => 1, 'last_login' => 0, 'secret' => str_repeat('s', 32), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function hashOf(int $id): ?string
    {
        return Device::findOrFail($id)->device_id_hash;
    }

    public function test_fills_hash_for_legacy_rows(): void
    {
        $suffixed = $this->legacy("abc_{$this->appId}");
        $raw = $this->legacy('raw-id', $rawApp = App::create(['package_id' => 'com.raw.app', 'name' => 'r', 'platform' => 1])->id);
        config(['login.api.raw_client_id_app_ids' => [$rawApp]]);

        $this->artisan('login:backfill-device-hash')->expectsOutputToContain('2 dòng đã điền hash')->assertSuccessful();

        $this->assertSame(DeviceIdHasher::hashDevice($this->appId, 'abc'), $this->hashOf($suffixed));
        $this->assertSame(DeviceIdHasher::hashDevice($rawApp, 'raw-id'), $this->hashOf($raw));
    }

    public function test_oldest_row_wins_and_duplicates_are_reported(): void
    {
        $oldest = $this->legacy("abc_{$this->appId}");
        $duplicate = $this->legacy("ABC_{$this->appId}");

        $this->artisan('login:backfill-device-hash')
            ->expectsOutputToContain("#{$duplicate} trùng hash với #{$oldest}")
            ->assertSuccessful();

        $this->assertSame(DeviceIdHasher::hashDevice($this->appId, 'abc'), $this->hashOf($oldest));
        $this->assertNull($this->hashOf($duplicate));
    }

    public function test_reports_client_id_without_expected_suffix(): void
    {
        $odd = $this->legacy('no-suffix-here');

        $this->artisan('login:backfill-device-hash')
            ->expectsOutputToContain("#{$odd}")
            ->assertSuccessful();

        $this->assertSame(DeviceIdHasher::hashDevice($this->appId, 'no-suffix-here'), $this->hashOf($odd));
    }

    public function test_second_run_changes_nothing(): void
    {
        $id = $this->legacy("abc_{$this->appId}");
        $this->artisan('login:backfill-device-hash')->assertSuccessful();
        $hash = $this->hashOf($id);

        $this->artisan('login:backfill-device-hash')->expectsOutputToContain('0 dòng đã điền hash')->assertSuccessful();

        $this->assertSame($hash, $this->hashOf($id));
    }

    public function test_all_option_recomputes_existing_hashes(): void
    {
        $id = $this->legacy("abc_{$this->appId}");
        DB::table('devices')->where('id', $id)->update(['device_id_hash' => str_repeat("\0", 16)]);   // hash cong thuc cu

        $this->artisan('login:backfill-device-hash', ['--all' => true])->assertSuccessful();

        $this->assertSame(DeviceIdHasher::hashDevice($this->appId, 'abc'), $this->hashOf($id));
    }

    public function test_processes_in_chunks(): void
    {
        $ids = array_map(fn ($i) => $this->legacy("dev{$i}_{$this->appId}"), range(1, 5));

        $this->artisan('login:backfill-device-hash', ['--chunk' => 2])->expectsOutputToContain('5 dòng đã điền hash')->assertSuccessful();

        foreach ($ids as $i => $id) {
            $this->assertSame(DeviceIdHasher::hashDevice($this->appId, 'dev'.($i + 1)), $this->hashOf($id));
        }
    }
}
