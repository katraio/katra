<?php

namespace Tests\Feature\Api\V1;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use Laravel\Fortify\Contracts\CreatesNewUsers;
use RuntimeException;
use Tests\TestCase;

final class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_read_the_current_user(): void
    {
        $this->getJson('/api/v1/auth/user')->assertUnauthorized();
    }

    public function test_user_can_register_and_receive_an_authenticated_session(): void
    {
        $this->postWithCsrf('/auth/register', [
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'email' => '  ADA@EXAMPLE.COM ',
            'password' => 'correct horse battery staple',
            'password_confirmation' => 'correct horse battery staple',
        ])->assertCreated();

        $this->assertAuthenticated();

        $user = User::query()->sole();

        $this->assertSame('ada@example.com', $user->email);
        $this->assertTrue(Hash::check('correct horse battery staple', $user->password));
        $this->assertMatchesRegularExpression('/^[0-9A-HJKMNP-TV-Z]{26}$/', $user->public_id);

        $this->getJson('/api/v1/auth/user')
            ->assertOk()
            ->assertExactJson([
                'data' => [
                    'id' => $user->public_id,
                    'first_name' => 'Ada',
                    'last_name' => 'Lovelace',
                    'name' => 'Ada Lovelace',
                    'email' => 'ada@example.com',
                    'email_verified_at' => null,
                    'is_global_administrator' => false,
                ],
            ]);
    }

    public function test_registration_rejects_weak_passwords_and_duplicate_email_addresses(): void
    {
        User::factory()->create(['email' => 'ada@example.com']);

        $this->postWithCsrf('/auth/register', [
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'email' => 'ADA@example.com',
            'password' => 'short',
            'password_confirmation' => 'short',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['email', 'password']);
    }

    public function test_user_can_login_and_logout(): void
    {
        $user = User::factory()->create([
            'email' => 'ada@example.com',
            'password' => Hash::make('correct horse battery staple'),
        ]);

        $this->postWithCsrf('/auth/login', [
            'email' => 'ADA@EXAMPLE.COM',
            'password' => 'correct horse battery staple',
            'remember' => false,
        ])->assertOk()
            ->assertExactJson(['two_factor' => false]);

        $this->assertAuthenticatedAs($user);
        $this->getJson('/api/v1/auth/user')->assertOk();

        $this->postWithCsrf('/auth/logout')->assertNoContent();

        $this->assertGuest();
    }

    public function test_invalid_login_returns_a_generic_credential_error(): void
    {
        User::factory()->create([
            'email' => 'ada@example.com',
            'password' => Hash::make('correct horse battery staple'),
        ]);

        $this->postWithCsrf('/auth/login', [
            'email' => 'ada@example.com',
            'password' => 'this is not the password',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['email'])
            ->assertJsonMissing(['password']);
    }

    public function test_unexpected_authentication_failures_do_not_expose_internal_details(): void
    {
        Log::spy();

        $this->app->instance(CreatesNewUsers::class, new class implements CreatesNewUsers
        {
            public function create(array $input): User
            {
                throw new RuntimeException('database details and password hash');
            }
        });

        $this->postWithCsrf('/auth/register', [
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'email' => 'ada@example.com',
            'password' => 'correct horse battery staple',
            'password_confirmation' => 'correct horse battery staple',
        ])->assertInternalServerError()
            ->assertExactJson([
                'message' => 'Katra Server could not complete the request. Please try again.',
            ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function postWithCsrf(string $uri, array $data = []): TestResponse
    {
        $token = 'katra-test-csrf-token';

        return $this->withSession(['_token' => $token])
            ->postJson($uri, $data, ['X-CSRF-TOKEN' => $token]);
    }
}
