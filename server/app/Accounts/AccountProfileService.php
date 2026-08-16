<?php

namespace App\Accounts;

use App\Enums\UserAccountEventKind;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

final class AccountProfileService
{
    public function updateName(
        User $user,
        string $firstName,
        string $lastName,
        string $requestId,
    ): User {
        return DB::transaction(function () use ($user, $firstName, $lastName, $requestId): User {
            $user->forceFill([
                'first_name' => $firstName,
                'last_name' => $lastName,
            ])->save();

            $user->accountEvents()->create([
                'request_id' => $requestId,
                'actor_user_id' => $user->getKey(),
                'kind' => UserAccountEventKind::ProfileUpdated,
            ]);

            return $user->refresh();
        });
    }

    public function updatePassword(User $user, string $password, string $requestId): void
    {
        DB::transaction(function () use ($user, $password, $requestId): void {
            $user->forceFill(['password' => Hash::make($password)])->save();

            $user->accountEvents()->create([
                'request_id' => $requestId,
                'actor_user_id' => $user->getKey(),
                'kind' => UserAccountEventKind::PasswordChanged,
            ]);
        });
    }
}
