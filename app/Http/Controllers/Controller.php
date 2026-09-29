<?php

namespace Modules\Login\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Response;

abstract class Controller
{
    public function response($data, int $status_code = 200, array $headers = []): JsonResponse
    {
        return Response::json([
            'data' => $data,
            'status' => $status_code,
        ], $status_code, $headers);
    }
}
