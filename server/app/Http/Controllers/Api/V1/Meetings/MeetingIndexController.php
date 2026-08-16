<?php

namespace App\Http\Controllers\Api\V1\Meetings;

use App\Http\Controllers\Controller;
use App\Http\Resources\MeetingResource;
use App\Meetings\MeetingAccess;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class MeetingIndexController extends Controller
{
    public function __invoke(Request $request, MeetingAccess $access): AnonymousResourceCollection
    {
        /** @var User $user */
        $user = $request->user();
        $meetings = $access->visibleTo($user)
            ->with([
                'organization',
                'organizer',
                'participants.user',
                'participants.invitation',
                'invitations',
                'agendaItems.owner',
                'outcomes.author',
                'outcomes.guestAuthor',
                'outcomes.assignee',
                'outcomes.attentionItem',
            ])
            ->where('starts_at', '>=', now()->subDay())
            ->orderBy('starts_at')
            ->limit(100)
            ->get();

        return MeetingResource::collection($meetings);
    }
}
