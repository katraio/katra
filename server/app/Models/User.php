<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\SystemRole;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Appends;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use Silber\Bouncer\Database\HasRolesAndAbilities;

#[Fillable(['first_name', 'last_name', 'name', 'email', 'password'])]
#[Hidden(['password', 'remember_token', 'two_factor_recovery_codes', 'two_factor_secret'])]
#[Appends(['name'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRolesAndAbilities, Notifiable;

    protected static function booted(): void
    {
        static::creating(function (User $user): void {
            $user->public_id ??= (string) Str::ulid();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /** @return HasMany<OrganizationMembership, $this> */
    public function organizationMemberships(): HasMany
    {
        return $this->hasMany(OrganizationMembership::class);
    }

    /** @return HasMany<AttentionItem, $this> */
    public function attentionItems(): HasMany
    {
        return $this->hasMany(AttentionItem::class);
    }

    /** @return HasMany<ConversationMembership, $this> */
    public function conversationMemberships(): HasMany
    {
        return $this->hasMany(ConversationMembership::class);
    }

    /** @return HasMany<MeetingParticipant, $this> */
    public function meetingParticipations(): HasMany
    {
        return $this->hasMany(MeetingParticipant::class);
    }

    /** @return HasMany<UserAccountEvent, $this> */
    public function accountEvents(): HasMany
    {
        return $this->hasMany(UserAccountEvent::class);
    }

    public function isGlobalAdministrator(): bool
    {
        return $this->isAn(SystemRole::GlobalAdministrator->value);
    }

    /**
     * Preserve the historical combined name attribute while storing its parts.
     *
     * @return Attribute<string, string>
     */
    protected function name(): Attribute
    {
        return Attribute::make(
            get: fn (): string => trim($this->first_name.' '.$this->last_name),
            set: function (string $value): array {
                $parts = preg_split('/\s+/', trim($value), 2);

                return [
                    'first_name' => $parts[0] ?? '',
                    'last_name' => $parts[1] ?? '',
                ];
            },
        );
    }

    /** @return Attribute<string, never> */
    protected function publicId(): Attribute
    {
        return Attribute::make(
            set: fn (string $value): string => Str::upper($value),
        );
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }
}
