<?php

namespace App\Http\Controllers\Api\V1\Meetings;

use App\Http\Controllers\Controller;
use App\Http\Resources\MeetingResource;
use App\Meetings\MeetingAccess;
use App\Models\User;
use Illuminate\Http\Request;

final class MeetingShowController extends Controller
{
    public function __invoke(Request $request, string $meeting, MeetingAccess $access): MeetingResource
    {
        /** @var User $user */
        $user = $request->user();
        $resolved = $access->findVisible($user, $meeting);
        $resolved->load([
            'organization',
            'organizer',
            'participants.user',
            'participants.invitation',
            'invitations.participant',
            'agendaItems.owner',
            'outcomes.author',
            'outcomes.guestAuthor',
            'outcomes.assignee',
            'outcomes.attentionItem',
        ]);

        return new MeetingResource($resolved);
    }
}
