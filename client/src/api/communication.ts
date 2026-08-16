export type CommunicationFieldErrors = Record<string, string[]>;

export type CommunicationOrganization = {
  id: string;
  name: string;
  slug: string;
  kind: "operating" | "client";
};

export type MeetingCandidate = {
  id: string;
  first_name: string;
  last_name: string;
  name: string;
  kind: "internal" | "client";
};

export type CommunicationMeeting = {
  id: string;
  organization: {
    id: string | null;
    name: string;
  };
  conversation_id: string | null;
  title: string;
  starts_at: string;
  duration_minutes: number;
  desired_outcome: string | null;
  status: "scheduled" | "live" | "completed" | "cancelled";
  started_at: string | null;
  ended_at: string | null;
  cancelled_at: string | null;
  organizer: {
    id: string;
    name: string;
  };
  participants: Array<{
    id: string;
    participant_id: string;
    name: string;
    kind: "user" | "guest";
    admission_source: "copied-link" | "email-invitation" | null;
    can_remove: boolean;
    can_block_reentry: boolean;
    joined_at: string | null;
    left_at: string | null;
  }>;
  agenda_items: Array<{
    position: number;
    title: string;
    owner: { id: string; name: string } | null;
    duration_minutes: number;
  }>;
  outcomes: MeetingOutcome[];
  guest_link_url: string | null;
  guest_link_expires_at: string;
  guest_invitations: Array<{
    id: string;
    email: string;
    url: string | null;
    expires_at: string;
    status: "pending" | "queued" | "sent" | "failed" | "admitted" | "removed" | "revoked";
    send_count: number;
    last_queued_at: string | null;
    last_sent_at: string | null;
    last_failed_at: string | null;
    admitted_at: string | null;
    revoked_at: string | null;
  }>;
  created_at: string;
};

export type MeetingOutcome = {
  id: string;
  sequence: number;
  kind: "note" | "decision" | "action";
  body: string;
  author: { id: string; name: string };
  assignee: { id: string; name: string } | null;
  completed_at: string | null;
  created_at: string;
};

export type MeetingOutcomeCreateInput = {
  kind: MeetingOutcome["kind"];
  body: string;
  assignee_user_id?: string | null;
};

export type MeetingMessageReaction = {
  kind: "approve" | "support";
  count: number;
  reacted_by_current_user: boolean;
};

export type MeetingMessage = {
  id: string;
  sequence: number;
  body: string;
  author: { id: string; name: string };
  reactions: MeetingMessageReaction[];
  created_at: string;
};

export type MeetingRoomReactionKind = "approve" | "support" | "celebrate" | "raise-hand" | "lower-hand";

export type MeetingMediaCredential = {
  url: string;
  token: string;
  expires_at: string;
  room_generation: number;
  participant_identity: string;
};

export type ConversationLiveMeeting = {
  id: string;
  title: string;
  status: "live";
  started_at: string | null;
  organizer: {
    id: string;
    name: string;
  };
};

export type MeetingCreateInput = {
  title: string;
  starts_at: string;
  duration_minutes: number;
  desired_outcome?: string;
  participant_ids: string[];
  guest_emails: string[];
  agenda_items: Array<{
    title: string;
    owner_user_id: string | null;
    duration_minutes: number;
  }>;
};

export type MeetingInstantCreateInput = {
  title: string;
  participant_ids: string[];
};

export class CommunicationRequestError extends Error {
  readonly status: number;
  readonly fields: CommunicationFieldErrors;

  constructor(message: string, status: number, fields: CommunicationFieldErrors = {}) {
    super(message);
    this.name = "CommunicationRequestError";
    this.status = status;
    this.fields = fields;
  }
}

export type ChannelMembership = {
  role: "owner" | "member" | null;
  last_read_sequence: number;
  joined_at: string;
};

export type CommunicationChannel = {
  id: string;
  organization_id: string;
  name: string;
  slug: string;
  visibility: "public" | "private" | "client-team";
  archived_at: string | null;
  is_favorite: boolean;
  latest_sequence: number;
  last_read_sequence: number | null;
  unread_count: number | null;
  mention_count: number;
  membership: ChannelMembership | null;
  permissions: {
    can_manage_members: boolean;
  };
  live_meeting: ConversationLiveMeeting | null;
};

