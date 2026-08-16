<?php

namespace App\Http\Controllers\Api\V1\Meetings;

use App\Http\Controllers\Controller;
use App\Http\Resources\MeetingResource;
use App\Meetings\MeetingAccess;
use App\Meetings\MeetingInvitationService;
use App\Models\User;
use Illuminate\Http\Request;

final class MeetingInvitationController extends Controller
{
    public function store(
        Request $request,
        string $meeting,
        MeetingAccess $access,
        MeetingInvitationService $invitations,
    ): MeetingResource {
        /** @var User $user */
        $user = $request->user();
        $validated = $request->validate([
            'guest_emails' => ['required', 'array', 'min:1', 'max:25'],
            'guest_emails.*' => ['required', 'email:rfc', 'max:254', 'distinct:ignore_case'],
        ]);
        $resolved = $access->findVisible($user, $meeting);

        return new MeetingResource($invitations->add($resolved, $user, $validated['guest_emails']));
    }

    public function resend(
        Request $request,
        string $meeting,
        string $invitation,
        MeetingAccess $access,
        MeetingInvitationService $invitations,
    ): MeetingResource {
        /** @var User $user */
        $user = $request->user();
        $resolved = $access->findVisible($user, $meeting);
        $resolvedInvitation = $resolved->invitations()->where('public_id', $invitation)->firstOrFail();

        return new MeetingResource($invitations->resend($resolved, $resolvedInvitation, $user));
    }

    public function revoke(
        Request $request,
        string $meeting,
        string $invitation,
        MeetingAccess $access,
        MeetingInvitationService $invitations,
    ): MeetingResource {
        /** @var User $user */
        $user = $request->user();
        $resolved = $access->findVisible($user, $meeting);
        $resolvedInvitation = $resolved->invitations()->where('public_id', $invitation)->firstOrFail();

        return new MeetingResource($invitations->revoke($resolved, $resolvedInvitation, $user));
    }
}
