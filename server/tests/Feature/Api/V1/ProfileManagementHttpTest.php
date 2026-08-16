<?php

namespace Tests\Feature\Api\V1;

use App\Enums\UserAccountEventKind;
use App\Models\User;
use App\Models\UserAccountEvent;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class ProfileManagementHttpTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_mutate_profile_or_password(): void
    {
        $this->patchJson('/api/v1/profile', [
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
        ])->assertUnauthorized();

        $this->putJson('/api/v1/profile/password', [
            'current_password' => 'password',
            'password' => 'correct horse battery staple',
            'password_confirmation' => 'correct horse battery staple',
        ])->assertUnauthorized();
    }

    public function test_user_can_update_only_their_own_name_with_correlated_audit_event(): void
    {
        $user = User::factory()->create([
            'first_name' => 'Ada',
            'last_name' => 'Byron',
            'email' => 'ada@example.com',
        ]);

        $response = $this->actingAs($user)->patchJson('/api/v1/profile', [
            'first_name' => '  Ada  ',
            'last_name' => '  Lovelace  ',
        ])->assertOk()
            ->assertExactJson([
                'data' => [
                    'id' => $user->public_id,
                    'first_name' => 'Ada',
                    'last_name' => 'Lovelace',
                    'name' => 'Ada Lovelace',
                    'email' => 'ada@example.com',
                    'email_verified_at' => $user->email_verified_at?->toISOString(),
                    'is_global_administrator' => false,
                ],
            ]);

        $requestId = $response->headers->get('X-Katra-Request-Id');
        $this->assertNotNull($requestId);
        $this->assertDatabaseHas('users', [
            'id' => $user->getKey(),
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'email' => 'ada@example.com',
        ]);
        $this->assertDatabaseHas('user_account_events', [
            'request_id' => $requestId,
            'user_id' => $user->getKey(),
            'actor_user_id' => $user->getKey(),
            'kind' => UserAccountEventKind::ProfileUpdated->value,
        ]);
    }

    public function test_profile_rejects_identity_and_authority_fields_not_in_the_contract(): void
    {
        $user = User::factory()->create([
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'email' => 'ada@example.com',
        ]);

        $this->actingAs($user)->patchJson('/api/v1/profile', [
            'first_name' => 'Grace',
            'last_name' => 'Hopper',
            'email' => 'grace@example.com',
            'is_global_administrator' => true,
            'avatar' => 'data:image/png;base64,not-accepted',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('request');

        $user->refresh();
        $this->assertSame('Ada', $user->first_name);
        $this->assertSame('Lovelace', $user->last_name);
        $this->assertSame('ada@example.com', $user->email);
        $this->assertDatabaseCount('user_account_events', 0);
    }

    public function test_profile_rejects_control_only_and_punctuation_only_names(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->patchJson('/api/v1/profile', [
            'first_name' => "\n\t",
            'last_name' => '---',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['first_name', 'last_name']);
    }

    public function test_user_can_change_password_only_with_the_current_password(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('original password phrase'),
        ]);

        $this->actingAs($user)->putJson('/api/v1/profile/password', [
            'current_password' => 'not the original password',
            'password' => 'replacement password phrase',
            'password_confirmation' => 'replacement password phrase',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('current_password');

        $this->assertDatabaseCount('user_account_events', 0);

        $response = $this->actingAs($user)->putJson('/api/v1/profile/password', [
            'current_password' => 'original password phrase',
            'password' => 'replacement password phrase',
            'password_confirmation' => 'replacement password phrase',
        ])->assertNoContent();

        $this->assertAuthenticatedAs($user);
        $this->assertFalse(Hash::check('original password phrase', $user->fresh()->password));
        $this->assertTrue(Hash::check('replacement password phrase', $user->fresh()->password));
        $this->assertDatabaseHas('user_account_events', [
            'request_id' => $response->headers->get('X-Katra-Request-Id'),
            'user_id' => $user->getKey(),
            'actor_user_id' => $user->getKey(),
            'kind' => UserAccountEventKind::PasswordChanged->value,
        ]);
    }

    public function test_account_events_are_immutable(): void
    {
        $user = User::factory()->create();
        $event = UserAccountEvent::query()->create([
            'request_id' => fake()->uuid(),
            'user_id' => $user->getKey(),
            'actor_user_id' => $user->getKey(),
            'kind' => UserAccountEventKind::ProfileUpdated,
        ]);

        $this->expectException(QueryException::class);

        $event->forceFill(['kind' => UserAccountEventKind::PasswordChanged])->save();
    }
}
