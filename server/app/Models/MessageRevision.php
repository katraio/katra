<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

#[Fillable([
    'message_id',
    'actor_user_id',
    'sequence',
    'operation',
    'body',
])]
class MessageRevision extends Model
{
    protected static function booted(): void
    {
        static::creating(function (MessageRevision $revision): void {
            $revision->public_id ??= (string) Str::ulid();
        });
    }

    /** @return BelongsTo<Message, $this> */
    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
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
