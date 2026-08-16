<?php

namespace App\Meetings;

use App\Enums\AttentionKind;
use App\Enums\AttentionPriority;
use App\Enums\AttentionState;
use App\Enums\MeetingOutcomeKind;
use App\Enums\MeetingStatus;
use App\Events\AttentionItemChanged;
use App\Events\MeetingOutcomeCreated;
use App\Models\AttentionItem;
use App\Models\Meeting;
use App\Models\MeetingOutcome;
use App\Models\MeetingParticipant;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class MeetingOutcomeService
{
    /** @return EloquentCollection<int, MeetingOutcome> */
    public function list(Meeting $meeting): EloquentCollection
    {
        return $meeting->outcomes()
            ->with(['author', 'guestAuthor', 'assignee', 'attentionItem'])
            ->get();
    }

    public function create(
        Meeting $meeting,
        User $actor,
        MeetingOutcomeKind $kind,
        string $body,
        ?User $assignee,
    ): MeetingOutcome {
        return DB::transaction(function () use ($meeting, $actor, $kind, $body, $assignee): MeetingOutcome {
            $locked = Meeting::query()->lockForUpdate()->findOrFail($meeting->getKey());

            if ($locked->status !== MeetingStatus::Live) {
                throw ValidationException::withMessages([
                    'meeting' => ['Meeting outcomes can be added only while the meeting is live.'],
                ]);
            }

            if (! $locked->participants()->where('user_id', $actor->getKey())->whereNull('removed_at')->exists()) {
                throw new AuthorizationException;
            }

            if ($kind === MeetingOutcomeKind::Action) {
                if ($assignee === null || ! $locked->participants()->where('user_id', $assignee->getKey())->whereNull('removed_at')->exists()) {
                    throw ValidationException::withMessages([
                        'assignee_user_id' => ['Select a current Katra meeting participant.'],
                    ]);
                }
            } elseif ($assignee !== null) {
                throw ValidationException::withMessages([
                    'assignee_user_id' => ['Only an action item can have an assignee.'],
                ]);
            }

            $outcome = $locked->outcomes()->create([
                'sequence' => ((int) $locked->outcomes()->max('sequence')) + 1,
                'kind' => $kind,
                'body' => trim($body),
                'author_user_id' => $actor->getKey(),
                'assignee_user_id' => $assignee?->getKey(),
            ]);

            if ($kind === MeetingOutcomeKind::Action && $assignee !== null) {
                $attention = AttentionItem::query()->create([
                    'user_id' => $assignee->getKey(),
                    'organization_id' => $locked->organization_id,
                    'conversation_id' => $locked->conversation_id,
                    'kind' => AttentionKind::MeetingAction,
                    'priority' => AttentionPriority::Normal,
                    'state' => AttentionState::Open,
                    'actor_user_id' => $actor->getKey(),
                    'meeting_outcome_id' => $outcome->getKey(),
                ]);
                AttentionItemChanged::dispatch($attention, 'created');
            }

            MeetingOutcomeCreated::dispatch($outcome);

            return $outcome->load(['author', 'guestAuthor', 'assignee', 'attentionItem']);
        });
    }

    public function createAsGuest(
        Meeting $meeting,
        MeetingParticipant $actor,
        MeetingOutcomeKind $kind,
        string $body,
    ): MeetingOutcome {
        if ($kind === MeetingOutcomeKind::Action) {
            throw ValidationException::withMessages([
                'kind' => ['Meeting guests can add notes and decisions, but cannot assign actions.'],
            ]);
        }

        return DB::transaction(function () use ($meeting, $actor, $kind, $body): MeetingOutcome {
            $locked = Meeting::query()->lockForUpdate()->findOrFail($meeting->getKey());
            $lockedActor = $locked->participants()
                ->whereKey($actor->getKey())
                ->whereNull('removed_at')
                ->lockForUpdate()
                ->first();
            if ($locked->status !== MeetingStatus::Live || $lockedActor === null) {
                throw ValidationException::withMessages([
                    'meeting' => ['Meeting outcomes can be added only by recorded participants while the meeting is live.'],
                ]);
            }

            $outcome = $locked->outcomes()->create([
                'sequence' => ((int) $locked->outcomes()->max('sequence')) + 1,
                'kind' => $kind,
                'body' => trim($body),
                'author_meeting_participant_id' => $lockedActor->getKey(),
            ]);
            MeetingOutcomeCreated::dispatch($outcome);

            return $outcome->load(['author', 'guestAuthor', 'assignee', 'attentionItem']);
        });
    }
}
