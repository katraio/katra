<?php

namespace App\Meetings;

use App\Models\Meeting;
use Illuminate\Support\Str;

final class MeetingCalendar
{
    public function render(Meeting $meeting): string
    {
        $meeting->loadMissing(['organization', 'agendaItems.owner']);
        $description = collect([
            filled($meeting->desired_outcome)
                ? "Desired outcome:\n{$meeting->desired_outcome}"
                : null,
            $meeting->agendaItems->isNotEmpty()
                ? "Agenda:\n".$meeting->agendaItems
                    ->map(function ($item): string {
                        $owner = $item->owner === null ? '' : " - {$item->owner->name}";

                        return "{$item->position}. {$item->title} ({$item->duration_minutes} min){$owner}";
                    })
                    ->implode("\n")
                : null,
        ])->filter()->implode("\n\n");
        $endsAt = $meeting->starts_at->addMinutes($meeting->duration_minutes);
        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Katra//Meeting Calendar//EN',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'BEGIN:VEVENT',
            "UID:{$meeting->public_id}@katra",
            'DTSTAMP:'.$this->utc($meeting->created_at),
            'DTSTART:'.$this->utc($meeting->starts_at),
            'DTEND:'.$this->utc($endsAt),
            'SUMMARY:'.$this->escape($meeting->title),
            'LOCATION:'.$this->escape('Katra room'),
            'DESCRIPTION:'.$this->escape($description),
            'STATUS:CONFIRMED',
            'END:VEVENT',
            'END:VCALENDAR',
        ];

        return collect($lines)
            ->map(fn (string $line): string => $this->fold($line))
            ->implode("\r\n")."\r\n";
    }

    public function filename(Meeting $meeting): string
    {
        $slug = Str::slug($meeting->title);

        return ($slug === '' ? 'katra-meeting' : $slug).'.ics';
    }

    private function utc($date): string
    {
        return $date->toImmutable()->utc()->format('Ymd\THis\Z');
    }

    private function escape(string $value): string
    {
        return str_replace(
            ['\\', "\r\n", "\n", "\r", ';', ','],
            ['\\\\', '\\n', '\\n', '\\n', '\\;', '\\,'],
            $value,
        );
    }

    private function fold(string $line): string
    {
        $segments = [];
        $remaining = $line;
        $limit = 75;

        while (strlen($remaining) > $limit) {
            $segment = mb_strcut($remaining, 0, $limit, 'UTF-8');
            $segments[] = $segment;
            $remaining = substr($remaining, strlen($segment));
            $limit = 74;
        }

        $segments[] = $remaining;

        return implode("\r\n ", $segments);
    }
}