export type ChannelMember = MentionableUser & {
  role: "owner" | "member";
  joined_at: string;
  is_current_user: boolean;
};

export type DirectMessageParticipant = {
  id: string;
  first_name: string;
  last_name: string;
  name: string;
  email: string;
};

export type DirectMessageCandidate = MentionableUser & {
  kind: "internal" | "client";
};

export type CommunicationDirectMessage = {
  id: string;
  organization_id: string;
  state: "open" | "completed" | "continuation-requested";
  is_favorite: boolean;
  latest_sequence: number;
  last_read_sequence: number | null;
  unread_count: number | null;
  participants: DirectMessageParticipant[];
  initiated_by_id: string;
  internal_owner_id: string | null;
  completed_at: string | null;
  completed_by_id: string | null;
  continuation_requested_at: string | null;
  continuation_requested_by_id: string | null;
  created_at: string;
  live_meeting: ConversationLiveMeeting | null;
};

export type ConversationReaction = {
  kind: "approve" | "support" | "done";
  count: number;
  reacted_by_current_user: boolean;
};

export type ConversationMessage = {
  id: string;
  sequence: number;
  body: string | null;
  author: {
    id: string;
    first_name: string;
    last_name: string;
    name: string;
  };
  parent_message_id: string | null;
  mention_user_ids: string[];
  mentions: MentionableUser[];
  attention_user_ids: string[];
  attention_targets: MentionableUser[];
  reactions: ConversationReaction[];
  edited_at: string | null;
  deleted_at: string | null;
  created_at: string;
};

export type CommunicationReadState = {
  conversation_id: string;
  last_read_sequence: number;
  latest_sequence: number;
  unread_count: number;
  mention_count: number;
};

export type ConversationFocusRequest = {
  conversationId: string;
  messageId: string;
  threadRootMessageId: string | null;
  nonce: number;
};

export type MeetingFocusRequest = {
  meetingId: string;
  nonce: number;
};

export type CommunicationSearchResult = {
  type: "message";
  message_id: string;
  conversation_id: string;
  conversation_type: "channel" | "direct-message";
  conversation_label: string;
  body: string;
  author: {
    id: string;
    name: string;
  };
  sequence: number;
  thread_root_message_id: string | null;
  created_at: string;
};

export type MentionableUser = {
  id: string;
  first_name: string;
  last_name: string;
  name: string;
};

export type CommunicationAttentionItem = {
  id: string;
  kind: "message-attention-request" | "direct-message-continuation" | "meeting-action";
  priority: "normal" | "high";
  state: "open" | "resolved";
  title: string;
  reason: string;
  context: string;
  organization: {
    id: string;
    name: string;
  };
  actor: {
    id: string;
    name: string;
  };
  destination: {
    type: "channel" | "direct-message" | "meeting";
    conversation_id: string | null;
    meeting_id: string | null;
    message_id: string | null;
    thread_root_message_id: string | null;
    message_sequence: number | null;
  };
  viewed_at: string | null;
  resolved_at: string | null;
  created_at: string;
};

export type MessagePageMeta = {
  conversation_id: string;
  conversation_type: "channel" | "direct-message";
  latest_sequence: number;
  pagination: {
    mode: "latest" | "before" | "after";
    limit: number;
    oldest_sequence: number | null;
    newest_sequence: number | null;
    has_more: boolean;
  };
};

type CollectionResponse<T> = {
  data: T[];
};

type ResourceResponse<T> = {
  data: T;
};

type MessageCollectionResponse = CollectionResponse<ConversationMessage> & {
  meta: MessagePageMeta;
};

export type MeetingMessageCollectionResponse = CollectionResponse<MeetingMessage> & {
  meta: {
    meeting_id: string;
    latest_sequence: number;
    pagination: MessagePageMeta["pagination"];
  };
};

type ErrorResponse = {
  message?: string;
  errors?: CommunicationFieldErrors;
};

function cookieValue(name: string): string | null {
  const prefix = `${name}=`;
  const cookie = document.cookie
    .split(";")
    .map((part) => part.trim())
    .find((part) => part.startsWith(prefix));

  return cookie ? decodeURIComponent(cookie.slice(prefix.length)) : null;
}

