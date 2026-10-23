<?php

namespace Modules\Login\Http\Controllers\Web;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Login\Helpers\LoginHelper;
use Modules\Login\Services\ApiDocs\OpenApiSpec;

/**
 * Trang Swagger UI cho cac API cua module (sau middleware auth cua CMS).
 */
class ApiDocsController
{
    public function index()
    {
        return view('login::pages.api-docs');
    }

    public function spec(OpenApiSpec $spec): JsonResponse
    {
        return response()->json($spec->build(), 200, [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Tao secret cho POST add-device (giong `php artisan login:create-device-token` > encode).
     * Ma hoa o server: khoa openssl.device_secret khong duoc lo ra trinh duyet.
     */
    public function deviceSecret(Request $request): JsonResponse
    {
        $input = $request->validate([
            'client_id' => ['required', 'string', 'max:255'],
            'platform' => ['required', 'integer', 'in:0,1,2'],
            'package_id' => ['required', 'string', 'max:255'],
        ]);

        $time = time();
        $ttl = (int) config('login.api.secret_ttl');
        $payload = json_encode([
            'client_id' => $input['client_id'],
            'platform' => (int) $input['platform'],
            'package_id' => $input['package_id'],
            'time' => $time,
        ]);

        return response()->json([
            'secret' => LoginHelper::encodeOpenSsl($payload),
            'payload' => $payload,
            'created_at' => $time,
            'expires_at' => $time + $ttl,
            'ttl' => $ttl,
            // APP_DEBUG=true thi add-device bo qua han cua secret
            'expiry_enforced' => ! config('app.debug'),
        ], 200, [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
