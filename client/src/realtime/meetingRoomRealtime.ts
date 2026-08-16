import Echo from "laravel-echo";
import Pusher from "pusher-js";

export type MeetingPresence = {
  id: string;
  name: string;
};

export type MeetingStateChangedEvent = {
  event_id: string;
  version: 1;
  meeting_id: string;
  status: "scheduled" | "live" | "completed" | "cancelled";
};

export type MeetingParticipantAccessChangedEvent = {
  event_id: string;
  version: 1;
  meeting_id: string;
  participant_id: string;
  operation: "removed" | "restored";
};

export type MeetingOutcomeCreatedEvent = {
  event_id: string;
  version: 1;
  meeting_id: string;
  outcome_id: string;
};

export type MeetingMessageCreatedEvent = {
  event_id: string;
  version: 1;
  meeting_id: string;
  message_id: string;
  sequence: number;
};

export type MeetingMessageReactionChangedEvent = {
  event_id: string;
  version: 1;
  meeting_id: string;
  message_id: string;
  message_sequence: number;
};

export type MeetingRoomReactionEvent = {
  event_id: string;
  version: 1;
  meeting_id: string;
  actor_user_id: string;
  kind: "approve" | "support" | "celebrate" | "raise-hand" | "lower-hand";
};

export type MeetingRoomRealtimeController = {
  stop(): void;
};

type MeetingRoomRealtimeOptions = {
  meetingId: string;
  authToken?: string;
  onPresence(users: MeetingPresence[]): void;
  onStateChange(event: MeetingStateChangedEvent): void;
  onParticipantAccessChange(event: MeetingParticipantAccessChangedEvent): void;
  onOutcomeChange(event: MeetingOutcomeCreatedEvent): void;
  onMessageChange(event: MeetingMessageCreatedEvent): void;
  onMessageReactionChange(event: MeetingMessageReactionChangedEvent): void;
  onRoomReaction(event: MeetingRoomReactionEvent): void;
  onError(): void;
};

function cookieValue(name: string): string | null {
  const prefix = `${name}=`;
  const cookie = document.cookie
    .split(";")
    .map((part) => part.trim())
    .find((part) => part.startsWith(prefix));

  return cookie ? decodeURIComponent(cookie.slice(prefix.length)) : null;
}

function isPresence(value: unknown): value is MeetingPresence {
  return typeof value === "object"
    && value !== null
    && "id" in value
    && typeof value.id === "string"
    && "name" in value
    && typeof value.name === "string";
}

function asStateChanged(value: unknown): MeetingStateChangedEvent | null {
  if (
    typeof value !== "object"
    || value === null
    || !("event_id" in value)
    || typeof value.event_id !== "string"
    || !("version" in value)
    || value.version !== 1
    || !("meeting_id" in value)
    || typeof value.meeting_id !== "string"
    || !("status" in value)
    || !["scheduled", "live", "completed", "cancelled"].includes(String(value.status))
  ) {
    return null;
  }

  return value as MeetingStateChangedEvent;
}

function asParticipantAccessChanged(value: unknown): MeetingParticipantAccessChangedEvent | null {
  if (
    typeof value !== "object" || value === null
    || !("event_id" in value) || typeof value.event_id !== "string"
    || !("version" in value) || value.version !== 1
    || !("meeting_id" in value) || typeof value.meeting_id !== "string"
    || !("participant_id" in value) || typeof value.participant_id !== "string"
    || !("operation" in value) || !["removed", "restored"].includes(String(value.operation))
  ) return null;

  return value as MeetingParticipantAccessChangedEvent;
}

function asOutcomeCreated(value: unknown): MeetingOutcomeCreatedEvent | null {
  if (
    typeof value !== "object"
    || value === null
    || !("event_id" in value) || typeof value.event_id !== "string"
    || !("version" in value) || value.version !== 1
    || !("meeting_id" in value) || typeof value.meeting_id !== "string"
    || !("outcome_id" in value) || typeof value.outcome_id !== "string"
  ) {
    return null;
  }

  return value as MeetingOutcomeCreatedEvent;
}

function asMessageCreated(value: unknown): MeetingMessageCreatedEvent | null {
  if (
    typeof value !== "object" || value === null
    || !("event_id" in value) || typeof value.event_id !== "string"
    || !("version" in value) || value.version !== 1
    || !("meeting_id" in value) || typeof value.meeting_id !== "string"
    || !("message_id" in value) || typeof value.message_id !== "string"
    || !("sequence" in value) || typeof value.sequence !== "number"
  ) return null;

  return value as MeetingMessageCreatedEvent;
}

