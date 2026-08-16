import Echo, { type ConnectionStatus } from "laravel-echo";
import Pusher from "pusher-js";
import type { CommunicationReadState } from "../api/communication";

type BaseEventEnvelope = Record<string, unknown> & {
  event_id: string;
  version: 1;
};

type ConversationEventEnvelope = BaseEventEnvelope & {
  conversation_id: string;
};

export type ConversationMessageCreatedEvent = ConversationEventEnvelope & {
  type: "message-created";
  message_id: string;
  sequence: number;
  mentioned_user_ids: string[];
  attention_user_ids: string[];
};

export type ConversationReactionChangedEvent = ConversationEventEnvelope & {
  type: "reaction-changed";
  message_id: string;
  message_sequence: number;
};

export type ConversationMessageChangedEvent = ConversationEventEnvelope & {
  type: "message-changed";
  message_id: string;
  message_sequence: number;
  operation: "edited" | "deleted";
};

export type ConversationRealtimeEvent =
  | ConversationMessageCreatedEvent
  | ConversationMessageChangedEvent
  | ConversationReactionChangedEvent;

export type AttentionChangedEvent = BaseEventEnvelope & {
  type: "attention-changed";
  attention_id: string;
  operation: "created" | "viewed" | "resolved";
};

export type ConversationAccessChangedEvent = ConversationEventEnvelope & {
  type: "access-changed";
  operation: "granted" | "revoked";
};

export type MeetingAccessChangedEvent = BaseEventEnvelope & {
  type: "meeting-access-changed";
  meeting_id: string;
  operation: "granted" | "revoked";
};

export type MeetingStateChangedEvent = BaseEventEnvelope & {
  type: "meeting-state-changed";
  meeting_id: string;
  conversation_id: string | null;
  status: "scheduled" | "live" | "completed" | "cancelled";
};

export type MeetingOutcomeCreatedEvent = BaseEventEnvelope & {
  type: "meeting-outcome-created";
  meeting_id: string;
  outcome_id: string;
};

export type CommunicationRealtimeController = {
  syncConversations(conversationIds: string[]): void;
  stop(): void;
};

type RealtimeOptions = {
  userId: string;
  onConversationEvent(event: ConversationRealtimeEvent): void;
  onReadState(readState: CommunicationReadState): void;
  onAttentionChange(event: AttentionChangedEvent): void;
  onConversationAccessChange(event: ConversationAccessChangedEvent): void;
  onMeetingAccessChange(event: MeetingAccessChangedEvent): void;
  onMeetingStateChange(event: MeetingStateChangedEvent): void;
  onMeetingOutcomeChange(event: MeetingOutcomeCreatedEvent): void;
  onReconnect(): void;
  onStatusChange(status: ConnectionStatus): void;
};

function cookieValue(name: string): string | null {
  const prefix = `${name}=`;
  const cookie = document.cookie
    .split(";")
    .map((part) => part.trim())
    .find((part) => part.startsWith(prefix));

  return cookie ? decodeURIComponent(cookie.slice(prefix.length)) : null;
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === "object" && value !== null;
}

function isBaseEnvelope(value: unknown): value is BaseEventEnvelope {
  return isRecord(value)
    && typeof value.event_id === "string"
    && value.version === 1;
}

function isConversationEnvelope(value: unknown): value is ConversationEventEnvelope {
  return isBaseEnvelope(value)
    && typeof value.conversation_id === "string";
}

function asMessageCreated(value: unknown): ConversationMessageCreatedEvent | null {
  if (
    !isConversationEnvelope(value)
    || typeof value.message_id !== "string"
    || typeof value.sequence !== "number"
    || !Array.isArray(value.mentioned_user_ids)
    || !value.mentioned_user_ids.every((id) => typeof id === "string")
    || !Array.isArray(value.attention_user_ids)
    || !value.attention_user_ids.every((id) => typeof id === "string")
  ) {
    return null;
  }

  return { ...value, type: "message-created" } as ConversationMessageCreatedEvent;
}

function asReactionChanged(value: unknown): ConversationReactionChangedEvent | null {
  if (!isConversationEnvelope(value) || typeof value.message_id !== "string" || typeof value.message_sequence !== "number") {
    return null;
  }

  return { ...value, type: "reaction-changed" } as ConversationReactionChangedEvent;
}

function asMessageChanged(value: unknown): ConversationMessageChangedEvent | null {
  if (
    !isConversationEnvelope(value)
    || typeof value.message_id !== "string"
    || typeof value.message_sequence !== "number"
    || !["edited", "deleted"].includes(String(value.operation))
  ) {
    return null;
  }

  return { ...value, type: "message-changed" } as ConversationMessageChangedEvent;
}

function asReadState(value: unknown): (ConversationEventEnvelope & CommunicationReadState) | null {
  if (
    !isConversationEnvelope(value)
    || typeof value.last_read_sequence !== "number"
    || typeof value.latest_sequence !== "number"
    || typeof value.unread_count !== "number"
    || typeof value.mention_count !== "number"
  ) {
    return null;
  }

  return value as ConversationEventEnvelope & CommunicationReadState;
}

function asAttentionChanged(value: unknown): AttentionChangedEvent | null {
  if (
    !isBaseEnvelope(value)
    || typeof value.attention_id !== "string"
    || !["created", "viewed", "resolved"].includes(String(value.operation))
  ) {
    return null;
  }

  return { ...value, type: "attention-changed" } as AttentionChangedEvent;
}