async function errorFromResponse(response: Response): Promise<CommunicationRequestError> {
  let payload: ErrorResponse = {};

  try {
    payload = await response.json() as ErrorResponse;
  } catch {
    // Use the safe fallback when a proxy or Server returns non-JSON.
  }

  const fallback = response.status >= 500
    ? "Katra Server could not complete the request. Please try again."
    : response.status === 404
      ? "This conversation is unavailable."
      : "The request could not be completed.";

  return new CommunicationRequestError(
    response.status >= 500 ? fallback : (payload.message ?? fallback),
    response.status,
    payload.errors ?? {},
  );
}

async function initializeCsrf(): Promise<string> {
  const response = await fetch("/sanctum/csrf-cookie", {
    credentials: "same-origin",
    headers: { Accept: "application/json" },
  });

  if (!response.ok) {
    throw await errorFromResponse(response);
  }

  const token = cookieValue("XSRF-TOKEN");

  if (!token) {
    throw new CommunicationRequestError("Katra Server did not establish a secure form session.", 419);
  }

  return token;
}

async function requestJson<T>(
  path: string,
  options: {
    method?: "GET" | "POST" | "PUT" | "PATCH" | "DELETE";
    data?: Record<string, unknown>;
    signal?: AbortSignal;
  } = {},
): Promise<T> {
  const method = options.method ?? "GET";
  const headers: Record<string, string> = { Accept: "application/json" };

  if (method !== "GET") {
    headers["Content-Type"] = "application/json";
    headers["X-XSRF-TOKEN"] = await initializeCsrf();
  }

  const response = await fetch(path, {
    method,
    credentials: "same-origin",
    headers,
    body: options.data ? JSON.stringify(options.data) : undefined,
    signal: options.signal,
  });

  if (!response.ok) {
    throw await errorFromResponse(response);
  }

  if (response.status === 204) {
    return undefined as T;
  }

  return await response.json() as T;
}

export async function getChannels(signal?: AbortSignal): Promise<CommunicationChannel[]> {
  return (await requestJson<CollectionResponse<CommunicationChannel>>("/api/v1/channels", { signal })).data;
}

export async function getChannel(channelId: string, signal?: AbortSignal): Promise<CommunicationChannel> {
  return (await requestJson<ResourceResponse<CommunicationChannel>>(
    `/api/v1/channels/${encodeURIComponent(channelId)}`,
    { signal },
  )).data;
}

export async function getOrganizations(signal?: AbortSignal): Promise<CommunicationOrganization[]> {
  return (await requestJson<CollectionResponse<CommunicationOrganization>>("/api/v1/organizations", { signal })).data;
}

export async function createChannel(
  organizationId: string,
  input: { name: string; visibility: "public" | "private" },
): Promise<CommunicationChannel> {
  const response = await requestJson<ResourceResponse<CommunicationChannel>>(
    `/api/v1/organizations/${encodeURIComponent(organizationId)}/channels`,
    {
      method: "POST",
      data: input,
    },
  );

  return response.data;
}

export async function getDirectMessages(signal?: AbortSignal): Promise<CommunicationDirectMessage[]> {
  return (await requestJson<CollectionResponse<CommunicationDirectMessage>>("/api/v1/direct-messages", { signal })).data;
}

export async function getDirectMessageCandidates(
  organizationId: string,
  query = "",
  signal?: AbortSignal,
): Promise<DirectMessageCandidate[]> {
  const parameters = new URLSearchParams({ query, limit: "50" });

  return (await requestJson<CollectionResponse<DirectMessageCandidate>>(
    `/api/v1/organizations/${encodeURIComponent(organizationId)}/direct-message-candidates?${parameters}`,
    { signal },
  )).data;
}

export async function createDirectMessage(
  organizationId: string,
  participantIds: string[],
): Promise<CommunicationDirectMessage> {
  const response = await requestJson<ResourceResponse<CommunicationDirectMessage>>(
    `/api/v1/organizations/${encodeURIComponent(organizationId)}/direct-messages`,
    {
      method: "POST",
      data: { participant_ids: participantIds },
    },
  );

  return response.data;
}

export async function getMeetings(signal?: AbortSignal): Promise<CommunicationMeeting[]> {
  return (await requestJson<CollectionResponse<CommunicationMeeting>>("/api/v1/meetings", { signal })).data;
}

