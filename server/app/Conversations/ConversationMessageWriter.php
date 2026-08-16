<?php

namespace App\Conversations;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ConversationMessageWriter
{
    /**
     * Persist one already-authorized human message and allocate its durable sequence.
     *
     * Authorization belongs to the conversation command that calls this persistence
     * service. No HTTP controller may call this class without that policy boundary.
     *
     * @param  iterable<User>  $mentionedUsers
     * @param  iterable<User>  $attentionUsers
     */
    public function append(
        Conversation $conversation,
        User $author,
        string $body,
        string $idempotencyKey,
        ?Message $parent = null,
        iterable $mentionedUsers = [],
        iterable $attentionUsers = [],
    ): Message {
        $body = trim($body);
        $idempotencyKey = trim($idempotencyKey);
        $mentionIds = collect($mentionedUsers)
            ->map(fn (User $user): int => $user->getKey())
            ->unique()
            ->sort()
            ->values();
        $attentionIds = collect($attentionUsers)
            ->map(fn (User $user): int => $user->getKey())
            ->unique()
            ->sort()
            ->values();
        $mentionIds = $mentionIds->reject(fn (int $userId): bool => $attentionIds->contains($userId))->values();

        if ($body === '' || mb_strlen($body) > 4000) {
            throw ValidationException::withMessages([
                'body' => ['A message body must contain between 1 and 4,000 characters.'],
            ]);
        }

        if ($idempotencyKey === '' || strlen($idempotencyKey) > 64) {
            throw ValidationException::withMessages([
                'idempotency_key' => ['The idempotency key must contain between 1 and 64 characters.'],
            ]);
        }

        if ($parent !== null && (
            $parent->conversation_id !== $conversation->getKey()
            || $parent->parent_message_id !== null
        )) {
            throw ValidationException::withMessages([
                'parent_message_id' => ['A thread reply must reference a root message in this conversation.'],
            ]);
        }

        return DB::transaction(function () use (
            $conversation,
            $author,
            $body,
            $idempotencyKey,
            $parent,
            $mentionIds,
            $attentionIds,
        ): Message {
            $lockedConversation = Conversation::query()
                ->whereKey($conversation->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $existing = $lockedConversation->messages()
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($existing !== null) {
                $existingMentionIds = $existing->mentions()
                    ->orderBy('mentioned_user_id')
                    ->pluck('mentioned_user_id');
                $existingAttentionIds = $existing->attentionTargets()
                    ->orderBy('targeted_user_id')
                    ->pluck('targeted_user_id');

                if (
                    $existing->author_user_id !== $author->getKey()
                    || $existing->body !== $body
                    || $existing->parent_message_id !== $parent?->getKey()
                    || $existingMentionIds->all() !== $mentionIds->all()
                    || $existingAttentionIds->all() !== $attentionIds->all()
                ) {
                    throw ValidationException::withMessages([
                        'idempotency_key' => ['This idempotency key was already used for a different message.'],
                    ]);
                }

                return $existing->load(['mentions', 'attentionTargets']);
            }

            $message = $lockedConversation->messages()->create([
                'sequence' => $lockedConversation->next_message_sequence,
                'author_user_id' => $author->getKey(),
                'idempotency_key' => $idempotencyKey,
                'parent_message_id' => $parent?->getKey(),
                'body' => $body,
            ]);

            $lockedConversation->increment('next_message_sequence');

            $message->mentions()->createMany(
                $mentionIds->map(fn (int $userId): array => ['mentioned_user_id' => $userId])->all(),
            );
            $message->attentionTargets()->createMany(
                $attentionIds->map(fn (int $userId): array => ['targeted_user_id' => $userId])->all(),
            );

            return $message->load(['mentions', 'attentionTargets']);
        });
    }
}
