<?php

namespace App\Models;

use App\Enums\ConversationType;
use Database\Factories\ConversationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

#[Fillable([
    'organization_id',
    'type',
    'next_message_sequence',
    'created_by_user_id',
    'archived_at',
])]
class Conversation extends Model
{
    /** @use HasFactory<ConversationFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::creating(function (Conversation $conversation): void {
            $conversation->public_id ??= (string) Str::ulid();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /** @return HasOne<Channel, $this> */
    public function channel(): HasOne
    {
        return $this->hasOne(Channel::class);
    }

    /** @return HasOne<DirectMessage, $this> */
    public function directMessage(): HasOne
    {
        return $this->hasOne(DirectMessage::class);
    }

    /** @return HasMany<ConversationMembership, $this> */
    public function memberships(): HasMany
    {
        return $this->hasMany(ConversationMembership::class);
    }

    /** @return BelongsToMany<User, $this> */
    public function favoritedByUsers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'conversation_favorites')->withTimestamps();
    }

    /** @return HasMany<Message, $this> */
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    /** @return HasMany<Meeting, $this> */
    public function meetings(): HasMany
    {
        return $this->hasMany(Meeting::class);
    }

    /** @return HasOne<Meeting, $this> */
    public function liveMeeting(): HasOne
    {
        return $this->hasOne(Meeting::class)
            ->where('status', 'live')
            ->latestOfMany();
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
            'type' => ConversationType::class,
            'next_message_sequence' => 'integer',
            'archived_at' => 'immutable_datetime',
        ];
    }
}
