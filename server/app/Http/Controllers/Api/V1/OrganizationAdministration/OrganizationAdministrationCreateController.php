<?php

namespace App\Http\Controllers\Api\V1\OrganizationAdministration;

use App\Http\Controllers\Controller;
use App\Http\Requests\OrganizationNameRequest;
use App\Http\Resources\OrganizationAdministrationResource;
use App\Models\User;
use App\Organizations\OrganizationAdministration;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

final class OrganizationAdministrationCreateController extends Controller
{
    public function __invoke(
        OrganizationNameRequest $request,
        OrganizationAdministration $administration,
    ): JsonResponse {
        /** @var User $user */
        $user = $request->user();
        $requestId = (string) Str::uuid();
        $organization = $administration->createClient(
            $user,
            $request->validated('name'),
            $requestId,
        );

        return OrganizationAdministrationResource::make($organization)
            ->response()
            ->setStatusCode(201)
            ->header('X-Katra-Request-Id', $requestId);
    }
}
