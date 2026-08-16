<?php

namespace App\Models;

use App\Enums\DirectMessageState;
use Database\Factories\DirectMessageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'conversation_id',
    'organization_id',
    'participant_set_hash',
    'initiated_by_user_id',
    'internal_owner_user_id',
    'state',
    'completed_at',
    'completed_by_user_id',
    'continuation_requested_at',
    'continuation_requested_by_user_id',
])]
class DirectMessage extends Model
{
    /** @use HasFactory<DirectMessageFactory> */
    use HasFactory;

    /** @return BelongsTo<Conversation, $this> */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsToMany<User, $this> */
    public function participants(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'direct_message_participants')
            ->withTimestamps();
    }

    /** @return HasMany<DirectMessageParticipant, $this> */
    public function participantRecords(): HasMany
    {
        return $this->hasMany(DirectMessageParticipant::class);
    }

    /** @return HasMany<DirectMessageTransition, $this> */
    public function transitions(): HasMany
    {
        return $this->hasMany(DirectMessageTransition::class);
    }

    /** @return BelongsTo<User, $this> */
    public function initiatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'initiated_by_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function internalOwner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'internal_owner_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function continuationRequestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'continuation_requested_by_user_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'state' => DirectMessageState::class,
            'completed_at' => 'immutable_datetime',
            'continuation_requested_at' => 'immutable_datetime',
        ];
    }
}
