<?php

namespace App\Http\Controllers\Api\V1\MemberAdministration;

use App\Http\Controllers\Controller;
use App\Models\OrganizationInvitation;
use App\Models\User;
use App\Organizations\MemberAdministration;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class MemberAdministrationInvitationIndexController extends Controller
{
    public function __invoke(
        Request $request,
        string $organization,
        MemberAdministration $administration,
    ): JsonResponse {
        /** @var User $user */
        $user = $request->user();
        $scope = $administration->resolveScope($user, $organization);
        $paginator = $administration->invitationHistory($user, $scope)->paginate(
            min(50, max(1, $request->integer('per_page', 25))),
        );

        return response()->json([
            'data' => collect($paginator->items())
                ->map(fn (OrganizationInvitation $invitation): array => [
                    'id' => $invitation->public_id,
                    'email' => $invitation->email,
                    'role' => $invitation->role->value,
                    'status' => $invitation->lifecycleStatus(),
                    'invited_by' => [
                        'id' => $invitation->invitedBy->public_id,
                        'name' => $invitation->invitedBy->name,
                    ],
                    'expires_at' => $invitation->expires_at->toISOString(),
                    'accepted_at' => $invitation->accepted_at?->toISOString(),
                    'revoked_at' => $invitation->revoked_at?->toISOString(),
                    'delivery_status' => $invitation->last_delivery_status?->value,
                    'last_delivery_at' => $invitation->last_delivery_at?->toISOString(),
                    'actions' => [
                        'reissue' => $administration->canReissue($user, $invitation),
                        'revoke' => $administration->canRevoke($user, $invitation),
                    ],
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
