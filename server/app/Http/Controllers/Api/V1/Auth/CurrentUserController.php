<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Resources\CurrentUserResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class CurrentUserController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        return CurrentUserResource::make($request->user())
            ->response()
            ->setStatusCode(200);
    }
}
