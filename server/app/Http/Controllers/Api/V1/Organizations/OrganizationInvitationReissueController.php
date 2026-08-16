<?php

namespace App\Http\Controllers\Api\V1\Organizations;

use App\Http\Controllers\Controller;
use App\Models\OrganizationInvitation;
use App\Models\User;
use App\Organizations\MemberAdministration;
use App\Organizations\OrganizationInvitationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class OrganizationInvitationReissueController extends Controller
{
    public function __invoke(
        Request $request,
        string $organization,
        string $invitation,
        MemberAdministration $administration,
        OrganizationInvitationService $invitations,
    ): JsonResponse {
        /** @var User $user */
        $user = $request->user();
        $scope = $administration->resolveScope($user, $organization);
        $existing = OrganizationInvitation::query()
            ->whereBelongsTo($scope)
            ->where('public_id', $invitation)
            ->firstOrFail();
        $issued = $invitations->reissue($existing, $user);

        return response()->json([
            'data' => [
                'id' => $issued->invitation->public_id,
                'organization_id' => $scope->public_id,
                'email' => $issued->invitation->email,
                'role' => $issued->invitation->role->value,
                'expires_at' => $issued->invitation->expires_at->toISOString(),
                'acceptance_url' => $issued->acceptanceUrl,
                'delivery_status' => $issued->invitation->last_delivery_status?->value,
                'last_delivery_at' => $issued->invitation->last_delivery_at?->toISOString(),
            ],
        ], 201);
    }
}
