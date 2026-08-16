<?php

namespace App\Conversations;

use App\Attention\AttentionService;
use App\Auth\OrganizationAuthorization;
use App\Enums\ConversationType;
use App\Enums\DirectMessageState;
use App\Enums\MembershipKind;
use App\Enums\MembershipStatus;
use App\Enums\OrganizationAbility;
use App\Enums\OrganizationKind;
use App\Events\ConversationAccessChanged;
use App\Models\Conversation;
use App\Models\DirectMessage;
use App\Models\DirectMessageTransition;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class DirectMessageService
{
    public function __construct(
        private readonly DirectMessageAccess $access,
        private readonly OrganizationAuthorization $authorization,
        private readonly AttentionService $attention,
    ) {}

    /** @param iterable<User> $requestedParticipants */
    public function create(
        Organization $organization,
        User $initiator,
        iterable $requestedParticipants,
    ): DirectMessage {
        if (! $this->access->isOperatingInternal($initiator)) {
            throw new AuthorizationException;
        }

        if (! $this->authorization->allows(
            $initiator,
            $organization,
            OrganizationAbility::CreateDirectMessages->value,
        )) {
            throw new AuthorizationException;
        }

        /** @var Collection<int, User> $participants */
        $participants = collect($requestedParticipants)
            ->push($initiator)
            ->unique(fn (User $user): int => $user->getKey())
            ->sortBy(fn (User $user): int => $user->getKey())
            ->values();

        if ($participants->count() < 2) {
            throw ValidationException::withMessages([
                'participant_ids' => ['A Direct Message requires at least two people.'],
            ]);
        }

        $hasClients = false;

        foreach ($participants as $participant) {
            if ($this->isInternalAuthorizedForOrganization($participant, $organization)) {
                continue;
            }

            if (
                $organization->kind === OrganizationKind::Client
                && $this->hasActiveMembership($participant, $organization, MembershipKind::Client)
            ) {
                $hasClients = true;

                continue;
            }

            throw ValidationException::withMessages([
                'participant_ids' => [
                    'Every participant must be an authorized internal member or an active client of this Organization.',
                ],
            ]);
        }

        if ($organization->kind === OrganizationKind::Client && ! $hasClients) {
            throw ValidationException::withMessages([
                'participant_ids' => [
                    'A client-Organization Direct Message must include at least one active client member.',
                ],
            ]);
        }

        $participantIds = $participants->map(fn (User $user): int => $user->getKey());
        $participantSetHash = md5($participantIds->implode(','));

        $directMessage = DB::transaction(function () use (
            $organization,
            $initiator,
            $participants,
            $participantSetHash,
            $hasClients,
        ): DirectMessage {
            Organization::query()->whereKey($organization->getKey())->lockForUpdate()->firstOrFail();

            $existing = DirectMessage::query()
                ->where('organization_id', $organization->getKey())
                ->where('participant_set_hash', $participantSetHash)
                ->first();

            if ($existing !== null) {
                return $existing->load(['conversation', 'organization', 'participants']);
            }

            $conversation = Conversation::query()->create([
                'organization_id' => $organization->getKey(),
                'type' => ConversationType::DirectMessage,
                'created_by_user_id' => $initiator->getKey(),
            ]);

            $directMessage = $conversation->directMessage()->create([
                'organization_id' => $organization->getKey(),
                'participant_set_hash' => $participantSetHash,
                'initiated_by_user_id' => $initiator->getKey(),
                'internal_owner_user_id' => $hasClients ? $initiator->getKey() : null,
                'state' => DirectMessageState::Open,
            ]);

            foreach ($participants as $participant) {
                $directMessage->participantRecords()->create(['user_id' => $participant->getKey()]);
                $conversation->memberships()->create([
                    'user_id' => $participant->getKey(),
                    'joined_at' => now(),
                    'added_by_user_id' => $initiator->getKey(),
                ]);
            }

            $directMessage->transitions()->create([
                'from_state' => null,
                'to_state' => DirectMessageState::Open,
                'actor_user_id' => $initiator->getKey(),
                'created_at' => now(),
            ]);

            return $directMessage->load(['conversation', 'organization', 'participants']);
        });

        if ($directMessage->wasRecentlyCreated) {
            $directMessage->participants
                ->where('id', '!=', $initiator->getKey())
                ->each(fn (User $participant) => ConversationAccessChanged::dispatch(
                    $participant,
                    $directMessage->conversation,
                    'granted',
                ));
        }

        return $directMessage;
    }

    public function complete(DirectMessage $directMessage, User $actor): DirectMessage
    {
        $this->authorizeInternalLifecycleActor($directMessage, $actor);

        return $this->transition($directMessage, $actor, function (DirectMessage $locked, User $actor): void {
            if ($locked->state === DirectMessageState::Completed) {
                return;
            }

            if ($locked->state !== DirectMessageState::Open) {
                throw ValidationException::withMessages([
                    'state' => ['Resolve the pending continuation request before completing the conversation.'],
                ]);
            }

            $now = now();
            $locked->forceFill([
                'state' => DirectMessageState::Completed,
                'completed_at' => $now,
                'completed_by_user_id' => $actor->getKey(),
                'continuation_requested_at' => null,
                'continuation_requested_by_user_id' => null,
            ])->save();
            $this->recordTransition($locked, DirectMessageState::Open, DirectMessageState::Completed, $actor);
        });
    }

    public function requestContinuation(DirectMessage $directMessage, User $actor): DirectMessage
    {
        if (! $this->access->isClientParticipant($directMessage, $actor)) {
            throw new AuthorizationException;
        }

        return $this->transition($directMessage, $actor, function (DirectMessage $locked, User $actor): void {
            if ($locked->state === DirectMessageState::ContinuationRequested) {
                return;
            }

            if ($locked->state !== DirectMessageState::Completed) {
                throw ValidationException::withMessages([
                    'state' => ['Continuation can be requested only after the conversation is complete.'],
                ]);
            }

            $locked->forceFill([
                'state' => DirectMessageState::ContinuationRequested,
                'continuation_requested_at' => now(),
                'continuation_requested_by_user_id' => $actor->getKey(),
            ])->save();
            $transition = $this->recordTransition(
                $locked,
                DirectMessageState::Completed,
                DirectMessageState::ContinuationRequested,
                $actor,
            );
            $this->attention->createForContinuation($locked, $transition, $actor);
        });
    }

    public function reopen(DirectMessage $directMessage, User $actor): DirectMessage
    {
        $this->authorizeInternalLifecycleActor($directMessage, $actor);

        return $this->transition($directMessage, $actor, function (DirectMessage $locked, User $actor): void {
            if ($locked->state === DirectMessageState::Open) {
                return;
            }

            $from = $locked->state;
            $locked->forceFill([
                'state' => DirectMessageState::Open,
                'completed_at' => null,
                'completed_by_user_id' => null,
                'continuation_requested_at' => null,
                'continuation_requested_by_user_id' => null,
            ])->save();
            $this->recordTransition($locked, $from, DirectMessageState::Open, $actor);
            $this->attention->resolveContinuation($locked, $actor);
        });
    }

    private function authorizeInternalLifecycleActor(DirectMessage $directMessage, User $actor): void
    {
        if ($directMessage->internal_owner_user_id === null) {
            throw ValidationException::withMessages([
                'state' => ['Internal-only Direct Messages do not use the client completion lifecycle.'],
            ]);
        }

        if (
            ! $this->access->canRead($actor, $directMessage)
            || ! $this->access->isInternalParticipant($directMessage, $actor)
        ) {
            throw new AuthorizationException;
        }
    }

    /** @param callable(DirectMessage, User): void $change */
    private function transition(
        DirectMessage $directMessage,
        User $actor,
        callable $change,
    ): DirectMessage {
        return DB::transaction(function () use ($directMessage, $actor, $change): DirectMessage {
            $locked = DirectMessage::query()->lockForUpdate()->findOrFail($directMessage->getKey());
            $change($locked, $actor);

            return $locked->fresh(['conversation', 'organization', 'participants', 'transitions']);
        });
    }

    private function recordTransition(
        DirectMessage $directMessage,
        DirectMessageState $from,
        DirectMessageState $to,
        User $actor,
    ): DirectMessageTransition {
        return $directMessage->transitions()->create([
            'from_state' => $from,
            'to_state' => $to,
            'actor_user_id' => $actor->getKey(),
            'created_at' => now(),
        ]);
    }

    private function isInternalAuthorizedForOrganization(User $user, Organization $organization): bool
    {
        if (! $this->access->isOperatingInternal($user)) {
            return false;
        }

        return $user->isGlobalAdministrator()
            || $this->hasActiveMembership($user, $organization, MembershipKind::Internal);
    }

    private function hasActiveMembership(
        User $user,
        Organization $organization,
        MembershipKind $kind,
    ): bool {
        return $user->organizationMemberships()
            ->where('organization_id', $organization->getKey())
            ->where('kind', $kind->value)
            ->where('status', MembershipStatus::Active->value)
            ->exists();
    }
}
