<?php

namespace App\Http\Controllers\Api\V1\DirectMessages;

use App\Auth\OrganizationAccess;
use App\Conversations\DirectMessageService;
use App\Http\Controllers\Controller;
use App\Http\Resources\DirectMessageResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class DirectMessageCreateController extends Controller
{
    public function __invoke(
        Request $request,
        string $organization,
        OrganizationAccess $organizations,
        DirectMessageService $directMessages,
    ): JsonResponse {
        /** @var User $user */
        $user = $request->user();
        $resolvedOrganization = $organizations->visibleTo($user)
            ->where('public_id', $organization)
            ->firstOrFail();
        $validated = $request->validate([
            'participant_ids' => ['required', 'array', 'min:1'],
            'participant_ids.*' => ['required', 'string', 'ulid', 'distinct'],
        ]);
        $participants = User::query()
            ->whereIn('public_id', $validated['participant_ids'])
            ->get();

        if ($participants->count() !== count($validated['participant_ids'])) {
            throw ValidationException::withMessages([
                'participant_ids' => ['One or more selected people are unavailable.'],
            ]);
        }

        $directMessage = $directMessages->create($resolvedOrganization, $user, $participants);
        $status = $directMessage->wasRecentlyCreated ? 201 : 200;
        $directMessage->load([
            'initiatedBy',
            'internalOwner',
            'completedBy',
            'continuationRequestedBy',
        ]);

        return (new DirectMessageResource($directMessage))->response()->setStatusCode($status);
    }
}