export async function getMeeting(meetingId: string): Promise<CommunicationMeeting> {
  const response = await requestJson<ResourceResponse<CommunicationMeeting>>(
    `/api/v1/meetings/${encodeURIComponent(meetingId)}`,
  );

  return response.data;
}

export async function getMeetingCandidates(
  organizationId: string,
  query = "",
  signal?: AbortSignal,
): Promise<MeetingCandidate[]> {
  const parameters = new URLSearchParams({ query, limit: "50" });

  return (await requestJson<CollectionResponse<MeetingCandidate>>(
    `/api/v1/organizations/${encodeURIComponent(organizationId)}/meeting-candidates?${parameters}`,
    { signal },
  )).data;
}

export async function createMeeting(
  organizationId: string,
  input: MeetingCreateInput,
): Promise<CommunicationMeeting> {
  const response = await requestJson<ResourceResponse<CommunicationMeeting>>(
    `/api/v1/organizations/${encodeURIComponent(organizationId)}/meetings`,
    {
      method: "POST",
      data: input,
    },
  );

  return response.data;
}

export async function createInstantMeeting(
  organizationId: string,
  input: MeetingInstantCreateInput,
): Promise<CommunicationMeeting> {
  const response = await requestJson<ResourceResponse<CommunicationMeeting>>(
    `/api/v1/organizations/${encodeURIComponent(organizationId)}/meetings/instant`,
    {
      method: "POST",
      data: input,
    },
  );

  return response.data;
}

export async function startOrJoinConversationMeeting(
  conversationId: string,
  title: string,
): Promise<CommunicationMeeting> {
  const response = await requestJson<ResourceResponse<CommunicationMeeting>>(
    `/api/v1/conversations/${encodeURIComponent(conversationId)}/meeting`,
    {
      method: "POST",
      data: { title },
    },
  );

  return response.data;
}

export async function addMeetingParticipants(
  meetingId: string,
  participantIds: string[],
): Promise<CommunicationMeeting> {
  const response = await requestJson<ResourceResponse<CommunicationMeeting>>(
    `/api/v1/meetings/${encodeURIComponent(meetingId)}/participants`,
    {
      method: "POST",
      data: { participant_ids: participantIds },
    },
  );

  return response.data;
}

export async function removeMeetingParticipant(
  meetingId: string,
  participantId: string,
  blockReentry = false,
): Promise<CommunicationMeeting> {
  const response = await requestJson<ResourceResponse<CommunicationMeeting>>(
    `/api/v1/meetings/${encodeURIComponent(meetingId)}/participants/${encodeURIComponent(participantId)}/remove`,
    {
      method: "POST",
      data: { block_reentry: blockReentry },
    },
  );

  return response.data;
}

export async function addMeetingGuestInvitations(
  meetingId: string,
  guestEmails: string[],
): Promise<CommunicationMeeting> {
  const response = await requestJson<ResourceResponse<CommunicationMeeting>>(
    `/api/v1/meetings/${encodeURIComponent(meetingId)}/guest-invitations`,
    { method: "POST", data: { guest_emails: guestEmails } },
  );

  return response.data;
}

export async function updateMeetingGuestInvitation(
  meetingId: string,
  invitationId: string,
  command: "resend" | "revoke",
): Promise<CommunicationMeeting> {
  const suffix = command === "resend" ? "/resend" : "";
  const response = await requestJson<ResourceResponse<CommunicationMeeting>>(
    `/api/v1/meetings/${encodeURIComponent(meetingId)}/guest-invitations/${encodeURIComponent(invitationId)}${suffix}`,
    { method: command === "resend" ? "POST" : "DELETE", data: {} },
  );

  return response.data;
}

export type MeetingRoomCommand = "start" | "join" | "leave" | "end" | "cancel";

export async function updateMeetingRoom(
  meetingId: string,
  command: MeetingRoomCommand,
): Promise<CommunicationMeeting> {
  const response = await requestJson<ResourceResponse<CommunicationMeeting>>(
    `/api/v1/meetings/${encodeURIComponent(meetingId)}/${command}`,
    { method: "POST", data: {} },
  );

  return response.data;
}

