<?php

namespace App\Meetings;

use App\Enums\ConversationType;
use App\Enums\DirectMessageState;
use App\Enums\MeetingStatus;
use App\Events\MeetingAccessChanged;
use App\Models\Conversation;
use App\Models\Meeting;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

final class ConversationMeetingService
{
    public function __construct(
        private readonly MeetingService $meetings,
        private readonly MeetingParticipantEligibility $participantEligibility,
    ) {}

    public function startOrJoin(Conversation $conversation, User $actor, string $title): Meeting
    {
        return DB::transaction(function () use ($conversation, $actor, $title): Meeting {
            $locked = Conversation::query()
                ->with([
                    'organization',
                    'channel',
                    'directMessage.participants',
                ])
                ->lockForUpdate()
                ->findOrFail($conversation->getKey());

            $live = Meeting::query()
                ->where('conversation_id', $locked->getKey())
                ->where('status', MeetingStatus::Live->value)
                ->lockForUpdate()
                ->first();

            if ($live !== null) {
                return $this->joinExisting($live, $actor);
            }

            $this->assertCanStart($locked);
            $participants = $locked->type === ConversationType::DirectMessage
                ? $locked->directMessage?->participants ?? collect([$actor])
                : collect([$actor]);

            return $this->meetings->create(
                $locked->organization,
                $actor,
                $participants,
                $title,
                CarbonImmutable::now(),
                30,
                null,
                [],
                [],
                true,
                $locked,
            );
        });
    }

    private function joinExisting(Meeting $meeting, User $actor): Meeting
    {
        $meeting->loadMissing('organization');

        if (! $this->participantEligibility->allows($actor, $meeting->organization)) {
            throw new AuthorizationException;
        }

        $participant = $meeting->participants()->firstOrCreate(
            ['user_id' => $actor->getKey()],
            [
                'kind' => 'user',
                'added_by_user_id' => $actor->getKey(),
            ],
        );

        if ($participant->wasRecentlyCreated) {
            MeetingAccessChanged::dispatch($actor, $meeting, 'granted');
        } elseif ($participant->removed_at !== null) {
            throw new AuthorizationException;
        }

        return $meeting->fresh([
            'organization',
            'conversation',
            'organizer',
            'participants.user',
            'invitations.participant',
            'agendaItems.owner',
            'outcomes.author',
            'outcomes.guestAuthor',
            'outcomes.assignee',
            'outcomes.attentionItem',
        ]);
    }

    private function assertCanStart(Conversation $conversation): void
    {
        if ($conversation->archived_at !== null) {
            throw new MeetingRoomTransitionException('An archived conversation cannot start a meeting.');
        }

        if (
            $conversation->type === ConversationType::DirectMessage
            && $conversation->directMessage?->state !== DirectMessageState::Open
        ) {
            throw new MeetingRoomTransitionException('A completed Direct Message cannot start a meeting.');
        }
    }
}
