<?php

namespace App\Http\Controllers\Api\V1\Meetings;

use App\Http\Controllers\Controller;
use App\Http\Resources\MeetingMessageResource;
use App\Meetings\MeetingAccess;
use App\Meetings\MeetingChatService;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;

final class MeetingMessageIndexController extends Controller
{
    public function __invoke(
        Request $request,
        string $meeting,
        MeetingAccess $access,
        MeetingChatService $chat,
    ): AnonymousResourceCollection {
        $validated = $request->validate([
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'before_sequence' => ['sometimes', 'integer', 'min:1'],
            'after_sequence' => ['sometimes', 'integer', 'min:0'],
        ]);
        if (isset($validated['before_sequence'], $validated['after_sequence'])) {
            throw ValidationException::withMessages([
                'before_sequence' => ['Choose either before_sequence or after_sequence, not both.'],
                'after_sequence' => ['Choose either before_sequence or after_sequence, not both.'],
            ]);
        }

        /** @var User $user */
        $user = $request->user();
        $resolved = $access->findVisible($user, $meeting);
        $page = $chat->page(
            $resolved,
            $validated['limit'] ?? 50,
            $validated['before_sequence'] ?? null,
            $validated['after_sequence'] ?? null,
        );

        return MeetingMessageResource::collection($page['messages'])->additional(['meta' => $page['meta']]);
    }
}
