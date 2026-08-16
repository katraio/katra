<?php

namespace App\Conversations;

use App\Attention\AttentionService;
use App\Enums\ChannelVisibility;
use App\Enums\ConversationType;
use App\Enums\MembershipKind;
use App\Enums\MembershipStatus;
use App\Events\ConversationMessageChanged;
use App\Events\ConversationMessageCreated;
use App\Models\Channel;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\MessageRevision;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ConversationMessageService
{
    public function __construct(
        private readonly ConversationAccess $access,
        private readonly ChannelAccess $channels,
        private readonly ChannelService $channelService,
        private readonly DirectMessageAccess $directMessages,
        private readonly ConversationWriteAccess $writeAccess,
        private readonly ConversationMessageWriter $writer,
        private readonly AttentionService $attention,
    ) {}

    /**
     * @return array{
     *     messages: EloquentCollection<int, Message>,
     *     meta: array<string, mixed>
     * }
     */
    public function page(
        User $user,
        string $conversationPublicId,
        int $limit = 50,
        ?int $beforeSequence = null,
        ?int $afterSequence = null,
    ): array {
        $conversation = $this->access->resolveReadable($user, $conversationPublicId);
        $mode = $afterSequence !== null ? 'after' : ($beforeSequence !== null ? 'before' : 'latest');

        $query = $conversation->messages()
            ->with(['author', 'parent', 'mentions.mentionedUser', 'attentionTargets.targetedUser', 'reactions', 'latestRevision']);

        if ($afterSequence !== null) {
            $query->where('sequence', '>', $afterSequence)->orderBy('sequence');
        } else {
            if ($beforeSequence !== null) {
                $query->where('sequence', '<', $beforeSequence);
            }

            $query->orderByDesc('sequence');
        }

        /** @var EloquentCollection<int, Message> $window */
        $window = $query->limit($limit + 1)->get();
        $hasMore = $window->count() > $limit;
        $messages = $window->take($limit);

        if ($afterSequence === null) {
            $messages = $messages->reverse()->values();
        } else {
            $messages = $messages->values();
        }

        /** @var Message|null $oldest */
        $oldest = $messages->first();
        /** @var Message|null $newest */
        $newest = $messages->last();

        return [
            'messages' => $messages,
            'meta' => [
                'conversation_id' => $conversation->public_id,
                'conversation_type' => $conversation->type->value,
                'latest_sequence' => $conversation->next_message_sequence - 1,
                'pagination' => [
                    'mode' => $mode,
                    'limit' => $limit,
                    'has_more' => $hasMore,
                    'oldest_sequence' => $oldest?->sequence,
                    'newest_sequence' => $newest?->sequence,
                ],
            ],
        ];
    }

    /**
     * @param  list<string>  $mentionedUserPublicIds
     * @param  list<string>  $attentionUserPublicIds
     */
    public function send(
        User $author,
        string $conversationPublicId,
        string $body,
        string $idempotencyKey,
        ?string $parentMessagePublicId = null,
        array $mentionedUserPublicIds = [],
        array $attentionUserPublicIds = [],
    ): Message {
        $conversation = $this->access->resolveReadable($author, $conversationPublicId);

        return DB::transaction(function () use (
            $author,
            $conversation,
            $body,
            $idempotencyKey,
            $parentMessagePublicId,
            $mentionedUserPublicIds,
            $attentionUserPublicIds,
        ): Message {
            $this->writeAccess->authorize($conversation, $author);

            $parent = $this->resolveParent($conversation, $parentMessagePublicId);
            $mentionedUsers = $this->resolveMentionedUsers(
                $conversation,
                $author,
                $mentionedUserPublicIds,
                'mention_user_ids',
            );
            $attentionUsers = $this->resolveMentionedUsers(
                $conversation,
                $author,
                $attentionUserPublicIds,
                'attention_user_ids',
                allowSelf: false,
            );

            $message = $this->writer->append(
                $conversation,
                $author,
                $body,
                $idempotencyKey,
                $parent,
                $mentionedUsers,
                $attentionUsers,
            );

            if ($message->wasRecentlyCreated) {
                $targetedUsers = $mentionedUsers
                    ->concat($attentionUsers)
                    ->unique(fn (User $user): int => $user->getKey())
                    ->values();
                $this->enrollMentionedInternalUsers($conversation, $author, $targetedUsers);
                $this->attention->createForMessage($message, $author);
                ConversationMessageCreated::dispatch($message);
            }

            return $message->load([
                'author',
                'parent',
                'mentions.mentionedUser',
                'attentionTargets.targetedUser',
                'reactions',
                'latestRevision',
            ]);
        });
    }

    public function edit(
        User $author,
        string $conversationPublicId,
        string $messagePublicId,
        string $body,
    ): Message {
        $body = trim($body);

        if ($body === '' || mb_strlen($body) > 4000) {
            throw ValidationException::withMessages([
                'body' => ['A message body must contain between 1 and 4,000 characters.'],
            ]);
        }

        $message = $this->appendRevision(
            $author,
            $conversationPublicId,
            $messagePublicId,
            'edit',
            $body,
        );
        $message->searchable();
        ConversationMessageChanged::dispatch($message, 'edited');

        return $message;
    }

    public function delete(
        User $author,
        string $conversationPublicId,
        string $messagePublicId,
    ): Message {
        $message = $this->appendRevision(
            $author,
            $conversationPublicId,
            $messagePublicId,
            'delete',
            null,
        );
        $message->unsearchable();
        ConversationMessageChanged::dispatch($message, 'deleted');

        return $message;
    }

    private function appendRevision(
        User $author,
        string $conversationPublicId,
        string $messagePublicId,
        string $operation,
        ?string $body,
    ): Message {
        $conversation = $this->access->resolveReadable($author, $conversationPublicId);
        $this->writeAccess->authorize($conversation, $author);

        return DB::transaction(function () use ($author, $conversation, $messagePublicId, $operation, $body): Message {
            $message = $conversation->messages()
                ->where('public_id', $messagePublicId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($message->author_user_id !== $author->getKey()) {
                throw new AuthorizationException;
            }

            /** @var MessageRevision|null $latestRevision */
            $latestRevision = $message->revisions()->orderByDesc('sequence')->first();

            if ($latestRevision?->operation === 'delete') {
                throw ValidationException::withMessages([
                    'message' => ['A deleted message cannot be changed.'],
                ]);
            }

            $currentBody = $latestRevision?->body ?? $message->body;

            if ($operation === 'edit' && $currentBody === $body) {
                throw ValidationException::withMessages([
                    'body' => ['Change the message before saving.'],
                ]);
            }

            $message->revisions()->create([
                'actor_user_id' => $author->getKey(),
                'sequence' => ($latestRevision?->sequence ?? 0) + 1,
                'operation' => $operation,
                'body' => $body,
            ]);

            if ($operation === 'delete') {
                $message->reactions()->delete();
            }

            return $message->load([
                'author',
                'parent',
                'mentions.mentionedUser',
                'attentionTargets.targetedUser',
                'reactions',
                'latestRevision',
            ]);
        });
    }

    private function resolveParent(Conversation $conversation, ?string $publicId): ?Message
    {
        if ($publicId === null) {
            return null;
        }

        $parent = $conversation->messages()
            ->where('public_id', $publicId)
            ->whereNull('parent_message_id')
            ->first();

        if ($parent === null) {
            throw ValidationException::withMessages([
                'parent_message_id' => ['The selected thread parent is unavailable.'],
            ]);
        }

        return $parent;
    }

    /**
     * @param  list<string>  $publicIds
     * @return Collection<int, User>
     */
    private function resolveMentionedUsers(
        Conversation $conversation,
        User $author,
        array $publicIds,
        string $field,
        bool $allowSelf = true,
    ): Collection {
        if ($publicIds === []) {
            return collect();
        }

        /** @var Collection<int, User> $users */
        $users = User::query()->whereIn('public_id', $publicIds)->get();

        if ($users->count() !== count($publicIds)) {
            $this->unavailableMention($field);
        }

        foreach ($users as $user) {
            if (! $allowSelf && $user->is($author)) {
                $this->unavailableMention($field);
            }

            $mentionable = match ($conversation->type) {
                ConversationType::Channel => $this->authorizeChannelMention(
                    $conversation->channel,
                    $author,
                    $user,
                ),
                ConversationType::DirectMessage => $conversation->directMessage !== null
                    && $this->directMessages->isParticipant($conversation->directMessage, $user),
            };

            if (! $mentionable) {
                $this->unavailableMention($field);
            }
        }

        return $users;
    }

    private function authorizeChannelMention(?Channel $channel, User $author, User $mentioned): bool
    {
        if ($channel === null) {
            return false;
        }

        if ($channel->visibility === ChannelVisibility::Public) {
            return $this->channels->isOperatingInternal($mentioned);
        }

        if ($channel->visibility === ChannelVisibility::Private) {
            return $this->hasActiveConversationMembership($channel->conversation, $mentioned);
        }

        if ($this->isActiveClientMember($mentioned, $channel)) {
            return true;
        }

        if (! $this->channels->isOperatingInternal($mentioned)) {
            return false;
        }

        return true;
    }

    /** @param Collection<int, User> $mentionedUsers */
    private function enrollMentionedInternalUsers(
        Conversation $conversation,
        User $author,
        Collection $mentionedUsers,
    ): void {
        if (
            $conversation->type !== ConversationType::Channel
            || $conversation->channel?->visibility !== ChannelVisibility::ClientTeam
        ) {
            return;
        }

        foreach ($mentionedUsers as $mentioned) {
            if ($this->channels->isOperatingInternal($mentioned)) {
                $this->channelService->enrollMentionedInternal(
                    $conversation->channel,
                    $author,
                    $mentioned,
                );
            }
        }
    }

    private function hasActiveConversationMembership(Conversation $conversation, User $user): bool
    {
        return $conversation->memberships()
            ->where('user_id', $user->getKey())
            ->whereNull('left_at')
            ->whereNull('removed_at')
            ->exists();
    }

    private function isActiveClientMember(User $user, Channel $channel): bool
    {
        return $user->organizationMemberships()
            ->where('organization_id', $channel->organization_id)
            ->where('kind', MembershipKind::Client->value)
            ->where('status', MembershipStatus::Active->value)
            ->exists();
    }

    private function unavailableMention(string $field): never
    {
        throw ValidationException::withMessages([
            $field => ['One or more selected people are unavailable in this conversation.'],
        ]);
    }
}
