<?php

namespace App\Http\Controllers\Api\V1\OrganizationAdministration;

use App\Http\Controllers\Controller;
use App\Http\Resources\OrganizationAdministrationResource;
use App\Models\User;
use App\Organizations\OrganizationAdministration;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class OrganizationAdministrationIndexController extends Controller
{
    public function __invoke(
        Request $request,
        OrganizationAdministration $administration,
    ): AnonymousResourceCollection {
        /** @var User $user */
        $user = $request->user();

        return OrganizationAdministrationResource::collection(
            $administration->organizations($user),
        );
    }
}
