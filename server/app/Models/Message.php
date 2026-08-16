<?php

namespace App\Models;

use Database\Factories\MessageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;
use Laravel\Scout\Searchable;

#[Fillable([
    'conversation_id',
    'sequence',
    'author_user_id',
    'idempotency_key',
    'parent_message_id',
    'body',
])]
class Message extends Model
{
    /** @use HasFactory<MessageFactory> */
    use HasFactory, Searchable;

    protected static function booted(): void
    {
        static::creating(function (Message $message): void {
            $message->public_id ??= (string) Str::ulid();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function searchableAs(): string
    {
        return 'communication_messages';
    }

    /** @return array<string, int|string> */
    public function toSearchableArray(): array
    {
        $this->loadMissing([
            'author',
            'latestRevision',
            'conversation.channel',
            'conversation.directMessage.participants',
        ]);

        $conversation = $this->conversation;
        $conversationLabel = $conversation->channel?->name
            ?? $conversation->directMessage?->participants
                ->sortBy('id')
                ->pluck('name')
                ->implode(', ')
            ?? '';

        return [
            'id' => (int) $this->getScoutKey(),
            'public_id' => $this->public_id,
            'conversation_id' => (int) $this->conversation_id,
            'conversation_public_id' => $conversation->public_id,
            'body' => $this->currentBody(),
            'author_name' => $this->author->name,
            'conversation_label' => $conversationLabel,
            'created_at' => $this->created_at->getTimestamp(),
        ];
    }

    public function shouldBeSearchable(): bool
    {
        return ! $this->isDeleted();
    }

    /** @param Builder<Message> $query */
    public function makeAllSearchableUsing(Builder $query): Builder
    {
        return $query->with([
            'author',
            'latestRevision',
            'conversation.channel',
            'conversation.directMessage.participants',
        ]);
    }

    /** @return BelongsTo<Conversation, $this> */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_user_id');
    }

    /** @return BelongsTo<Message, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_message_id');
    }

    /** @return HasMany<Message, $this> */
    public function replies(): HasMany
    {
        return $this->hasMany(self::class, 'parent_message_id');
    }

    /** @return HasMany<MessageMention, $this> */
    public function mentions(): HasMany
    {
        return $this->hasMany(MessageMention::class);
    }

    /** @return HasMany<MessageAttentionTarget, $this> */
    public function attentionTargets(): HasMany
    {
        return $this->hasMany(MessageAttentionTarget::class);
    }

    /** @return HasMany<MessageReaction, $this> */
    public function reactions(): HasMany
    {
        return $this->hasMany(MessageReaction::class);
    }

    /** @return HasMany<MessageRevision, $this> */
    public function revisions(): HasMany
    {
        return $this->hasMany(MessageRevision::class);
    }

    /** @return HasOne<MessageRevision, $this> */
    public function latestRevision(): HasOne
    {
        return $this->hasOne(MessageRevision::class)->latestOfMany('sequence');
    }

    public function currentBody(): ?string
    {
        $revision = $this->relationLoaded('latestRevision')
            ? $this->latestRevision
            : $this->latestRevision()->first();

        return $revision?->operation === 'delete' ? null : ($revision?->body ?? $this->body);
    }

    public function isDeleted(): bool
    {
        $revision = $this->relationLoaded('latestRevision')
            ? $this->latestRevision
            : $this->latestRevision()->first();

        return $revision?->operation === 'delete';
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
        return ['sequence' => 'integer'];
    }
}
