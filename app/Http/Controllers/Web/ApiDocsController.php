<?php

namespace Modules\Login\Http\Controllers\Web;

use Illuminate\Http\JsonResponse;
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
}
