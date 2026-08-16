<?php

namespace App\Models;

use App\Enums\AttentionKind;
use App\Enums\AttentionPriority;
use App\Enums\AttentionState;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

#[Fillable([
    'user_id',
    'organization_id',
    'conversation_id',
    'kind',
    'priority',
    'state',
    'actor_user_id',
    'message_id',
    'direct_message_transition_id',
    'meeting_outcome_id',
    'viewed_at',
    'resolved_at',
    'resolved_by_user_id',
])]
class AttentionItem extends Model
{
    protected static function booted(): void
    {
        static::creating(function (AttentionItem $item): void {
            $item->public_id ??= (string) Str::ulid();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<Conversation, $this> */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    /** @return BelongsTo<Message, $this> */
    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }

    /** @return BelongsTo<DirectMessageTransition, $this> */
    public function directMessageTransition(): BelongsTo
    {
        return $this->belongsTo(DirectMessageTransition::class);
    }

    /** @return BelongsTo<MeetingOutcome, $this> */
    public function meetingOutcome(): BelongsTo
    {
        return $this->belongsTo(MeetingOutcome::class);
    }

    /** @return BelongsTo<User, $this> */
    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by_user_id');
    }

    /** @return Attribute<string, never> */
    protected function publicId(): Attribute
    {
        return Attribute::make(
            set: fn (string $value): string => Str::upper($value),
        );
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'kind' => AttentionKind::class,
            'priority' => AttentionPriority::class,
            'state' => AttentionState::class,
            'viewed_at' => 'immutable_datetime',
            'resolved_at' => 'immutable_datetime',
        ];
    }
}