function asConversationAccessChanged(value: unknown): ConversationAccessChangedEvent | null {
  if (
    !isConversationEnvelope(value)
    || !["granted", "revoked"].includes(String(value.operation))
  ) {
    return null;
  }

  return { ...value, type: "access-changed" } as ConversationAccessChangedEvent;
}

function asMeetingAccessChanged(value: unknown): MeetingAccessChangedEvent | null {
  if (
    !isBaseEnvelope(value)
    || typeof value.meeting_id !== "string"
    || !["granted", "revoked"].includes(String(value.operation))
  ) {
    return null;
  }

  return { ...value, type: "meeting-access-changed" } as MeetingAccessChangedEvent;
}

function asMeetingStateChanged(value: unknown): MeetingStateChangedEvent | null {
  if (
    !isBaseEnvelope(value)
    || typeof value.meeting_id !== "string"
    || !(typeof value.conversation_id === "string" || value.conversation_id === null)
    || !["scheduled", "live", "completed", "cancelled"].includes(String(value.status))
  ) {
    return null;
  }

  return { ...value, type: "meeting-state-changed" } as MeetingStateChangedEvent;
}

function asMeetingOutcomeCreated(value: unknown): MeetingOutcomeCreatedEvent | null {
  if (
    !isBaseEnvelope(value)
    || typeof value.meeting_id !== "string"
    || typeof value.outcome_id !== "string"
  ) {
    return null;
  }

  return { ...value, type: "meeting-outcome-created" } as MeetingOutcomeCreatedEvent;
}

export function startCommunicationRealtime(options: RealtimeOptions): CommunicationRealtimeController {
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
      },
    },
    withCredentials: true,
  });
  const subscribed = new Set<string>();
  const seenEventIds = new Set<string>();
  const seenEventOrder: string[] = [];
  let hasConnected = false;

  function acceptEvent(eventId: string): boolean {
    if (seenEventIds.has(eventId)) {
      return false;
    }

    seenEventIds.add(eventId);
    seenEventOrder.push(eventId);

    if (seenEventOrder.length > 1_000) {
      const oldest = seenEventOrder.shift();
      if (oldest) seenEventIds.delete(oldest);
    }

    return true;
  }

  function subscribeConversation(conversationId: string): void {
    if (subscribed.has(conversationId)) return;

    echo.private(`conversations.${conversationId}`)
      .listen(".conversation.message.created.v1", (payload: unknown) => {
        const event = asMessageCreated(payload);
        if (event && acceptEvent(event.event_id)) options.onConversationEvent(event);
      })
      .listen(".conversation.message.changed.v1", (payload: unknown) => {
        const event = asMessageChanged(payload);
        if (event && acceptEvent(event.event_id)) options.onConversationEvent(event);
      })
      .listen(".conversation.reaction.changed.v1", (payload: unknown) => {
        const event = asReactionChanged(payload);
        if (event && acceptEvent(event.event_id)) options.onConversationEvent(event);
      })
      .listen(".meeting.state.changed.v1", (payload: unknown) => {
        const event = asMeetingStateChanged(payload);
        if (event && acceptEvent(event.event_id)) options.onMeetingStateChange(event);
      });
    subscribed.add(conversationId);
  }

  echo.private(`users.${options.userId}`)
    .listen(".conversation.read-position.advanced.v1", (payload: unknown) => {
      const event = asReadState(payload);
      if (event && acceptEvent(event.event_id)) options.onReadState(event);
    })
    .listen(".attention.item.changed.v1", (payload: unknown) => {
      const event = asAttentionChanged(payload);
      if (event && acceptEvent(event.event_id)) options.onAttentionChange(event);
    })
    .listen(".conversation.access.changed.v1", (payload: unknown) => {
      const event = asConversationAccessChanged(payload);
      if (event && acceptEvent(event.event_id)) options.onConversationAccessChange(event);
    })
    .listen(".meeting.access.changed.v1", (payload: unknown) => {
      const event = asMeetingAccessChanged(payload);
      if (event && acceptEvent(event.event_id)) options.onMeetingAccessChange(event);
    })
    .listen(".meeting.state.changed.v1", (payload: unknown) => {
      const event = asMeetingStateChanged(payload);
      if (event && acceptEvent(event.event_id)) options.onMeetingStateChange(event);
    })
    .listen(".meeting.outcome.created.v1", (payload: unknown) => {
      const event = asMeetingOutcomeCreated(payload);
      if (event && acceptEvent(event.event_id)) options.onMeetingOutcomeChange(event);
    });

  const stopStatusListener = echo.connector.onConnectionChange((status) => {
    options.onStatusChange(status);

    if (status === "connected") {
      if (hasConnected) options.onReconnect();
      hasConnected = true;
    }
  });

  return {
    syncConversations(conversationIds) {
      const next = new Set(conversationIds);

      for (const conversationId of next) subscribeConversation(conversationId);

      for (const conversationId of subscribed) {
        if (!next.has(conversationId)) {
          echo.leave(`conversations.${conversationId}`);
          subscribed.delete(conversationId);
        }
      }
    },
    stop() {
      stopStatusListener();
      for (const conversationId of subscribed) echo.leave(`conversations.${conversationId}`);
      echo.leave(`users.${options.userId}`);
      echo.disconnect();
      subscribed.clear();
    },
  };
}
