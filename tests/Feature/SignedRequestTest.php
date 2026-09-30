<?php

namespace Modules\Login\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Modules\Login\Helpers\LoginHelper;
use Modules\Login\Models\Device;
use OpenSSLAsymmetricKey;
use Tests\TestCase;

class SignedRequestTest extends TestCase
{
    use RefreshDatabase;

    private OpenSSLAsymmetricKey $key;

    private string $token;

    private int $deviceId;

    private int $appId;

    protected function setUp(): void
    {
        parent::setUp();

        config(['login.openssl.device_secret' => 'test-device-secret']);
        Route::middleware(['auth.api', 'signed.device'])->match(['GET', 'POST'], '/_login/signed', fn (Request $request) => [
            'device_id' => $request->attributes->get('login_device')->id,
        ]);
        Route::middleware('signed.device')->post('/_login/signed-only', fn () => ['ok' => true]);

        $data = $this->postJson('/api/v1/auth/add-device', ['secret' => LoginHelper::encodeOpenSsl(json_encode([
            'client_id' => 'client-abc', 'platform' => 'android', 'package_id' => 'com.example.app', 'time' => time(),
        ]))])->json('data');
        $this->token = $data['access_token'];
        $this->deviceId = $data['device']['id'];
        $this->appId = $data['device']['app_id'];

        $this->key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        Device::findOrFail($this->deviceId)->update([
            'public_key_pem' => openssl_pkey_get_details($this->key)['key'],
            'security_level' => 'TEE',
        ]);
    }

    /**
     * Dung chuoi ky giong app: METHOD, /PATH, QUERY, TS, NONCE, DEVICE_ID, APP_ID, SHA256(body).
     */
    private function sign(string $method, string $path, string $query, string $body, array $override = []): array
    {
        $fields = array_merge([
            'ts' => (string) time(),
            'nonce' => bin2hex(random_bytes(16)),
            'device_id' => $this->deviceId,
            'app_id' => $this->appId,
            'key' => $this->key,
        ], $override);
        $payload = implode("\n", [$method, $path, $query, $fields['ts'], $fields['nonce'], $fields['device_id'], $fields['app_id'], hash('sha256', $body)]);
        openssl_sign($payload, $signature, $fields['key'], OPENSSL_ALGO_SHA256);

        return [
            'Authorization' => 'Bearer '.$this->token,
            'X-Timestamp' => $fields['ts'],
            'X-Nonce' => $fields['nonce'],
            'X-Signature' => base64_encode($signature),
        ];
    }

    private function send(string $method, string $uri, string $body, array $headers): TestResponse
    {
        // call() khong dung header cua withHeaders() -> truyen thang qua $server
        $server = $this->transformHeadersToServerVars($headers + ['Content-Type' => 'application/json', 'Accept' => 'application/json']);

        return $this->call($method, $uri, [], [], [], $server, $body);
    }

    public function test_accepts_valid_signed_post(): void
    {
        $body = '{"reason":"watch_ad"}';

        $this->send('POST', '/_login/signed', $body, $this->sign('POST', '/_login/signed', '', $body))
            ->assertOk()
            ->assertJson(['device_id' => $this->deviceId]);
    }

    public function test_accepts_valid_signed_get_with_query(): void
    {
        $this->send('GET', '/_login/signed?amount=10', '', $this->sign('GET', '/_login/signed', 'amount=10', ''))
            ->assertOk();
    }

    public function test_rejects_multipart_body(): void
    {
        // PHP-FPM khong cho doc php://input voi multipart -> body khong duoc ky -> tu choi
        $headers = $this->sign('POST', '/_login/signed', '', '') + ['Content-Type' => 'multipart/form-data; boundary=x'];

        $this->send('POST', '/_login/signed', '', $headers)->assertStatus(400)->assertJsonPath('status', 'unsupported_content_type');
    }

    public function test_rejects_missing_headers(): void
    {
        $headers = $this->sign('POST', '/_login/signed', '', '{}');
        unset($headers['X-Nonce']);

        $this->send('POST', '/_login/signed', '{}', $headers)->assertStatus(400)->assertJsonPath('status', 'missing_signature_header');
    }

    public function test_rejects_too_long_nonce(): void
    {
        $headers = $this->sign('POST', '/_login/signed', '', '{}', ['nonce' => str_repeat('a', 65)]);

        $this->send('POST', '/_login/signed', '{}', $headers)->assertStatus(400)->assertJsonPath('status', 'missing_signature_header');
    }