function asMessageReactionChanged(value: unknown): MeetingMessageReactionChangedEvent | null {
  if (
    typeof value !== "object" || value === null
    || !("event_id" in value) || typeof value.event_id !== "string"
    || !("version" in value) || value.version !== 1
    || !("meeting_id" in value) || typeof value.meeting_id !== "string"
    || !("message_id" in value) || typeof value.message_id !== "string"
    || !("message_sequence" in value) || typeof value.message_sequence !== "number"
  ) return null;

  return value as MeetingMessageReactionChangedEvent;
}

function asRoomReaction(value: unknown): MeetingRoomReactionEvent | null {
  if (
    typeof value !== "object" || value === null
    || !("event_id" in value) || typeof value.event_id !== "string"
    || !("version" in value) || value.version !== 1
    || !("meeting_id" in value) || typeof value.meeting_id !== "string"
    || !("actor_user_id" in value) || typeof value.actor_user_id !== "string"
    || !("kind" in value) || !["approve", "support", "celebrate", "raise-hand", "lower-hand"].includes(String(value.kind))
  ) return null;

  return value as MeetingRoomReactionEvent;
}

export function startMeetingRoomRealtime(options: MeetingRoomRealtimeOptions): MeetingRoomRealtimeController {
  const scheme = import.meta.env.VITE_REVERB_SCHEME ?? "http";
  const port = Number(import.meta.env.VITE_REVERB_PORT ?? (scheme === "https" ? 443 : 8080));
  const token = cookieValue("XSRF-TOKEN");
  const echo = new Echo<"reverb">({
    broadcaster: "reverb",
    Pusher,
    key: import.meta.env.VITE_REVERB_APP_KEY ?? "katra-local-key",
    wsHost: import.meta.env.VITE_REVERB_HOST ?? window.location.hostname,
    wsPort: port,
    wssPort: port,
    forceTLS: scheme === "https",
    enabledTransports: ["ws", "wss"],
    authEndpoint: "/broadcasting/auth",
    auth: {
      headers: {
        Accept: "application/json",
        ...(token ? { "X-XSRF-TOKEN": token } : {}),
        ...(options.authToken ? { Authorization: `Bearer ${options.authToken}` } : {}),
      },
    },
    withCredentials: true,
  });
  const users = new Map<string, MeetingPresence>();

  function publish(): void {
    options.onPresence([...users.values()].sort((left, right) => left.name.localeCompare(right.name)));
  }

  echo.join(`meetings.${options.meetingId}`)
    .here((present: unknown[]) => {
      users.clear();
      present.filter(isPresence).forEach((user) => users.set(user.id, user));
      publish();
    })
    .joining((value: unknown) => {
      if (!isPresence(value)) return;
      users.set(value.id, value);
      publish();
    })
    .leaving((value: unknown) => {
      if (!isPresence(value)) return;
      users.delete(value.id);
      publish();
    })
    .error(() => options.onError())
    .listen(".meeting.state.changed.v1", (value: unknown) => {
      const event = asStateChanged(value);
      if (event) options.onStateChange(event);
    })
    .listen(".meeting.participant.access.changed.v1", (value: unknown) => {
      const event = asParticipantAccessChanged(value);
      if (event) options.onParticipantAccessChange(event);
    })
    .listen(".meeting.outcome.created.v1", (value: unknown) => {
      const event = asOutcomeCreated(value);
      if (event) options.onOutcomeChange(event);
    })
    .listen(".meeting.message.created.v1", (value: unknown) => {
      const event = asMessageCreated(value);
      if (event) options.onMessageChange(event);
    })
    .listen(".meeting.message.reaction.changed.v1", (value: unknown) => {
      const event = asMessageReactionChanged(value);
      if (event) options.onMessageReactionChange(event);
    })
    .listen(".meeting.room.reaction.sent.v1", (value: unknown) => {
      const event = asRoomReaction(value);
      if (event) options.onRoomReaction(event);
    });

  return {
    stop() {
      echo.leave(`meetings.${options.meetingId}`);
      echo.disconnect();
      users.clear();
    },
  };
}
