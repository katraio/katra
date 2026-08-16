<?php

namespace App\Http\Controllers\Api\V1\Organizations;

use App\Enums\MembershipStatus;
use App\Enums\OrganizationRole;
use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\User;
use App\Organizations\OrganizationInvitationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class OrganizationInvitationCreateController extends Controller
{
    public function __invoke(
        Request $request,
        string $organization,
        OrganizationInvitationService $invitations,
    ): JsonResponse {
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

        $validated = $request->validate([
            'email' => ['required', 'string', 'email:rfc', 'max:254'],
            'role' => ['required', Rule::enum(OrganizationRole::class)],
        ]);

        $issued = $invitations->issue(
            $visibleOrganization,
            $user,
            $validated['email'],
            OrganizationRole::from($validated['role']),
        );

        return response()->json([
            'data' => [
                'id' => $issued->invitation->public_id,
                'organization_id' => $visibleOrganization->public_id,
                'email' => $issued->invitation->email,
                'role' => $issued->invitation->role->value,
                'expires_at' => $issued->invitation->expires_at->toISOString(),
                'acceptance_url' => $issued->acceptanceUrl,
                'email_delivery_attempted_at' => $issued->invitation->last_sent_at?->toISOString(),
                'delivery_status' => $issued->invitation->last_delivery_status?->value,
                'last_delivery_at' => $issued->invitation->last_delivery_at?->toISOString(),
            ],
        ], 201);
    }
}