    public function test_rejects_old_or_millisecond_timestamp(): void
    {
        $old = $this->sign('POST', '/_login/signed', '', '{}', ['ts' => (string) (time() - 301)]);
        $this->send('POST', '/_login/signed', '{}', $old)->assertStatus(401)->assertJsonPath('status', 'request_expired');

        $millis = $this->sign('POST', '/_login/signed', '', '{}', ['ts' => (string) (time() * 1000)]);
        $this->send('POST', '/_login/signed', '{}', $millis)->assertStatus(401)->assertJsonPath('status', 'request_expired');
    }

    public function test_rejects_device_not_attested(): void
    {
        Device::findOrFail($this->deviceId)->update(['public_key_pem' => null]);

        $this->send('POST', '/_login/signed', '{}', $this->sign('POST', '/_login/signed', '', '{}'))
            ->assertStatus(403)->assertJsonPath('status', 'device_not_attested');
    }

    public function test_rejects_modified_body(): void
    {
        $headers = $this->sign('POST', '/_login/signed', '', '{"amount":1}');

        $this->send('POST', '/_login/signed', '{"amount":1000}', $headers)->assertStatus(401)->assertJsonPath('status', 'invalid_signature');
    }

    public function test_rejects_other_path(): void
    {
        $headers = $this->sign('POST', '/_login/other', '', '{}');

        $this->send('POST', '/_login/signed', '{}', $headers)->assertStatus(401)->assertJsonPath('status', 'invalid_signature');
    }

    public function test_rejects_modified_query(): void
    {
        $headers = $this->sign('GET', '/_login/signed', 'amount=10', '');

        $this->send('GET', '/_login/signed?amount=1000', '', $headers)->assertStatus(401)->assertJsonPath('status', 'invalid_signature');
    }

    public function test_rejects_other_method(): void
    {
        $headers = $this->sign('GET', '/_login/signed', '', '');

        $this->send('POST', '/_login/signed', '', $headers)->assertStatus(401)->assertJsonPath('status', 'invalid_signature');
    }

    public function test_rejects_signature_from_other_key(): void
    {
        $other = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        $headers = $this->sign('POST', '/_login/signed', '', '{}', ['key' => $other]);

        $this->send('POST', '/_login/signed', '{}', $headers)->assertStatus(401)->assertJsonPath('status', 'invalid_signature');
    }

    public function test_rejects_signature_for_other_device_or_app(): void
    {
        $otherDevice = $this->sign('POST', '/_login/signed', '', '{}', ['device_id' => $this->deviceId + 1]);
        $this->send('POST', '/_login/signed', '{}', $otherDevice)->assertStatus(401)->assertJsonPath('status', 'invalid_signature');

        $otherApp = $this->sign('POST', '/_login/signed', '', '{}', ['app_id' => $this->appId + 1]);
        $this->send('POST', '/_login/signed', '{}', $otherApp)->assertStatus(401)->assertJsonPath('status', 'invalid_signature');
    }

    public function test_rejects_non_base64_signature(): void
    {
        $headers = $this->sign('POST', '/_login/signed', '', '{}');
        $headers['X-Signature'] = '%%%';

        $this->send('POST', '/_login/signed', '{}', $headers)->assertStatus(401)->assertJsonPath('status', 'invalid_signature');
    }

    public function test_rejects_replayed_nonce(): void
    {
        $headers = $this->sign('POST', '/_login/signed', '', '{}');

        $this->send('POST', '/_login/signed', '{}', $headers)->assertOk();
        $this->send('POST', '/_login/signed', '{}', $headers)->assertStatus(401)->assertJsonPath('status', 'replayed_request');
    }

    public function test_invalid_signature_does_not_burn_nonce(): void
    {
        $nonce = bin2hex(random_bytes(16));
        $bad = $this->sign('POST', '/_login/signed', '', '{"a":1}', ['nonce' => $nonce]);
        $this->send('POST', '/_login/signed', '{"a":2}', $bad)->assertStatus(401);

        $good = $this->sign('POST', '/_login/signed', '', '{}', ['nonce' => $nonce]);
        $this->send('POST', '/_login/signed', '{}', $good)->assertOk();
    }

    public function test_requires_auth_api_on_same_request(): void
    {
        // Lam "ban" singleton AuthApi nhu request truoc trong worker chay lau
        $this->send('POST', '/_login/signed', '{}', $this->sign('POST', '/_login/signed', '', '{}'))->assertOk();

        $this->send('POST', '/_login/signed-only', '{}', $this->sign('POST', '/_login/signed-only', '', '{}'))
            ->assertStatus(401)->assertJsonPath('status', 'not_authenticated');
    }
}
