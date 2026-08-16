<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['direct_message_id', 'user_id'])]
class DirectMessageParticipant extends Model
{
    /** @return BelongsTo<DirectMessage, $this> */
    public function directMessage(): BelongsTo
    {
        return $this->belongsTo(DirectMessage::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
