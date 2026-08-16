<?php

namespace App\Models;

use App\Enums\ChannelVisibility;
use Database\Factories\ChannelFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['conversation_id', 'organization_id', 'name', 'slug', 'visibility'])]
class Channel extends Model
{
    /** @use HasFactory<ChannelFactory> */
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

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['visibility' => ChannelVisibility::class];
    }
}
