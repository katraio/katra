<?php

namespace App\Http\Controllers\Api\V1\Organizations;

use App\Enums\MembershipStatus;
use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\User;
use App\Organizations\OrganizationInvitationService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class OrganizationInvitationRevokeController extends Controller
{
    public function __invoke(
        Request $request,
        string $organization,
        string $invitation,
        OrganizationInvitationService $invitations,
    ): Response {
        /** @var User $user */
        $user = $request->user();

        $visibleOrganization = Organization::query()
            ->when(! $user->isGlobalAdministrator(), fn ($query) => $query->whereHas(
                'memberships',
                fn ($membership) => $membership
                    ->where('user_id', $user->getKey())
                    ->where('status', MembershipStatus::Active->value),
            ))
            ->where('public_id', $organization)
            ->firstOrFail();

        $organizationInvitation = OrganizationInvitation::query()
            ->whereBelongsTo($visibleOrganization)
            ->where('public_id', $invitation)
            ->firstOrFail();

        $invitations->revoke($organizationInvitation, $user);

        return response()->noContent();
    }
}
