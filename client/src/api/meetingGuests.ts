import {
  CommunicationRequestError,
  type CommunicationFieldErrors,
  type CommunicationMeeting,
  type MeetingMessage,
  type MeetingMessageCollectionResponse,
  type MeetingMessageReaction,
  type MeetingMediaCredential,
  type MeetingOutcome,
  type MeetingOutcomeCreateInput,
  type MeetingRoomReactionKind,
} from "./communication";

export type GuestMeetingLobby = {
  id: string;
  title: string;
  starts_at: string;
  duration_minutes: number;
  status: "scheduled" | "live";
  organizer: { name: string };
  organization: { name: string };
};

export type GuestMeetingAdmission = {
  session_token: string;
  participant: { id: string; name: string };
  meeting: CommunicationMeeting;
};

type ResourceResponse<T> = { data: T };
type CollectionResponse<T> = { data: T[] };
type ErrorResponse = { message?: string; errors?: CommunicationFieldErrors };

async function requestGuestJson<T>(
  path: string,
  options: {
    method?: "GET" | "POST" | "PUT" | "DELETE";
    data?: Record<string, unknown>;
    sessionToken?: string;
    signal?: AbortSignal;
  } = {},
): Promise<T> {
  const method = options.method ?? "GET";
  const response = await fetch(path, {
    method,
    credentials: "same-origin",
    headers: {
      Accept: "application/json",
      ...(method !== "GET" ? { "Content-Type": "application/json" } : {}),
      ...(options.sessionToken ? { Authorization: `Bearer ${options.sessionToken}` } : {}),
    },
    body: options.data ? JSON.stringify(options.data) : undefined,
    signal: options.signal,
  });

  if (!response.ok) {
    let payload: ErrorResponse = {};
    try {
      payload = await response.json() as ErrorResponse;
    } catch {
      // A neutral fallback keeps proxy errors from exposing implementation detail.
    }
    const fallback = response.status === 404
      ? "This meeting link is unavailable or has expired."
      : response.status >= 500
        ? "Katra could not reach this meeting. Please try again."
        : "The request could not be completed.";
    throw new CommunicationRequestError(
      response.status === 404 || response.status >= 500 ? fallback : (payload.message ?? fallback),
      response.status,
      payload.errors ?? {},
    );
  }

  return response.status === 204 ? undefined as T : await response.json() as T;
}

export async function inspectGuestMeeting(
  meetingId: string,
  linkToken: string,
  signal?: AbortSignal,
): Promise<GuestMeetingLobby> {
  return (await requestGuestJson<ResourceResponse<GuestMeetingLobby>>(
    `/api/v1/meeting-guests/${encodeURIComponent(meetingId)}/inspect`,
    { method: "POST", data: { token: linkToken }, signal },
  )).data;
}

export async function admitGuestMeeting(
  meetingId: string,
  linkToken: string,
  displayName: string,
  idempotencyKey: string,
): Promise<GuestMeetingAdmission> {
  return (await requestGuestJson<ResourceResponse<GuestMeetingAdmission>>(
    `/api/v1/meeting-guests/${encodeURIComponent(meetingId)}/admit`,
    {
      method: "POST",
      data: { token: linkToken, display_name: displayName, idempotency_key: idempotencyKey },
    },
  )).data;
}

export async function inspectEmailMeetingInvitation(
  invitationId: string,
  linkToken: string,
  signal?: AbortSignal,
): Promise<GuestMeetingLobby> {
  return (await requestGuestJson<ResourceResponse<GuestMeetingLobby>>(
    `/api/v1/meeting-invitations/${encodeURIComponent(invitationId)}/inspect`,
    { method: "POST", data: { token: linkToken }, signal },
  )).data;
}

export async function admitEmailMeetingInvitation(
  invitationId: string,
  linkToken: string,
  displayName: string,
  idempotencyKey: string,
): Promise<GuestMeetingAdmission> {
  return (await requestGuestJson<ResourceResponse<GuestMeetingAdmission>>(
    `/api/v1/meeting-invitations/${encodeURIComponent(invitationId)}/admit`,
    {
      method: "POST",
      data: { token: linkToken, display_name: displayName, idempotency_key: idempotencyKey },
    },
  )).data;
}

export async function getGuestMeeting(sessionToken: string): Promise<CommunicationMeeting> {
  return (await requestGuestJson<ResourceResponse<CommunicationMeeting>>(
    "/api/v1/meeting-guest/session",
    { sessionToken },
  )).data;
}

export async function updateGuestMeetingRoom(
  sessionToken: string,
  command: "join" | "leave",
): Promise<CommunicationMeeting> {
  return (await requestGuestJson<ResourceResponse<CommunicationMeeting>>(
    `/api/v1/meeting-guest/${command}`,
    { method: "POST", data: {}, sessionToken },
  )).data;
}

export async function getGuestMeetingMediaCredential(sessionToken: string): Promise<MeetingMediaCredential> {
  return (await requestGuestJson<ResourceResponse<MeetingMediaCredential>>(
    "/api/v1/meeting-guest/media-credential",
    { method: "POST", data: {}, sessionToken },
  )).data;
}

export async function getGuestMeetingOutcomes(sessionToken: string): Promise<MeetingOutcome[]> {
  return (await requestGuestJson<CollectionResponse<MeetingOutcome>>(
    "/api/v1/meeting-guest/outcomes",
    { sessionToken },
  )).data;
}

export async function createGuestMeetingOutcome(
  sessionToken: string,
  input: Pick<MeetingOutcomeCreateInput, "kind" | "body">,
): Promise<MeetingOutcome> {
  return (await requestGuestJson<ResourceResponse<MeetingOutcome>>(
    "/api/v1/meeting-guest/outcomes",
    { method: "POST", data: input, sessionToken },
  )).data;
}

export async function getGuestMeetingMessages(sessionToken: string): Promise<MeetingMessageCollectionResponse> {
  return await requestGuestJson<MeetingMessageCollectionResponse>(
    "/api/v1/meeting-guest/messages?limit=100",
    { sessionToken },
  );
}

export async function createGuestMeetingMessage(sessionToken: string, body: string): Promise<MeetingMessage> {
  return (await requestGuestJson<ResourceResponse<MeetingMessage>>(
    "/api/v1/meeting-guest/messages",
    { method: "POST", data: { body, idempotency_key: crypto.randomUUID() }, sessionToken },
  )).data;
}

export async function setGuestMeetingMessageReaction(
  sessionToken: string,
  messageId: string,
  kind: MeetingMessageReaction["kind"],
  reacted: boolean,
): Promise<MeetingMessage> {
  return (await requestGuestJson<ResourceResponse<MeetingMessage>>(
    `/api/v1/meeting-guest/messages/${encodeURIComponent(messageId)}/reactions`,
    { method: reacted ? "PUT" : "DELETE", data: { kind }, sessionToken },
  )).data;
}

export async function sendGuestMeetingRoomReaction(
  sessionToken: string,
  kind: MeetingRoomReactionKind,
): Promise<void> {
  await requestGuestJson<ResourceResponse<{ accepted: true }>>(
    "/api/v1/meeting-guest/reactions",
    { method: "POST", data: { kind }, sessionToken },
  );
}
