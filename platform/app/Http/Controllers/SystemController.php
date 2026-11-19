<?php

namespace App\Http\Controllers;

use App\Http\Responses\Api;
use App\Support\HealthChecker;
use Illuminate\Http\JsonResponse;

/** Super Admin only (route guard). */
class SystemController extends Controller
{
    public function health(HealthChecker $checker): JsonResponse
    {
        return Api::ok($checker->run());
    }
}
