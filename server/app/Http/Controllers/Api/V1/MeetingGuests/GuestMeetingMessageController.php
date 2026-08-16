<?php

namespace App\Http\Controllers\Api\V1\MeetingGuests;

use App\Http\Controllers\Controller;
use App\Http\Resources\MeetingMessageResource;
use App\Meetings\MeetingChatService;
use App\Meetings\MeetingGuestAccess;
use App\Models\MeetingGuestSession;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response;

final class GuestMeetingMessageController extends Controller
{
    public function index(Request $request, MeetingGuestAccess $access, MeetingChatService $chat): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'before_sequence' => ['sometimes', 'integer', 'min:1', 'prohibited_with:after_sequence'],
            'after_sequence' => ['sometimes', 'integer', 'min:0', 'prohibited_with:before_sequence'],
        ]);
        $session = $this->session($request);
        $page = $chat->page(
            $access->meeting($session),
            $validated['limit'] ?? 50,
            $validated['before_sequence'] ?? null,
            $validated['after_sequence'] ?? null,
        );

        return MeetingMessageResource::collection($page['messages'])->additional(['meta' => $page['meta']]);
    }

    public function store(Request $request, MeetingGuestAccess $access, MeetingChatService $chat): Response
    {
        $validated = $request->validate([
            'body' => ['required', 'string', 'max:4000'],
            'idempotency_key' => ['required', 'string', 'max:64'],
        ]);
        $session = $this->session($request);
        $message = $chat->sendAsGuest(
            $access->meeting($session),
            $session->participant,
            $validated['body'],
            $validated['idempotency_key'],
        );

        return (new MeetingMessageResource($message))->response()->setStatusCode(
            $message->wasRecentlyCreated ? 201 : 200,
        );
    }

    private function session(Request $request): MeetingGuestSession
    {
        /** @var MeetingGuestSession $session */
        $session = $request->user('meeting-guest');

        return $session;
    }
}
