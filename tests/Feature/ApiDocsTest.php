<?php

namespace Modules\Login\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Modules\Login\Providers\RouteServiceProvider;
use Tests\TestCase;

class ApiDocsTest extends TestCase
{
    use RefreshDatabase;

    private function createAdmin(): object
    {
        $model = config('auth.providers.users.model');
        $user = new $model;
        $user->name = 'Admin';
        $user->email = 'admin@example.com';
        $user->login_name = 'admin@example.com';
        $user->password = Hash::make('secret123');
        $user->role = 1;
        $user->save();

        return $user;
    }

    /**
     * Dang ky lai route cua module sau khi doi config (route doc config luc boot).
     */
    private function reloadModuleRoutes(array $config): void
    {
        config($config);
        Route::setRoutes(new RouteCollection);
        (new RouteServiceProvider($this->app))->map();
        Route::getRoutes()->refreshNameLookups();
    }

    public function test_guest_cannot_see_docs_or_spec(): void
    {
        $this->get('/admin/api-docs')->assertRedirect('/login');
        $this->get('/admin/api-docs/openapi.json')->assertRedirect('/login');
    }

    public function test_logged_in_admin_sees_swagger_ui(): void
    {
        $this->actingAs($this->createAdmin())->get('/admin/api-docs')
            ->assertOk()
            ->assertSee('swagger-ui-dist@5.33.1/swagger-ui-bundle.js', false)
            ->assertSee(route('login.api-docs.spec', [], false), false)
            ->assertSee('Đăng xuất');
    }

    public function test_spec_documents_module_api(): void
    {
        $spec = $this->actingAs($this->createAdmin())->getJson('/admin/api-docs/openapi.json')->assertOk()->json();

        $this->assertStringStartsWith('3.', $spec['openapi']);
        $this->assertArrayHasKey('/api/v1/auth/add-device', $spec['paths']);
        $this->assertArrayHasKey('/api/v1/auth/attest/challenge', $spec['paths']);
        $this->assertArrayHasKey('/api/v1/auth/attest/register', $spec['paths']);
        $this->assertSame('bearer', $spec['components']['securitySchemes']['deviceJwt']['scheme']);
        $this->assertSame([['deviceJwt' => []]], $spec['paths']['/api/v1/auth/attest/register']['post']['security']);
        $this->assertArrayNotHasKey('security', $spec['paths']['/api/v1/auth/add-device']['post']);
        $this->assertStringContainsString('X-Signature', $spec['info']['description']);
    }

    public function test_every_documented_path_is_a_real_route(): void
    {
        $spec = $this->actingAs($this->createAdmin())->getJson('/admin/api-docs/openapi.json')->json();

        foreach ($spec['paths'] as $path => $operations) {
            foreach (array_keys($operations) as $method) {
                $route = Route::getRoutes()->match(\Illuminate\Http\Request::create($path, strtoupper($method)));
                $this->assertSame(ltrim($path, '/'), $route->uri(), "$method $path");
            }
        }
    }

    public function test_spec_follows_configured_api_prefix(): void
    {
        $this->reloadModuleRoutes(['login.api.prefix' => 'mobile/v2']);

        $spec = $this->actingAs($this->createAdmin())->getJson('/admin/api-docs/openapi.json')->json();

        $this->assertArrayHasKey('/mobile/v2/add-device', $spec['paths']);
        $this->assertArrayNotHasKey('/api/v1/auth/add-device', $spec['paths']);
    }

    public function test_spec_omits_attestation_when_disabled(): void
    {
        $this->reloadModuleRoutes(['login.attestation.enabled' => false]);

        $spec = $this->actingAs($this->createAdmin())->getJson('/admin/api-docs/openapi.json')->json();

        $this->assertArrayHasKey('/api/v1/auth/add-device', $spec['paths']);
        $this->assertArrayNotHasKey('/api/v1/auth/attest/register', $spec['paths']);
        $this->assertStringNotContainsString('X-Signature', $spec['info']['description']);
    }

    public function test_page_has_integration_guide_tab(): void
    {
        $this->actingAs($this->createAdmin())->get('/admin/api-docs')
            ->assertOk()
            ->assertSeeInOrder(['Hướng dẫn tích hợp app', 'API (Swagger)'])
            ->assertSee('/api/v1/auth/add-device')
            ->assertSee('AES/CBC/PKCS5Padding')
            ->assertSee('copyOf(32)')
            ->assertSee('setAttestationChallenge')
            ->assertSee('SHA256withECDSA')
            ->assertSee('encodedPath')
            ->assertSee('device_not_attested')
            ->assertSee('300 giây');
    }

    public function test_guide_follows_config(): void
    {
        $this->reloadModuleRoutes([
            'login.api.prefix' => 'mobile/v2',
            'login.api.token_ttl_days' => 3,
            'login.attestation.timestamp_window' => 120,
        ]);

        $this->actingAs($this->createAdmin())->get('/admin/api-docs')
            ->assertOk()
            ->assertSee('/mobile/v2/add-device')
            ->assertDontSee('/api/v1/auth/add-device')
            ->assertSee('3 ngày')
            ->assertSee('120 giây');
    }

    public function test_guide_omits_attestation_when_disabled(): void
    {
        $this->reloadModuleRoutes(['login.attestation.enabled' => false]);

        $this->actingAs($this->createAdmin())->get('/admin/api-docs')
            ->assertOk()
            ->assertSee('AES/CBC/PKCS5Padding')
            ->assertDontSee('setAttestationChallenge')
            ->assertDontSee('SHA256withECDSA');
    }

    public function test_sidebar_links_api_docs(): void
    {
        $this->actingAs($this->createAdmin())->get('/admin')
            ->assertOk()
            ->assertSee('API Docs')
            ->assertSee(route('login.api-docs'), false);
    }

    public function test_docs_can_be_disabled(): void
    {
        $this->reloadModuleRoutes(['login.web.api_docs' => false]);

        $this->actingAs($this->createAdmin())->get('/admin/api-docs')->assertNotFound();
        $this->get('/admin')->assertOk()->assertDontSee('API Docs');
    }

    public function test_docs_path_is_configurable(): void
    {
        $this->reloadModuleRoutes(['login.web.api_docs_path' => 'cms/docs']);

        $this->actingAs($this->createAdmin())->get('/cms/docs')->assertOk();
        $this->get('/cms/docs/openapi.json')->assertOk()->assertJsonPath('openapi', '3.0.3');
    }
}
