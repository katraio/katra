<?php

namespace App\Http\Controllers\Api\V1\Meetings;

use App\Http\Controllers\Controller;
use App\Meetings\MeetingAccess;
use App\Meetings\MeetingCalendar;
use App\Models\User;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class MeetingCalendarController extends Controller
{
    public function __invoke(
        Request $request,
        string $meeting,
        MeetingAccess $access,
        MeetingCalendar $calendar,
    ): Response {
        /** @var User $user */
        $user = $request->user();
        $resolved = $access->findVisible($user, $meeting);

        return response($calendar->render($resolved), 200, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'Content-Disposition' => sprintf('attachment; filename="%s"', $calendar->filename($resolved)),
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
