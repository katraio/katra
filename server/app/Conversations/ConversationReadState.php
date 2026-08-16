<?php

namespace App\Conversations;

use App\Models\ConversationMembership;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ConversationReadState
{
    public function advance(ConversationMembership $membership, int $throughSequence): ConversationMembership
    {
        if ($throughSequence < 0) {
            throw ValidationException::withMessages([
                'through_sequence' => ['The read sequence cannot be negative.'],
            ]);
        }

        return DB::transaction(function () use ($membership, $throughSequence): ConversationMembership {
            $lockedMembership = ConversationMembership::query()
                ->with('conversation')
                ->whereKey($membership->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $greatestMessageSequence = $lockedMembership->conversation->next_message_sequence - 1;

            if ($throughSequence > $greatestMessageSequence) {
                throw ValidationException::withMessages([
                    'through_sequence' => ['The read sequence cannot exceed the conversation sequence.'],
                ]);
            }

            if ($throughSequence > $lockedMembership->last_read_sequence) {
                $lockedMembership->forceFill(['last_read_sequence' => $throughSequence])->save();
            }

            return $lockedMembership;
        });
    }
}
