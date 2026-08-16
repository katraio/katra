<?php

namespace App\Meetings;

use App\Auth\OrganizationAuthorization;
use App\Enums\MeetingStatus;
use App\Enums\MembershipKind;
use App\Enums\MembershipStatus;
use App\Enums\OrganizationAbility;
use App\Enums\OrganizationKind;
use App\Events\MeetingAccessChanged;
use App\Events\MeetingParticipantAccessChanged;
use App\Events\MeetingStateChanged;
use App\Models\Conversation;
use App\Models\Meeting;
use App\Models\Organization;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class MeetingService
{
    public function __construct(
        private readonly OrganizationAuthorization $authorization,
        private readonly MeetingParticipantEligibility $participantEligibility,
        private readonly MeetingInvitationService $invitations,
        private readonly MeetingGuestSecurityMonitor $securityMonitor,
    ) {}

    /**
     * @param  iterable<User>  $requestedParticipants
     * @param  list<string>  $guestEmails
     * @param  list<array{title: string, owner_user_id: string|null, duration_minutes: int}>  $agendaItems
     */
    public function create(
        Organization $organization,
        User $organizer,
        iterable $requestedParticipants,
        string $title,
        CarbonImmutable $startsAt,
        int $durationMinutes,
        ?string $desiredOutcome,
        array $guestEmails,
        array $agendaItems,
        bool $startsLive = false,
        ?Conversation $conversation = null,
    ): Meeting {
        if (
            ! $this->isOperatingInternal($organizer)
            || ! $this->authorization->allows(
                $organizer,
                $organization,
                OrganizationAbility::CreateMeetings->value,
            )
        ) {
            throw new AuthorizationException;
        }

        /** @var Collection<int, User> $participants */
        $participants = collect($requestedParticipants)
            ->push($organizer)
            ->unique(fn (User $user): int => $user->getKey())
            ->values();

        foreach ($participants as $participant) {
            if ($this->participantEligibility->allows($participant, $organization)) {
                continue;
            }

            throw ValidationException::withMessages([
                'participant_ids' => [
                    'Every participant must be an authorized internal member or an active client of this Organization.',
                ],
            ]);
        }

        $participantsByPublicId = $participants->keyBy('public_id');

        foreach ($agendaItems as $position => $agendaItem) {
            $ownerPublicId = $agendaItem['owner_user_id'];

            if ($ownerPublicId !== null && ! $participantsByPublicId->has(Str::upper($ownerPublicId))) {
                throw ValidationException::withMessages([
                    "agenda_items.{$position}.owner_user_id" => [
                        'An agenda owner must be one of the invited Katra participants.',
                    ],
                ]);
            }
        }

        $emails = collect($guestEmails)
            ->map(fn (string $email): string => mb_strtolower(trim($email)))
            ->unique()
            ->values();

        $meeting = DB::transaction(function () use (
            $organization,
            $organizer,
            $participants,
            $participantsByPublicId,
            $title,
            $startsAt,
            $durationMinutes,
            $desiredOutcome,
            $emails,
            $agendaItems,
            $startsLive,
            $conversation,
        ): Meeting {
            $guestLinkToken = Str::random(64);
            $startsAtUtc = $startsAt->utc();
            $expiresAt = $startsAtUtc->addMinutes($durationMinutes);

            $meeting = Meeting::query()->create([
                'organization_id' => $organization->getKey(),
                'conversation_id' => $conversation?->getKey(),
                'organizer_user_id' => $organizer->getKey(),
                'title' => trim($title),
                'starts_at' => $startsAtUtc,
                'duration_minutes' => $durationMinutes,
                'desired_outcome' => filled($desiredOutcome) ? trim((string) $desiredOutcome) : null,
                'status' => $startsLive ? MeetingStatus::Live : MeetingStatus::Scheduled,
                'started_at' => $startsLive ? $startsAtUtc : null,
                'guest_link_token_hash' => hash('sha256', $guestLinkToken),
                'guest_link_token' => $guestLinkToken,
                'guest_link_expires_at' => $expiresAt,
            ]);

            foreach ($participants as $participant) {
                $meeting->participants()->create([
                    'user_id' => $participant->getKey(),
                    'kind' => 'user',
                    'added_by_user_id' => $organizer->getKey(),
                ]);
            }

            foreach ($emails as $email) {
                $token = Str::random(64);
                $meeting->invitations()->create([
                    'email' => $email,
                    'token_hash' => hash('sha256', $token),
                    'token' => $token,
                    'expires_at' => $expiresAt,
                    'created_by_user_id' => $organizer->getKey(),
                ]);
            }

            foreach ($agendaItems as $position => $agendaItem) {
                $owner = $agendaItem['owner_user_id'] === null
                    ? null
                    : $participantsByPublicId->get(Str::upper($agendaItem['owner_user_id']));
                $meeting->agendaItems()->create([
                    'position' => $position + 1,
                    'title' => trim($agendaItem['title']),
                    'owner_user_id' => $owner?->getKey(),
                    'duration_minutes' => $agendaItem['duration_minutes'],
                ]);
            }

            $meeting->load([
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
            ]);

            foreach ($participants as $participant) {
                MeetingAccessChanged::dispatch($participant, $meeting, 'granted');
            }

            if ($startsLive) {
                MeetingStateChanged::dispatch($meeting);
            }

            return $meeting;
        });

        $this->invitations->queueCreated($meeting, $organizer);

        return $meeting->load([
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
    }

    /** @param iterable<User> $requestedParticipants */
    public function addParticipants(
        Meeting $meeting,
        User $actor,
        iterable $requestedParticipants,
    ): Meeting {
        if ($meeting->organizer_user_id !== $actor->getKey()) {
            throw new AuthorizationException;
        }

        $meeting->loadMissing('organization');
        /** @var Collection<int, User> $participants */
        $participants = collect($requestedParticipants)
            ->unique(fn (User $user): int => $user->getKey())
            ->values();

        foreach ($participants as $participant) {
            if ($this->participantEligibility->allows($participant, $meeting->organization)) {
                continue;
            }

            throw ValidationException::withMessages([
                'participant_ids' => [
                    'Every participant must be an authorized internal member or an active client of this Organization.',
                ],
            ]);
        }

        return DB::transaction(function () use ($meeting, $actor, $participants): Meeting {
            $locked = Meeting::query()->lockForUpdate()->findOrFail($meeting->getKey());

            foreach ($participants as $participant) {
                $created = $locked->participants()->firstOrCreate(
                    ['user_id' => $participant->getKey()],
                    [
                        'kind' => 'user',
                        'added_by_user_id' => $actor->getKey(),
                    ],
                );

                if ($created->wasRecentlyCreated) {
                    MeetingAccessChanged::dispatch($participant, $locked, 'granted');

                    continue;
                }

                if ($created->removed_at !== null) {
                    $created->forceFill([
                        'removed_by_user_id' => null,
                        'removed_at' => null,
                    ])->save();
                    $created->events()->create([
                        'meeting_id' => $locked->getKey(),
                        'kind' => 'restored',
                        'actor_user_id' => $actor->getKey(),
                    ]);
                    MeetingAccessChanged::dispatch($participant, $locked, 'granted');
                    MeetingParticipantAccessChanged::dispatch($locked, $created, 'restored');
                    DB::afterCommit(fn () => $this->securityMonitor->record('participant-restored'));
                }
            }

            return $locked->fresh([
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
        });
    }

    private function isOperatingInternal(User $user): bool
    {
        if ($user->isGlobalAdministrator()) {
            return true;
        }

        return $user->organizationMemberships()
            ->where('kind', MembershipKind::Internal->value)
            ->where('status', MembershipStatus::Active->value)
            ->whereHas(
                'organization',
                fn ($query) => $query->where('kind', OrganizationKind::Operating->value),
            )
            ->exists();
    }
}
