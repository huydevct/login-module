<?php

namespace Modules\Login\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Modules\Login\Providers\RouteServiceProvider;
use Tests\TestCase;

class AdminPageTest extends TestCase
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

    public function test_login_redirects_to_admin_page_by_default(): void
    {
        $this->createAdmin();

        $this->post('/login', ['email' => 'admin@example.com', 'password' => 'secret123'])
            ->assertRedirect('/admin');
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/admin')->assertRedirect('/login');
    }

    public function test_admin_page_with_empty_menu_shows_only_logout(): void
    {
        config(['login.cms.menu' => []]);

        $this->actingAs($this->createAdmin())->get('/admin')
            ->assertOk()
            ->assertSee('Dashboard')
            ->assertSee('admin@example.com')
            ->assertSee('Đăng xuất')
            ->assertSee("config/login.php")
            ->assertSee(route('logout'), false);
    }

    public function test_admin_page_shows_configured_menu(): void
    {
        config(['login.cms.menu' => [['label' => 'Reports', 'route' => 'login.admin', 'icon' => 'cil-chart']]]);

        $this->actingAs($this->createAdmin())->get('/admin')
            ->assertOk()
            ->assertSee('Reports')
            ->assertSee('Đăng xuất')
            ->assertDontSee('config/login.php');
    }

    public function test_menu_item_with_unregistered_route_fails_loudly(): void
    {
        config(['login.cms.menu' => [['label' => 'Analytic', 'route' => 'admin.analytic.index']]]);
        $this->withoutExceptionHandling();

        $this->expectException(\Illuminate\View\ViewException::class);
        $this->expectExceptionMessage("Menu 'Analytic': route [admin.analytic.index] chưa được đăng ký");

        $this->actingAs($this->createAdmin())->get('/admin');
    }

    public function test_header_menu_item_with_unregistered_route_fails_loudly(): void
    {
        config(['login.cms.header_menu' => [['label' => 'Reports', 'route' => 'admin.reports.index']]]);
        $this->withoutExceptionHandling();

        $this->expectException(\Illuminate\View\ViewException::class);
        $this->expectExceptionMessage("Menu 'Reports': route [admin.reports.index] chưa được đăng ký");

        $this->actingAs($this->createAdmin())->get('/admin');
    }

    public function test_admin_path_is_configurable(): void
    {
        $this->reloadModuleRoutes(['login.web.admin_path' => 'cms']);

        $this->actingAs($this->createAdmin())->get('/cms')->assertOk()->assertSee('Dashboard');
        $this->get('/admin')->assertNotFound();
        $this->assertSame(url('/cms'), route('login.admin'));
    }

    public function test_admin_page_works_with_config_published_before_it_existed(): void
    {
        // config/login.php publish tu ban cu: khoi 'web' cua project thay ca khoi 'web' cua module
        $this->reloadModuleRoutes(['login.web' => ['enabled' => true, 'admin_role' => 1, 'home' => '/', 'user_model' => null]]);

        $this->actingAs($this->createAdmin())->get('/admin')->assertOk()->assertSee('Dashboard');
    }

    public function test_admin_page_can_be_disabled(): void
    {
        $this->reloadModuleRoutes(['login.web.admin_page' => false]);

        $this->actingAs($this->createAdmin())->get('/admin')->assertNotFound();
        $this->assertFalse(Route::has('login.admin'));
        $this->get('/login')->assertOk();
    }
}
