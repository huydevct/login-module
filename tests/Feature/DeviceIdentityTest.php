<?php

namespace Modules\Login\Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Login\Helpers\LoginHelper;
use Modules\Login\Models\App;
use Modules\Login\Models\Device;
use Modules\Login\Services\DeviceService\DeviceIdHasher;
use Tests\TestCase;

class DeviceIdentityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['login.openssl.device_secret' => 'test-device-secret']);
    }

    private function addDevice(string $clientId, string $package = 'com.example.app')
    {
        return $this->postJson('/api/v1/auth/add-device', ['secret' => LoginHelper::encodeOpenSsl(json_encode([
            'client_id' => $clientId, 'platform' => 'android', 'package_id' => $package, 'time' => time(),
        ]))]);
    }

    /**
     * Dong do code cu tao (chua co device_id_hash).
     */
    private function legacyDevice(string $clientId, int $appId): int
    {
        return DB::table('devices')->insertGetId([
            'name' => 'Device_old', 'client_id' => $clientId, 'app_id' => $appId, 'client_id_md5' => md5($clientId),
            'platform' => 1, 'last_login' => 0, 'secret' => str_repeat('s', 32), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_devices_table_has_device_id_hash_column(): void
    {
        $this->assertTrue(Schema::hasColumn('devices', 'device_id_hash'));
    }

    public function test_add_device_stores_hash_and_keeps_legacy_columns(): void
    {
        $data = $this->addDevice('Client-ABC')->assertOk()->json('data');

        $appId = $data['device']['app_id'];
        $device = Device::findOrFail($data['device']['id']);
        $this->assertSame(DeviceIdHasher::hashDevice($appId, 'Client-ABC'), $device->device_id_hash);
        $this->assertSame("Client-ABC_{$appId}", $device->client_id);
        $this->assertSame(md5("Client-ABC_{$appId}"), $device->client_id_md5);
    }

    public function test_client_id_differing_only_by_case_or_spaces_is_same_device(): void
    {
        $first = $this->addDevice('E621E1F8-C36C-495A-93FC-0C247A3E6E5F')->assertOk()->json('data.device.id');
        $second = $this->addDevice('  e621e1f8-c36c-495a-93fc-0c247a3e6e5f ')->assertOk()->json('data.device.id');

        $this->assertSame($first, $second);
        $this->assertSame(1, Device::count());
    }

    public function test_same_client_id_in_two_apps_is_two_devices(): void
    {
        $a = $this->addDevice('9774d56d682e549c', 'com.example.app')->assertOk()->json('data.device.id');
        $b = $this->addDevice('9774d56d682e549c', 'com.other.app')->assertOk()->json('data.device.id');

        $this->assertNotSame($a, $b);
        $this->assertSame(2, Device::count());
    }

    public function test_raw_client_id_app_hashes_trimmed_client_id(): void
    {
        $app = App::create(['package_id' => 'com.example.app', 'name' => 'x', 'platform' => 1]);
        config(['login.api.raw_client_id_app_ids' => [$app->id]]);

        $id = $this->addDevice(' raw-id ')->assertOk()->json('data.device.id');

        $device = Device::findOrFail($id);
        $this->assertSame('raw-id', $device->client_id);
        $this->assertSame(DeviceIdHasher::hashDevice($app->id, 'raw-id'), $device->device_id_hash);
    }

    public function test_legacy_device_without_hash_is_reused_and_backfilled(): void
    {
        $app = App::create(['package_id' => 'com.example.app', 'name' => 'x', 'platform' => 1]);
        $legacyId = $this->legacyDevice("client-abc_{$app->id}", $app->id);

        $id = $this->addDevice('client-abc')->assertOk()->json('data.device.id');

        $this->assertSame($legacyId, $id);
        $this->assertSame(1, Device::count());
        $this->assertSame(DeviceIdHasher::hashDevice($app->id, 'client-abc'), Device::findOrFail($legacyId)->device_id_hash);
    }

    public function test_legacy_duplicate_resolves_to_device_holding_the_hash(): void
    {
        $app = App::create(['package_id' => 'com.example.app', 'name' => 'x', 'platform' => 1]);
        $oldest = $this->legacyDevice("abc_{$app->id}", $app->id);
        DB::table('devices')->where('id', $oldest)->update(['device_id_hash' => DeviceIdHasher::hashDevice($app->id, 'abc')]);
        $duplicate = $this->legacyDevice("ABC_{$app->id}", $app->id);   // khac hoa/thuong, chua co hash

        $id = $this->addDevice('ABC')->assertOk()->json('data.device.id');

        $this->assertSame($oldest, $id);
        $this->assertNull(Device::findOrFail($duplicate)->device_id_hash);
    }

    public function test_device_json_hides_binary_hash(): void
    {
        $this->addDevice('client-abc')->assertOk();

        $json = Device::firstOrFail()->toJson();

        $this->assertStringNotContainsString('device_id_hash', $json);
    }

    public function test_database_error_returns_valid_utf8_message(): void
    {
        Device::creating(function (Device $device) {
            throw new QueryException('sqlite', 'insert into devices (device_id_hash) values (?)', [$device->device_id_hash], new \PDOException('boom'));
        });

        $this->addDevice('client-abc')
            ->assertStatus(500)
            ->assertJsonPath('data.message', 'Register device error!');
    }

    private function adminSearch(array $query): array
    {
        $this->app->instance('request', \Illuminate\Http\Request::create('/admin/devices', 'GET', $query));
        \Illuminate\Support\Facades\Request::clearResolvedInstance('request');

        return \Modules\Login\Services\DeviceService\DeviceGet::getDeviceByAdmin()->pluck('id')->all();
    }

    public function test_admin_search_by_client_id_and_app_id_uses_hash(): void
    {
        $data = $this->addDevice('Client-ABC')->assertOk()->json('data');
        $this->addDevice('other-device')->assertOk();
        $appId = $data['device']['app_id'];

        $this->assertSame([$data['device']['id']], $this->adminSearch(['client_id' => "Client-ABC_{$appId}", 'app_id' => $appId]));
        $this->assertSame([$data['device']['id']], $this->adminSearch(['client_id' => 'client-abc', 'app_id' => $appId]));
    }

    public function test_admin_search_by_client_id_only_matches_stored_client_id(): void
    {
        $data = $this->addDevice('Client-ABC')->assertOk()->json('data');
        $appId = $data['device']['app_id'];

        $this->assertSame([$data['device']['id']], $this->adminSearch(['client_id' => "Client-ABC_{$appId}"]));
        $this->assertSame([], $this->adminSearch(['client_id' => 'nope']));
    }
}
