<?php

namespace App\Models;

use App\Enums\DirectMessageState;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'direct_message_id',
    'from_state',
    'to_state',
    'actor_user_id',
    'created_at',
])]
class DirectMessageTransition extends Model
{
    public const UPDATED_AT = null;

    /** @return BelongsTo<DirectMessage, $this> */
    public function directMessage(): BelongsTo
    {
        return $this->belongsTo(DirectMessage::class);
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'from_state' => DirectMessageState::class,
            'to_state' => DirectMessageState::class,
            'created_at' => 'immutable_datetime',
        ];
    }
}
