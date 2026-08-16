<?php

namespace App\Http\Controllers\Api\V1\Profile;

use App\Accounts\AccountProfileService;
use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateProfilePasswordRequest;
use App\Models\User;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

final class ProfilePasswordUpdateController extends Controller
{
    public function __invoke(
        UpdateProfilePasswordRequest $request,
        AccountProfileService $profiles,
    ): Response {
        /** @var User $user */
        $user = $request->user();
        $requestId = (string) Str::uuid();
        $profiles->updatePassword($user, $request->validated('password'), $requestId);

        return response()
            ->noContent()
            ->header('X-Katra-Request-Id', $requestId);
    }
}