export async function getMeetingMediaCredential(meetingId: string): Promise<MeetingMediaCredential> {
  return (await requestJson<ResourceResponse<MeetingMediaCredential>>(
    `/api/v1/meetings/${encodeURIComponent(meetingId)}/media-credential`,
    { method: "POST", data: {} },
  )).data;
}

export async function updateMeetingGuestLink(
  meetingId: string,
  command: "revoke" | "regenerate",
): Promise<CommunicationMeeting> {
  const response = await requestJson<ResourceResponse<CommunicationMeeting>>(
    `/api/v1/meetings/${encodeURIComponent(meetingId)}/guest-link/${command}`,
    { method: "POST", data: {} },
  );

  return response.data;
}

export async function getMeetingOutcomes(meetingId: string): Promise<MeetingOutcome[]> {
  return (await requestJson<CollectionResponse<MeetingOutcome>>(
    `/api/v1/meetings/${encodeURIComponent(meetingId)}/outcomes`,
  )).data;
}

export async function createMeetingOutcome(
  meetingId: string,
  input: MeetingOutcomeCreateInput,
): Promise<MeetingOutcome> {
  const response = await requestJson<ResourceResponse<MeetingOutcome>>(
    `/api/v1/meetings/${encodeURIComponent(meetingId)}/outcomes`,
    { method: "POST", data: input },
  );

  return response.data;
}

export async function getMeetingMessages(
  meetingId: string,
  options: { beforeSequence?: number; afterSequence?: number; limit?: number; signal?: AbortSignal } = {},
): Promise<MeetingMessageCollectionResponse> {
  const query = new URLSearchParams({ limit: String(options.limit ?? 100) });
  if (options.beforeSequence !== undefined) query.set("before_sequence", String(options.beforeSequence));
  if (options.afterSequence !== undefined) query.set("after_sequence", String(options.afterSequence));

  return await requestJson<MeetingMessageCollectionResponse>(
    `/api/v1/meetings/${encodeURIComponent(meetingId)}/messages?${query}`,
    { signal: options.signal },
  );
}

export async function createMeetingMessage(meetingId: string, body: string): Promise<MeetingMessage> {
  const response = await requestJson<ResourceResponse<MeetingMessage>>(
    `/api/v1/meetings/${encodeURIComponent(meetingId)}/messages`,
    {
      method: "POST",
      data: { body, idempotency_key: crypto.randomUUID() },
    },
  );

  return response.data;
}

export async function setMeetingMessageReaction(
  meetingId: string,
  messageId: string,
  kind: MeetingMessageReaction["kind"],
  reacted: boolean,
): Promise<MeetingMessage> {
  const response = await requestJson<ResourceResponse<MeetingMessage>>(
    `/api/v1/meetings/${encodeURIComponent(meetingId)}/messages/${encodeURIComponent(messageId)}/reactions`,
    { method: reacted ? "PUT" : "DELETE", data: { kind } },
  );

  return response.data;
}

export async function sendMeetingRoomReaction(
  meetingId: string,
  kind: MeetingRoomReactionKind,
): Promise<void> {
  await requestJson<ResourceResponse<{ accepted: true }>>(
    `/api/v1/meetings/${encodeURIComponent(meetingId)}/reactions`,
    { method: "POST", data: { kind } },
  );
}

export async function getMeetingCalendar(meetingId: string): Promise<Blob> {
  const response = await fetch(
    `/api/v1/meetings/${encodeURIComponent(meetingId)}/calendar.ics`,
    {
      credentials: "same-origin",
      headers: { Accept: "text/calendar" },
    },
  );

  if (!response.ok) {
    throw await errorFromResponse(response);
  }

  return response.blob();
}

export async function getAttention(signal?: AbortSignal): Promise<CommunicationAttentionItem[]> {
  return (await requestJson<CollectionResponse<CommunicationAttentionItem>>("/api/v1/attention", { signal })).data;
}

export async function searchCommunications(
  query: string,
  options: { currentConversationId?: string; limit?: number; signal?: AbortSignal } = {},
): Promise<CommunicationSearchResult[]> {
  const parameters = new URLSearchParams({
    q: query,
    limit: String(options.limit ?? 20),
  });

  if (options.currentConversationId) {
    parameters.set("current_conversation_id", options.currentConversationId);
  }

  return (await requestJson<CollectionResponse<CommunicationSearchResult>>(
    `/api/v1/search/communications?${parameters}`,
    { signal: options.signal },
  )).data;
}

