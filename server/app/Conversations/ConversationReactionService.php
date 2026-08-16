<?php

namespace App\Conversations;

use App\Events\ConversationReactionChanged;
use App\Models\Message;
use App\Models\User;
use Illuminate\Validation\ValidationException;

final class ConversationReactionService
{
    /** @var list<string> */
    public const SUPPORTED_KINDS = ['approve', 'support', 'done'];

    public function __construct(
        private readonly ConversationAccess $access,
        private readonly ConversationWriteAccess $writeAccess,
    ) {}

    public function add(User $user, string $conversationPublicId, string $messagePublicId, string $kind): Message
    {
        $message = $this->resolveWritableMessage($user, $conversationPublicId, $messagePublicId);
        $reaction = $message->reactions()->firstOrCreate([
            'user_id' => $user->getKey(),
            'kind' => $kind,
        ]);

        if ($reaction->wasRecentlyCreated) {
            ConversationReactionChanged::dispatch($message);
        }

        return $this->reload($message);
    }

    public function remove(User $user, string $conversationPublicId, string $messagePublicId, string $kind): Message
    {
        $message = $this->resolveWritableMessage($user, $conversationPublicId, $messagePublicId);
        $removed = $message->reactions()
            ->where('user_id', $user->getKey())
            ->where('kind', $kind)
            ->delete();

        if ($removed > 0) {
            ConversationReactionChanged::dispatch($message);
        }

        return $this->reload($message);
    }

    private function resolveWritableMessage(User $user, string $conversationPublicId, string $messagePublicId): Message
    {
        $conversation = $this->access->resolveReadable($user, $conversationPublicId);
        $this->writeAccess->authorize($conversation, $user);

        $message = $conversation->messages()
            ->with('latestRevision')
            ->where('public_id', $messagePublicId)
            ->firstOrFail();

        if ($message->isDeleted()) {
            throw ValidationException::withMessages([
                'message' => ['A deleted message cannot receive reactions.'],
            ]);
        }

        return $message;
    }

    private function reload(Message $message): Message
    {
        return $message->load([
            'author',
            'parent',
            'mentions.mentionedUser',
            'attentionTargets.targetedUser',
            'reactions',
            'latestRevision',
        ]);
    }
}
