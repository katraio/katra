<?php

namespace App\Models;

use App\Enums\ChannelMembershipRole;
use Database\Factories\ConversationMembershipFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'conversation_id',
    'user_id',
    'channel_role',
    'last_read_sequence',
    'joined_at',
    'left_at',
    'removed_at',
    'added_by_user_id',
])]
class ConversationMembership extends Model
{
    /** @use HasFactory<ConversationMembershipFactory> */
    use HasFactory;

    public function isActive(): bool
    {
        return $this->left_at === null && $this->removed_at === null;
    }

    /** @return BelongsTo<Conversation, $this> */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'channel_role' => ChannelMembershipRole::class,
            'last_read_sequence' => 'integer',
            'joined_at' => 'immutable_datetime',
            'left_at' => 'immutable_datetime',
            'removed_at' => 'immutable_datetime',
        ];
    }
}
