<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Organizations\OrganizationInvitationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

final class OrganizationInvitationController extends Controller
{
    public function show(Request $request, OrganizationInvitationService $invitations): JsonResponse
    {
        $token = $request->validate([
            'token' => ['required', 'string', 'size:64', 'alpha_num'],
        ])['token'];
        $invitation = $invitations->inspect($token);

        if (! $invitation->isAcceptable()) {
            return response()->json(['message' => 'This invitation is no longer available.'], 410);
        }

        return response()->json([
            'data' => [
                'organization_name' => $invitation->organization->name,
                'email' => $invitation->email,
                'role' => $invitation->role->value,
                'expires_at' => $invitation->expires_at->toISOString(),
                'existing_account' => User::query()->where('email', $invitation->email)->exists(),
            ],
        ]);
    }

    public function accept(
        Request $request,
        OrganizationInvitationService $invitations,
    ): JsonResponse {
        $token = $request->validate([
            'token' => ['required', 'string', 'size:64', 'alpha_num'],
        ])['token'];
        $invitation = $invitations->inspect($token);

        if (! $invitation->isAcceptable()) {
            return response()->json(['message' => 'This invitation is no longer available.'], 410);
        }

        /** @var User|null $user */
        $user = $request->user();
        $created = false;

        if ($user === null) {
            if (User::query()->where('email', $invitation->email)->exists()) {
                throw ValidationException::withMessages([
                    'email' => ['Sign in with the invited email address before accepting.'],
                ]);
            }

            $validated = $request->validate([
                'first_name' => ['required', 'string', 'max:255'],
                'last_name' => ['required', 'string', 'max:255'],
                'password' => ['required', 'string', Password::default(), 'confirmed'],
            ]);

            $user = DB::transaction(function () use ($validated, $invitation, $invitations, $token): User {
                $newUser = User::query()->create([
                    'first_name' => $validated['first_name'],
                    'last_name' => $validated['last_name'],
                    'email' => $invitation->email,
                    'password' => $validated['password'],
                ]);

                $invitations->accept($token, $newUser);

                return $newUser;
            });
            $created = true;

            Auth::login($user);
            $request->session()->regenerate();
        } else {
            $invitations->accept($token, $user);
        }

        return response()->json([
            'data' => [
                'user_id' => $user->getKey(),
                'organization_id' => $invitation->organization->public_id,
                'role' => $invitation->role->value,
            ],
        ], $created ? 201 : 200);
    }
}
