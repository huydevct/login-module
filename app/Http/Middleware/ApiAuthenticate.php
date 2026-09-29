<?php

namespace Modules\Login\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Response;
use Modules\Login\Helpers\LoginHelper;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Xac thuc request API bang JWT cua device (header Authorization: Bearer ... hoac ?access_token=).
 * Dang ky alias theo config login.api.middleware_alias (mac dinh auth.api).
 */
class ApiAuthenticate
{
    /**
     * @param  Closure(Request): (SymfonyResponse)  $next
     */
    public function handle(Request $request, Closure $next): SymfonyResponse
    {
        $auth = LoginHelper::AuthApi();
        $auth->reset();

        $token = $request->header('authorization');
        if (empty($token)) {
            $token = $request->input('access_token');
        }
        if (empty($token)) {
            return Response::json(['code' => 401, 'message' => 'Not auth,[ApiAuthenticate]', 'status' => 'not_found_token'], 401);
        }
        $token = str_replace('Bearer ', '', $token);
        if (! $auth->verify($token)) {
            return Response::json(['code' => 401, 'message' => 'Not auth,[ApiAuthenticate]', 'status' => 'expire'], 401);
        }

        $device_id = $auth->getDeviceId();
        if (empty($device_id)) {
            return Response::json(['code' => 401, 'message' => 'Not auth,[ApiAuthenticate]', 'status' => 'not_found_id'], 401);
        }

        $blocked_key = config('login.api.blocked_devices_redis_key');
        if (! empty($blocked_key) && Redis::sismember($blocked_key, $device_id)) {
            return Response::json(['code' => 403, 'message' => 'Account is deactivated,[ApiAuthenticate]'], 403);
        }

        return $next($request);
    }
}
