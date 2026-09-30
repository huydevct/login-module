<?php

namespace Modules\Login\Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

class SetupEnvCommandTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir().'/login-env-'.bin2hex(random_bytes(4));
        File::makeDirectory($this->dir);
        $this->app->useEnvironmentPath($this->dir);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);

        parent::tearDown();
    }

    private function write(string $file, string $content): void
    {
        file_put_contents("{$this->dir}/{$file}", $content);
    }

    private function read(string $file): string
    {
        return file_get_contents("{$this->dir}/{$file}");
    }

    /**
     * Gia tri cua KEY trong noi dung file .env (null neu khong co).
     */
    private function value(string $content, string $key): ?string
    {
        return preg_match('/^'.$key.'=(.*)$/m', $content, $m) ? $m[1] : null;
    }

    public function test_adds_missing_keys_to_env_and_example(): void
    {
        $this->write('.env', "APP_NAME=Demo\n");
        $this->write('.env.example', "APP_NAME=Laravel\n");

        $this->artisan('login:env')->assertSuccessful();

        $env = $this->read('.env');
        $example = $this->read('.env.example');
        foreach ([$env, $example] as $content) {
            $this->assertStringContainsString("# Login module\n", $content);
            $this->assertSame('login.admin', $this->value($content, 'LOGIN_MODULE_HOME'));
            $this->assertSame('"${APP_NAME} CMS"', $this->value($content, 'LOGIN_MODULE_CMS_TITLE'));
            $this->assertSame('"Powered by CoreUI"', $this->value($content, 'LOGIN_MODULE_CMS_FOOTER'));
            $this->assertSame('/modules/login', $this->value($content, 'LOGIN_MODULE_ASSETS_URL'));
            $this->assertSame('', $this->value($content, 'LOGIN_MODULE_BLOCKED_DEVICES_KEY'));
            $this->assertSame('true', $this->value($content, 'LOGIN_MODULE_ATTESTATION_REQUIRE_VERIFIED_BOOT'));
            $this->assertSame('', $this->value($content, 'LOGIN_MODULE_ATTESTATION_ROOTS'));
        }
        $this->assertStringStartsWith("APP_NAME=Demo\n", $env);
    }

    public function test_generates_secrets_only_in_env(): void
    {
        $this->write('.env', '');
        $this->write('.env.example', '');

        $this->artisan('login:env')
            ->expectsOutputToContain('JWT_OPENSSL_DEVICE_SECRET')
            ->assertSuccessful();

        $env = $this->read('.env');
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{32}$/', $this->value($env, 'JWT_OPENSSL_DEVICE_SECRET'));
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{64}$/', $this->value($env, 'AUTH_API_JWT_SECRET'));

        $example = $this->read('.env.example');
        $this->assertSame('', $this->value($example, 'JWT_OPENSSL_DEVICE_SECRET'));
        $this->assertSame('', $this->value($example, 'AUTH_API_JWT_SECRET'));
    }

    public function test_keeps_existing_values(): void
    {
        $this->write('.env', "JWT_OPENSSL_DEVICE_SECRET=shared-with-app\nLOGIN_MODULE_HOME=admin.dashboard\n");
        $this->write('.env.example', "LOGIN_MODULE_HOME=admin.dashboard\n");

        $this->artisan('login:env')->assertSuccessful();

        $env = $this->read('.env');
        $this->assertSame('shared-with-app', $this->value($env, 'JWT_OPENSSL_DEVICE_SECRET'));
        $this->assertSame('admin.dashboard', $this->value($env, 'LOGIN_MODULE_HOME'));
        $this->assertSame(1, substr_count($env, 'LOGIN_MODULE_HOME='));
        $this->assertSame(1, substr_count($this->read('.env.example'), 'LOGIN_MODULE_HOME='));
    }

    public function test_commented_key_counts_as_missing(): void
    {
        $this->write('.env', "# LOGIN_MODULE_HOME=/old\n");
        $this->write('.env.example', '');

        $this->artisan('login:env')->assertSuccessful();

        $this->assertSame('login.admin', $this->value($this->read('.env'), 'LOGIN_MODULE_HOME'));
    }

    public function test_second_run_changes_nothing(): void
    {
        $this->write('.env', "APP_NAME=Demo");   // khong co xuong dong cuoi
        $this->write('.env.example', "APP_NAME=Laravel\n");

        $this->artisan('login:env')->assertSuccessful();
        $env = $this->read('.env');
        $example = $this->read('.env.example');
        $this->assertStringStartsWith("APP_NAME=Demo\n", $env);

        $this->artisan('login:env')->assertSuccessful();

        $this->assertSame($env, $this->read('.env'));
        $this->assertSame($example, $this->read('.env.example'));
    }

    public function test_skips_missing_files(): void
    {
        $this->write('.env.example', '');

        $this->artisan('login:env')
            ->expectsOutputToContain('.env không tồn tại')
            ->assertSuccessful();

        $this->assertFileDoesNotExist("{$this->dir}/.env");
        $this->assertNotNull($this->value($this->read('.env.example'), 'LOGIN_MODULE_HOME'));
    }
}
