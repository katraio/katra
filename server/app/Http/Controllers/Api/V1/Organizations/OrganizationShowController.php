<?php

namespace App\Http\Controllers\Api\V1\Organizations;

use App\Auth\OrganizationAccess;
use App\Http\Controllers\Controller;
use App\Http\Resources\OrganizationResource;
use App\Models\User;
use Illuminate\Http\Request;

final class OrganizationShowController extends Controller
{
    public function __invoke(
        Request $request,
        string $organization,
        OrganizationAccess $access,
    ): OrganizationResource {
        /** @var User $user */
        $user = $request->user();

        $visibleOrganization = $access->visibleTo($user)
            ->where('public_id', $organization)
            ->firstOrFail();

        $this->authorize('view', $visibleOrganization);

        return OrganizationResource::make($visibleOrganization);
    }
}
