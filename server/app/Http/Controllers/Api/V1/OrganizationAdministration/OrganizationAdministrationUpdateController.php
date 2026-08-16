<?php

namespace App\Http\Controllers\Api\V1\OrganizationAdministration;

use App\Http\Controllers\Controller;
use App\Http\Requests\OrganizationNameRequest;
use App\Http\Resources\OrganizationAdministrationResource;
use App\Models\Organization;
use App\Models\User;
use App\Organizations\OrganizationAdministration;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

final class OrganizationAdministrationUpdateController extends Controller
{
    public function __invoke(
        OrganizationNameRequest $request,
        Organization $organization,
        OrganizationAdministration $administration,
    ): JsonResponse {
        /** @var User $user */
        $user = $request->user();
        $requestId = (string) Str::uuid();
        $updated = $administration->rename(
            $user,
            $organization,
            $request->validated('name'),
            $requestId,
        );

        return OrganizationAdministrationResource::make($updated)
            ->response()
            ->header('X-Katra-Request-Id', $requestId);
    }
}
