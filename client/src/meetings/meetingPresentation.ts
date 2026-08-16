import type { MeetingRoomReactionKind } from "../api/communication";

export function formatMeetingElapsedTime(
  startedAt: string | null,
  now: number = Date.now(),
  fallbackStartedAt: number = now,
): string {
  const parsedStartedAt = startedAt ? Date.parse(startedAt) : Number.NaN;
  const start = Number.isFinite(parsedStartedAt) ? parsedStartedAt : fallbackStartedAt;
  const elapsedSeconds = Math.max(0, Math.floor((now - start) / 1_000));
  const hours = Math.floor(elapsedSeconds / 3_600);
  const minutes = Math.floor((elapsedSeconds % 3_600) / 60);
  const seconds = elapsedSeconds % 60;
  const minuteLabel = minutes.toString().padStart(2, "0");
  const secondLabel = seconds.toString().padStart(2, "0");

  return hours > 0
    ? `${hours.toString().padStart(2, "0")}:${minuteLabel}:${secondLabel}`
    : `${minuteLabel}:${secondLabel}`;
}

export function updateRaisedParticipantIds(
  current: ReadonlySet<string>,
  actorId: string,
  kind: MeetingRoomReactionKind,
): ReadonlySet<string> {
  if (kind !== "raise-hand" && kind !== "lower-hand") return current;

  const updated = new Set(current);
  if (kind === "raise-hand") updated.add(actorId);
  else updated.delete(actorId);
  return updated;
}

export function retainPresentRaisedParticipantIds(
  current: ReadonlySet<string>,
  presentParticipantIds: ReadonlySet<string>,
): ReadonlySet<string> {
  return new Set([...current].filter((participantId) => presentParticipantIds.has(participantId)));
}
