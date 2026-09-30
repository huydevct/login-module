<?php

namespace Modules\Login\Http\Controllers\Web;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Modules\Login\Http\Requests\AuthRequest;

class AuthController
{
    public function login()
    {
        return view('login::pages.login');
    }

    public function loginPost(AuthRequest $request)
    {
        $credentials = [
            'login_name' => $request->email,
            'password' => $request->password,
            'role' => config('login.web.admin_role'),
        ];
        if (Auth::attempt($credentials)) {
            $request->session()->regenerate();

            // intended: vao trang admin khi chua login thi login xong quay lai dung trang do.
            return redirect()->intended($this->homeUrl())->with('success', 'Login Success');
        }

        return back()->withInput($request->only('email'))->with('error', 'Error Email or Password');
    }

    /**
     * Trang admin mac dinh: layout CoreUI + menu trong config login.cms.menu.
     */
    public function dashboard()
    {
        return view('login::pages.dashboard');
    }

    public function logout()
    {
        Auth::logout();
        request()->session()->invalidate();
        request()->session()->regenerateToken();

        return redirect()->route('login');
    }

    private function homeUrl(): string
    {
        $home = (string) config('login.web.home', '/');

        return Route::has($home) ? route($home) : url($home);
    }
}
