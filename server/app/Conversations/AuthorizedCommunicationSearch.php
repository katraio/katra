<?php

namespace App\Conversations;

use App\Models\Message;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

final class AuthorizedCommunicationSearch
{
    public function __construct(
        private readonly ChannelAccess $channels,
        private readonly DirectMessageAccess $directMessages,
    ) {}

    /** @return EloquentCollection<int, Message> */
    public function search(
        User $user,
        string $query,
        ?string $currentConversationPublicId = null,
        int $limit = 20,
    ): EloquentCollection {
        $authorizedConversationIds = $this->readableConversationIds($user);

        if ($authorizedConversationIds->isEmpty()) {
            return new EloquentCollection;
        }

        $fetchLimit = min(max($limit * 3, $limit), 75);

        /** @var EloquentCollection<int, Message> $ranked */
        $ranked = Message::search($query)
            ->whereIn('conversation_id', $authorizedConversationIds->all())
            ->take($fetchLimit)
            ->get()
            ->load([
                'author',
                'parent',
                'latestRevision',
                'conversation.channel',
                'conversation.directMessage.participants',
            ]);

        // Resolve authorization again after search ranking so a stale index or
        // concurrent membership revocation cannot make a result readable.
        $stillAuthorizedConversationIds = $this->readableConversationIds($user);
        $currentConversationId = $ranked
            ->first(fn (Message $message): bool => $message->conversation->public_id === $currentConversationPublicId)
            ?->conversation_id;

        $results = $ranked
            ->filter(fn (Message $message): bool => $stillAuthorizedConversationIds->contains($message->conversation_id)
                && ! $message->isDeleted())
            ->values();

        if ($currentConversationId !== null && $stillAuthorizedConversationIds->contains($currentConversationId)) {
            $results = $results
                ->sortByDesc(fn (Message $message): int => $message->conversation_id === $currentConversationId ? 1 : 0)
                ->values();
        }

        /** @var EloquentCollection<int, Message> $limited */
        $limited = new EloquentCollection($results->take($limit)->all());

        return $limited;
    }

    /** @return Collection<int, int> */
    private function readableConversationIds(User $user): Collection
    {
        return $this->channels->visibleTo($user)
            ->pluck('conversation_id')
            ->concat($this->directMessages->visibleTo($user)->pluck('conversation_id'))
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values();
    }
}
