<?php

namespace App\Http\Controllers\Api\V1\Profile;

use App\Accounts\AccountProfileService;
use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateProfileRequest;
use App\Http\Resources\CurrentUserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

final class ProfileUpdateController extends Controller
{
    public function __invoke(
        UpdateProfileRequest $request,
        AccountProfileService $profiles,
    ): JsonResponse {
        /** @var User $user */
        $user = $request->user();
        $requestId = (string) Str::uuid();
        $updated = $profiles->updateName(
            $user,
            $request->validated('first_name'),
            $request->validated('last_name'),
            $requestId,
        );

        return CurrentUserResource::make($updated)
            ->response()
            ->header('X-Katra-Request-Id', $requestId);
    }
}
