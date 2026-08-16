<?php

namespace App\Meetings;

use App\Enums\MeetingStatus;
use App\Events\MeetingMessageCreated;
use App\Models\Meeting;
use App\Models\MeetingMessage;
use App\Models\MeetingParticipant;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class MeetingChatService
{
    /**
     * @return array{
     *     messages: EloquentCollection<int, MeetingMessage>,
     *     meta: array<string, mixed>
     * }
     */
    public function page(
        Meeting $meeting,
        int $limit = 50,
        ?int $beforeSequence = null,
        ?int $afterSequence = null,
    ): array {
        $mode = $afterSequence !== null ? 'after' : ($beforeSequence !== null ? 'before' : 'latest');
        $query = $meeting->messages()->reorder()->with(['author', 'guestAuthor', 'reactions']);

        if ($afterSequence !== null) {
            $query->where('sequence', '>', $afterSequence)->orderBy('sequence');
        } else {
            if ($beforeSequence !== null) {
                $query->where('sequence', '<', $beforeSequence);
            }
            $query->orderByDesc('sequence');
        }

        /** @var EloquentCollection<int, MeetingMessage> $window */
        $window = $query->limit($limit + 1)->get();
        $hasMore = $window->count() > $limit;
        $messages = $window->take($limit);
        $messages = $afterSequence === null ? $messages->reverse()->values() : $messages->values();

        /** @var MeetingMessage|null $oldest */
        $oldest = $messages->first();
        /** @var MeetingMessage|null $newest */
        $newest = $messages->last();

        return [
            'messages' => $messages,
            'meta' => [
                'meeting_id' => $meeting->public_id,
                'latest_sequence' => max(0, $meeting->next_message_sequence - 1),
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

    public function send(Meeting $meeting, User $author, string $body, string $idempotencyKey): MeetingMessage
    {
        $participant = $meeting->participants()
            ->where('user_id', $author->getKey())
            ->whereNull('removed_at')
            ->firstOrFail();

        return $this->sendForParticipant($meeting, $participant, $body, $idempotencyKey, $author->getKey());
    }

    public function sendAsGuest(Meeting $meeting, MeetingParticipant $author, string $body, string $idempotencyKey): MeetingMessage
    {
        return $this->sendForParticipant($meeting, $author, $body, $idempotencyKey, null);
    }

    private function sendForParticipant(
        Meeting $meeting,
        MeetingParticipant $author,
        string $body,
        string $idempotencyKey,
        ?int $authorUserId,
    ): MeetingMessage {
        $normalizedBody = trim($body);
        $requestHash = hash('sha256', json_encode(['body' => $normalizedBody], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($meeting, $author, $authorUserId, $normalizedBody, $idempotencyKey, $requestHash): MeetingMessage {
            /** @var Meeting $locked */
            $locked = Meeting::query()->whereKey($meeting->getKey())->lockForUpdate()->firstOrFail();
            $lockedAuthor = $locked->participants()
                ->whereKey($author->getKey())
                ->whereNull('removed_at')
                ->lockForUpdate()
                ->first();

            if ($locked->status !== MeetingStatus::Live || $lockedAuthor === null) {
                throw ValidationException::withMessages([
                    'meeting' => ['Meeting chat is writable only by recorded participants while the meeting is live.'],
                ]);
            }

            $existing = $locked->messages()->where('idempotency_key', $idempotencyKey)->first();
            if ($existing !== null) {
                if (! hash_equals($existing->request_hash, $requestHash)) {
                    throw ValidationException::withMessages([
                        'idempotency_key' => ['The idempotency key was already used for different meeting chat content.'],
                    ]);
                }

                return $existing->load(['author', 'guestAuthor', 'reactions']);
            }

            $message = $locked->messages()->create([
                'sequence' => $locked->next_message_sequence,
                'author_user_id' => $authorUserId,
                'author_meeting_participant_id' => $authorUserId === null ? $lockedAuthor->getKey() : null,
                'body' => $normalizedBody,
                'idempotency_key' => $idempotencyKey,
                'request_hash' => $requestHash,
            ]);
            $locked->forceFill(['next_message_sequence' => $locked->next_message_sequence + 1])->save();
            MeetingMessageCreated::dispatch($message);

            return $message->load(['author', 'guestAuthor', 'reactions']);
        });
    }
}
