<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['meeting_message_id', 'user_id', 'meeting_participant_id', 'kind'])]
class MeetingMessageReaction extends Model
{
    /** @return BelongsTo<MeetingMessage, $this> */
    public function message(): BelongsTo
    {
        return $this->belongsTo(MeetingMessage::class, 'meeting_message_id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<MeetingParticipant, $this> */
    public function guestParticipant(): BelongsTo
    {
        return $this->belongsTo(MeetingParticipant::class, 'meeting_participant_id');
    }
}
