<?php

namespace App\Http\Controllers\Api\V1\Organizations;

use App\Auth\OrganizationAccess;
use App\Http\Controllers\Controller;
use App\Http\Resources\OrganizationResource;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class OrganizationIndexController extends Controller
{
    public function __invoke(Request $request, OrganizationAccess $access): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Organization::class);

        /** @var User $user */
        $user = $request->user();

        $organizations = $access->visibleTo($user)
            ->orderBy('name')
            ->get();

        return OrganizationResource::collection($organizations);
    }
}
