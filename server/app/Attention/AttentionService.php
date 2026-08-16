<?php

namespace App\Attention;

use App\Conversations\ConversationAccess;
use App\Enums\AttentionKind;
use App\Enums\AttentionPriority;
use App\Enums\AttentionState;
use App\Events\AttentionItemChanged;
use App\Meetings\MeetingAccess;
use App\Models\AttentionItem;
use App\Models\DirectMessage;
use App\Models\DirectMessageTransition;
use App\Models\Message;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

final class AttentionService
{
    public function __construct(
        private readonly ConversationAccess $conversations,
        private readonly MeetingAccess $meetings,
    ) {}

    public function createForContinuation(
        DirectMessage $directMessage,
        DirectMessageTransition $transition,
        User $actor,
    ): ?AttentionItem {
        if ($directMessage->internal_owner_user_id === null) {
            return null;
        }

        $item = AttentionItem::query()->firstOrCreate(
            [
                'kind' => AttentionKind::DirectMessageContinuation,
                'direct_message_transition_id' => $transition->getKey(),
                'user_id' => $directMessage->internal_owner_user_id,
            ],
            [
                'organization_id' => $directMessage->organization_id,
                'conversation_id' => $directMessage->conversation_id,
                'priority' => AttentionPriority::Normal,
                'state' => AttentionState::Open,
                'actor_user_id' => $actor->getKey(),
            ],
        );

        if ($item->wasRecentlyCreated) {
            AttentionItemChanged::dispatch($item, 'created');
        }

        return $item;
    }

    /** @return EloquentCollection<int, AttentionItem> */
    public function createForMessage(Message $message, User $actor): EloquentCollection
    {
        $message->loadMissing(['conversation', 'attentionTargets.targetedUser']);
        $items = new EloquentCollection;

        foreach ($message->attentionTargets as $target) {
            $item = AttentionItem::query()->firstOrCreate(
                [
                    'kind' => AttentionKind::MessageAttentionRequest,
                    'message_id' => $message->getKey(),
                    'user_id' => $target->targeted_user_id,
                ],
                [
                    'organization_id' => $message->conversation->organization_id,
                    'conversation_id' => $message->conversation_id,
                    'priority' => AttentionPriority::Normal,
                    'state' => AttentionState::Open,
                    'actor_user_id' => $actor->getKey(),
                ],
            );

            if ($item->wasRecentlyCreated) {
                AttentionItemChanged::dispatch($item, 'created');
            }

            $items->push($item);
        }

        return $items;
    }

    /** @return EloquentCollection<int, AttentionItem> */
    public function unresolvedFor(User $user): EloquentCollection
    {
        $items = AttentionItem::query()
            ->where('user_id', $user->getKey())
            ->whereIn('kind', [
                AttentionKind::MessageAttentionRequest,
                AttentionKind::DirectMessageContinuation,
                AttentionKind::MeetingAction,
            ])
            ->where('state', AttentionState::Open)
            ->with($this->relations())
            ->orderByRaw("CASE priority WHEN 'high' THEN 0 ELSE 1 END")
            ->orderBy('created_at')
            ->get();

        return $items
            ->filter(fn (AttentionItem $item): bool => $this->sourceIsReadable($user, $item))
            ->values();
    }

    public function markViewed(User $user, string $publicId): AttentionItem
    {
        $this->resolveVisible($user, $publicId);

        return DB::transaction(function () use ($user, $publicId): AttentionItem {
            $item = $this->assignedQuery($user, $publicId)->lockForUpdate()->firstOrFail();

            if ($item->viewed_at === null) {
                $item->forceFill(['viewed_at' => now()])->save();
                AttentionItemChanged::dispatch($item, 'viewed');
            }

            return $item->load($this->relations());
        });
    }

    public function resolve(User $user, string $publicId): AttentionItem
    {
        $this->resolveVisible($user, $publicId);

        return DB::transaction(function () use ($user, $publicId): AttentionItem {
            $item = $this->assignedQuery($user, $publicId)->lockForUpdate()->firstOrFail();

            $this->resolveLocked($item, $user);

            return $item->load($this->relations());
        });
    }

    public function resolveContinuation(DirectMessage $directMessage, User $actor): void
    {
        if ($directMessage->internal_owner_user_id === null) {
            return;
        }

        AttentionItem::query()
            ->where('kind', AttentionKind::DirectMessageContinuation)
            ->where('conversation_id', $directMessage->conversation_id)
            ->where('user_id', $directMessage->internal_owner_user_id)
            ->where('state', AttentionState::Open)
            ->lockForUpdate()
            ->get()
            ->each(fn (AttentionItem $item) => $this->resolveLocked($item, $actor));
    }

    public function resolveVisible(User $user, string $publicId): AttentionItem
    {
        $item = $this->assignedQuery($user, $publicId)
            ->with($this->relations())
            ->firstOrFail();

        if (! $this->sourceIsReadable($user, $item)) {
            throw (new ModelNotFoundException)->setModel(AttentionItem::class, [$publicId]);
        }

        return $item;
    }

    /** @return list<string> */
    private function relations(): array
    {
        return [
            'user',
            'organization',
            'actor',
            'conversation.channel',
            'conversation.directMessage.participants',
            'message.parent',
            'directMessageTransition',
            'meetingOutcome.meeting',
            'meetingOutcome.author',
            'meetingOutcome.assignee',
            'resolvedBy',
        ];
    }

    private function assignedQuery(User $user, string $publicId)
    {
        return AttentionItem::query()
            ->where('public_id', $publicId)
            ->whereIn('kind', [
                AttentionKind::MessageAttentionRequest,
                AttentionKind::DirectMessageContinuation,
                AttentionKind::MeetingAction,
            ])
            ->where('user_id', $user->getKey());
    }

    private function sourceIsReadable(User $user, AttentionItem $item): bool
    {
        try {
            if ($item->kind === AttentionKind::MeetingAction) {
                $meeting = $item->meetingOutcome?->meeting;
                if ($meeting === null) {
                    return false;
                }
                $this->meetings->findVisible($user, $meeting->public_id);

                return true;
            }

            if ($item->conversation === null) {
                return false;
            }
            $this->conversations->resolveReadable($user, $item->conversation->public_id);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function resolveLocked(AttentionItem $item, User $actor): void
    {
        if ($item->state === AttentionState::Resolved) {
            return;
        }

        $item->forceFill([
            'state' => AttentionState::Resolved,
            'viewed_at' => $item->viewed_at ?? now(),
            'resolved_at' => now(),
            'resolved_by_user_id' => $actor->getKey(),
        ])->save();

        AttentionItemChanged::dispatch($item, 'resolved');
    }
}