export async function markAttentionViewed(attentionId: string): Promise<CommunicationAttentionItem> {
  const response = await requestJson<ResourceResponse<CommunicationAttentionItem>>(
    `/api/v1/attention/${encodeURIComponent(attentionId)}/viewed`,
    { method: "PUT" },
  );

  return response.data;
}

export async function resolveAttention(attentionId: string): Promise<CommunicationAttentionItem> {
  const response = await requestJson<ResourceResponse<CommunicationAttentionItem>>(
    `/api/v1/attention/${encodeURIComponent(attentionId)}/resolve`,
    { method: "POST" },
  );

  return response.data;
}

export async function getConversationMessages(
  conversationId: string,
  options: { beforeSequence?: number; afterSequence?: number; limit?: number; signal?: AbortSignal } = {},
): Promise<MessageCollectionResponse> {
  const query = new URLSearchParams({ limit: String(options.limit ?? 100) });

  if (options.beforeSequence !== undefined) {
    query.set("before_sequence", String(options.beforeSequence));
  }

  if (options.afterSequence !== undefined) {
    query.set("after_sequence", String(options.afterSequence));
  }

  return await requestJson<MessageCollectionResponse>(
    `/api/v1/conversations/${encodeURIComponent(conversationId)}/messages?${query}`,
    { signal: options.signal },
  );
}

export async function getMentionableUsers(
  conversationId: string,
  signal?: AbortSignal,
): Promise<MentionableUser[]> {
  return (await requestJson<CollectionResponse<MentionableUser>>(
    `/api/v1/conversations/${encodeURIComponent(conversationId)}/mentionable-users`,
    { signal },
  )).data;
}

export async function sendConversationMessage(
  conversationId: string,
  input: {
    body: string;
    idempotencyKey: string;
    parentMessageId?: string;
    mentionUserIds?: string[];
    attentionUserIds?: string[];
  },
): Promise<ConversationMessage> {
  const response = await requestJson<ResourceResponse<ConversationMessage>>(
    `/api/v1/conversations/${encodeURIComponent(conversationId)}/messages`,
    {
      method: "POST",
      data: {
        body: input.body,
        idempotency_key: input.idempotencyKey,
        parent_message_id: input.parentMessageId ?? null,
        mention_user_ids: input.mentionUserIds ?? [],
        attention_user_ids: input.attentionUserIds ?? [],
      },
    },
  );

  return response.data;
}

export async function updateConversationMessage(
  conversationId: string,
  messageId: string,
  body: string,
): Promise<ConversationMessage> {
  const response = await requestJson<ResourceResponse<ConversationMessage>>(
    `/api/v1/conversations/${encodeURIComponent(conversationId)}/messages/${encodeURIComponent(messageId)}`,
    { method: "PATCH", data: { body } },
  );

  return response.data;
}

export async function deleteConversationMessage(
  conversationId: string,
  messageId: string,
): Promise<ConversationMessage> {
  const response = await requestJson<ResourceResponse<ConversationMessage>>(
    `/api/v1/conversations/${encodeURIComponent(conversationId)}/messages/${encodeURIComponent(messageId)}`,
    { method: "DELETE" },
  );

  return response.data;
}

export async function setMessageReaction(
  conversationId: string,
  messageId: string,
  kind: ConversationReaction["kind"],
  reacted: boolean,
): Promise<ConversationMessage> {
  const response = await requestJson<ResourceResponse<ConversationMessage>>(
    `/api/v1/conversations/${encodeURIComponent(conversationId)}/messages/${encodeURIComponent(messageId)}/reactions`,
    {
      method: reacted ? "PUT" : "DELETE",
      data: { kind },
    },
  );

  return response.data;
}

export async function advanceConversationReadPosition(
  conversationId: string,
  throughSequence: number,
): Promise<CommunicationReadState> {
  const response = await requestJson<ResourceResponse<CommunicationReadState>>(
    `/api/v1/conversations/${encodeURIComponent(conversationId)}/read-position`,
    {
      method: "PUT",
      data: { through_sequence: throughSequence },
    },
  );

  return response.data;
}

