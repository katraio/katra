<?php

namespace App\Http\Controllers\Api\V1\MemberAdministration;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\User;
use App\Organizations\MemberAdministration;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class MemberAdministrationScopeIndexController extends Controller
{
    public function __invoke(Request $request, MemberAdministration $administration): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json([
            'data' => $administration->scopes($user)
                ->map(fn (Organization $organization): array => [
                    'organization' => [
                        'id' => $organization->public_id,
                        'name' => $organization->name,
                        'kind' => $organization->kind->value,
                    ],
                    'allowed_invitation_roles' => collect(
                        $administration->allowedInvitationRoles($user, $organization),
                    )->map(fn ($role): array => [
                        'value' => $role->value,
                        'label' => $role->label(),
                    ])->values(),
                    'actions' => [
                        'view_members' => true,
                        'view_invitations' => true,
                        'invite' => $administration->allowedInvitationRoles($user, $organization) !== [],
                    ],
                ])
                ->values(),
        ]);
    }
}
