<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Libs\HealthCheck;
use Illuminate\Http\JsonResponse;

class HealthController extends Controller
{
    public function index(HealthCheck $healthCheck): JsonResponse
    {
        $result = $healthCheck->run();

        return response()->json(
            $result,
            $result['status'] === 'ok' ? 200 : 503
        );
    }
}