export async function joinChannel(channelId: string): Promise<CommunicationChannel> {
  const response = await requestJson<ResourceResponse<CommunicationChannel>>(
    `/api/v1/channels/${encodeURIComponent(channelId)}/join`,
    { method: "POST" },
  );

  return response.data;
}

export async function getChannelMembers(channelId: string, signal?: AbortSignal): Promise<ChannelMember[]> {
  return (await requestJson<CollectionResponse<ChannelMember>>(
    `/api/v1/channels/${encodeURIComponent(channelId)}/members`,
    { signal },
  )).data;
}

export async function getChannelMemberCandidates(
  channelId: string,
  query = "",
  signal?: AbortSignal,
): Promise<MentionableUser[]> {
  const parameters = new URLSearchParams({ limit: "20" });
  if (query) parameters.set("query", query);

  return (await requestJson<CollectionResponse<MentionableUser>>(
    `/api/v1/channels/${encodeURIComponent(channelId)}/member-candidates?${parameters}`,
    { signal },
  )).data;
}

export async function addChannelMember(channelId: string, userId: string): Promise<ChannelMember> {
  return (await requestJson<ResourceResponse<ChannelMember>>(
    `/api/v1/channels/${encodeURIComponent(channelId)}/members`,
    { method: "POST", data: { user_id: userId } },
  )).data;
}

export async function removeChannelMember(channelId: string, userId: string): Promise<void> {
  await requestJson<unknown>(
    `/api/v1/channels/${encodeURIComponent(channelId)}/members/${encodeURIComponent(userId)}`,
    { method: "DELETE" },
  );
}

export async function promoteChannelOwner(channelId: string, userId: string): Promise<ChannelMember> {
  return (await requestJson<ResourceResponse<ChannelMember>>(
    `/api/v1/channels/${encodeURIComponent(channelId)}/members/${encodeURIComponent(userId)}/owner`,
    { method: "PUT" },
  )).data;
}

export async function demoteChannelOwner(channelId: string, userId: string): Promise<ChannelMember> {
  return (await requestJson<ResourceResponse<ChannelMember>>(
    `/api/v1/channels/${encodeURIComponent(channelId)}/members/${encodeURIComponent(userId)}/owner`,
    { method: "DELETE" },
  )).data;
}

export async function leaveChannel(channelId: string): Promise<void> {
  await requestJson<unknown>(
    `/api/v1/channels/${encodeURIComponent(channelId)}/membership`,
    { method: "DELETE" },
  );
}

export async function setChannelFavorite(
  channelId: string,
  favorite: boolean,
): Promise<CommunicationChannel> {
  const response = await requestJson<ResourceResponse<CommunicationChannel>>(
    `/api/v1/channels/${encodeURIComponent(channelId)}/favorite`,
    { method: favorite ? "PUT" : "DELETE" },
  );

  return response.data;
}

export async function setDirectMessageFavorite(
  directMessageId: string,
  favorite: boolean,
): Promise<CommunicationDirectMessage> {
  const response = await requestJson<ResourceResponse<CommunicationDirectMessage>>(
    `/api/v1/direct-messages/${encodeURIComponent(directMessageId)}/favorite`,
    { method: favorite ? "PUT" : "DELETE" },
  );

  return response.data;
}

export async function completeDirectMessage(directMessageId: string): Promise<CommunicationDirectMessage> {
  const response = await requestJson<ResourceResponse<CommunicationDirectMessage>>(
    `/api/v1/direct-messages/${encodeURIComponent(directMessageId)}/complete`,
    { method: "POST" },
  );

  return response.data;
}

export async function requestDirectMessageContinuation(
  directMessageId: string,
): Promise<CommunicationDirectMessage> {
  const response = await requestJson<ResourceResponse<CommunicationDirectMessage>>(
    `/api/v1/direct-messages/${encodeURIComponent(directMessageId)}/continuation-requests`,
    { method: "POST" },
  );

  return response.data;
}

export async function reopenDirectMessage(directMessageId: string): Promise<CommunicationDirectMessage> {
  const response = await requestJson<ResourceResponse<CommunicationDirectMessage>>(
    `/api/v1/direct-messages/${encodeURIComponent(directMessageId)}/reopen`,
    { method: "POST" },
  );

  return response.data;
}
