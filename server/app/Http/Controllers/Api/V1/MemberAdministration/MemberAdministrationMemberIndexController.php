<?php

namespace App\Http\Controllers\Api\V1\MemberAdministration;

use App\Http\Controllers\Controller;
use App\Models\OrganizationMembership;
use App\Models\User;
use App\Organizations\MemberAdministration;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class MemberAdministrationMemberIndexController extends Controller
{
    public function __invoke(
        Request $request,
        string $organization,
        MemberAdministration $administration,
    ): JsonResponse {
        /** @var User $user */
        $user = $request->user();
        $scope = $administration->resolveScope($user, $organization);
        $paginator = $administration->members($user, $scope)->paginate(
            min(50, max(1, $request->integer('per_page', 25))),
        );

        return response()->json([
            'data' => collect($paginator->items())
                ->map(fn (OrganizationMembership $membership): array => [
                    'id' => $membership->user->public_id,
                    'name' => $membership->user->name,
                    'email' => $membership->user->email,
                    'kind' => $membership->kind->value,
                    'status' => $membership->status->value,
                    'role' => $administration->roleFor($membership, $scope)?->value,
                    'joined_at' => $membership->joined_at?->toISOString(),
                ])
                ->values(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }
}
