<?php

namespace Modules\Login\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Modules\Login\Helpers\LoginHelper;
use Modules\Login\Models\App;
use Modules\Login\Models\Device;
use Tests\TestCase;

class ApiAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['login.openssl.device_secret' => 'test-device-secret']);

        Route::middleware('auth.api')->get('/_login/me', fn () => [
            'device_id' => LoginHelper::AuthApi()->getDeviceId(),
            'app_id' => LoginHelper::AuthApi()->getAppId(),
        ]);
    }

    private function secret(array $overrides = []): string
    {
        return LoginHelper::encodeOpenSsl(json_encode(array_merge([
            'client_id' => 'client-abc',
            'platform' => 'android',
            'package_id' => 'com.example.app',
            'time' => time(),
        ], $overrides)));
    }

    public function test_add_device_creates_app_device_and_returns_token(): void
    {
        $response = $this->postJson('/api/v1/auth/add-device', ['secret' => $this->secret()]);

        $response->assertOk()->assertJsonStructure(['data' => ['access_token', 'device' => ['id', 'name', 'client_id', 'app_id']]]);

        $app = App::where('package_id', 'com.example.app')->firstOrFail();
        $device = Device::firstOrFail();
        $this->assertSame("client-abc_{$app->id}", $device->client_id);
        $this->assertSame(1, (int) $device->platform);
        $this->assertSame($app->id, (int) $device->app_id);
    }

    public function test_add_device_is_idempotent_for_same_client(): void
    {
        $first = $this->postJson('/api/v1/auth/add-device', ['secret' => $this->secret()])->json('data.device.id');
        $second = $this->postJson('/api/v1/auth/add-device', ['secret' => $this->secret()])->json('data.device.id');

        $this->assertSame($first, $second);
        $this->assertSame(1, Device::count());
    }

    public function test_raw_client_id_app_ids_keep_client_id(): void
    {
        $app = App::create(['package_id' => 'com.example.app', 'name' => 'x', 'platform' => 1]);
        config(['login.api.raw_client_id_app_ids' => [$app->id]]);

        $this->postJson('/api/v1/auth/add-device', ['secret' => $this->secret()])->assertOk();

        $this->assertSame('client-abc', Device::firstOrFail()->client_id);
    }

    public function test_add_device_rejects_bad_secret(): void
    {
        $this->postJson('/api/v1/auth/add-device', ['secret' => 'not-a-secret'])->assertStatus(401);
        $this->postJson('/api/v1/auth/add-device', ['secret' => LoginHelper::encodeOpenSsl('{"client_id":"x"}')])->assertStatus(401);
    }

    public function test_add_device_rejects_expired_secret_when_not_debug(): void
    {
        config(['app.debug' => false]);

        $this->postJson('/api/v1/auth/add-device', ['secret' => $this->secret(['time' => time() - 3600])])
            ->assertStatus(401)
            ->assertJsonPath('data.message', 'Secret error: Secret expire!');
    }

    public function test_middleware_accepts_issued_token(): void
    {
        $data = $this->postJson('/api/v1/auth/add-device', ['secret' => $this->secret()])->json('data');

        $this->getJson('/_login/me', ['Authorization' => 'Bearer '.$data['access_token']])
            ->assertOk()
            ->assertJson(['device_id' => $data['device']['id'], 'app_id' => $data['device']['app_id']]);
    }

    public function test_middleware_rejects_missing_or_invalid_token(): void
    {
        $this->getJson('/_login/me')->assertStatus(401)->assertJsonPath('status', 'not_found_token');
        $this->getJson('/_login/me', ['Authorization' => 'Bearer a.b.c'])->assertStatus(401)->assertJsonPath('status', 'expire');
    }
}
