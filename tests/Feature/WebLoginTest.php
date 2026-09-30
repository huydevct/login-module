<?php

namespace Modules\Login\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class WebLoginTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware(['web', 'auth'])->get('/_login/admin', fn () => view('login::layouts.master'))->name('login.test.admin');
        Route::getRoutes()->refreshNameLookups();
        config([
            'login.web.home' => 'login.test.admin',
            'login.cms.menu' => [
                ['label' => 'Admin Home', 'route' => 'login.test.admin', 'icon' => 'cil-speedometer'],
            ],
        ]);
    }

    private function createAdmin(int $role = 1): object
    {
        $model = config('auth.providers.users.model');
        $user = new $model;
        $user->name = 'Admin';
        $user->email = 'admin@example.com';
        $user->login_name = 'admin@example.com';
        $user->password = Hash::make('secret123');
        $user->role = $role;
        $user->save();

        return $user;
    }

    public function test_login_page_renders(): void
    {
        $this->get('/login')->assertOk()->assertSee('Sign In to your account')->assertSee('/modules/login/css/style.css');
    }

    public function test_admin_can_login_and_see_layout_menu(): void
    {
        $this->createAdmin();

        $this->post('/login', ['email' => 'admin@example.com', 'password' => 'secret123'])
            ->assertRedirect(route('login.test.admin'));

        $this->get('/_login/admin')->assertOk()->assertSee('Admin Home');
    }

    public function test_non_admin_cannot_login(): void
    {
        $this->createAdmin(role: 0);

        $this->post('/login', ['email' => 'admin@example.com', 'password' => 'secret123'])->assertSessionHas('error');
        $this->assertGuest();
    }

    public function test_guest_is_redirected_to_login_and_can_logout(): void
    {
        $this->get('/_login/admin')->assertRedirect('/login');

        $this->actingAs($this->createAdmin())->get('/logout')->assertRedirect('/login');
        $this->assertGuest();
    }
}
