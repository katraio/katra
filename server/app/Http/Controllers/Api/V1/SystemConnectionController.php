<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

final class SystemConnectionController extends Controller
{
    /**
     * Confirm that a client can reach the versioned Katra Server API.
     */
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'data' => [
                'status' => 'connected',
                'application' => 'Katra Server',
                'api_version' => 'v1',
            ],
        ]);
    }
}
