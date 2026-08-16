export type AuthUser = {
  id: string;
  first_name: string;
  last_name: string;
  name: string;
  email: string;
  email_verified_at: string | null;
  is_global_administrator: boolean;
};

export type FieldErrors = Record<string, string[]>;

export type OrganizationRoleValue =
  | "organization-administrator"
  | "internal-member"
  | "client-administrator"
  | "client-member";

type CurrentUserResponse = {
  data: AuthUser;
};

type ErrorResponse = {
  message?: string;
  errors?: FieldErrors;
};

export type OrganizationInvitation = {
  organization_name: string;
  email: string;
  role: OrganizationRoleValue;
  expires_at: string;
  existing_account: boolean;
};

type OrganizationInvitationResponse = {
  data: OrganizationInvitation;
};

export type MemberAdministrationScope = {
  organization: {
    id: string;
    name: string;
    kind: "operating" | "client";
  };
  allowed_invitation_roles: Array<{
    value: OrganizationRoleValue;
    label: string;
  }>;
  actions: {
    view_members: boolean;
    view_invitations: boolean;
    invite: boolean;
  };
};

export type AdministrationMember = {
  id: string;
  name: string;
  email: string;
  kind: "internal" | "client";
  status: "active" | "suspended";
  role: OrganizationRoleValue | null;
  joined_at: string | null;
};

export type AdministrationInvitation = {
  id: string;
  email: string;
  role: OrganizationRoleValue;
  status: "pending" | "accepted" | "expired" | "revoked";
  invited_by: {
    id: string;
    name: string;
  };
  expires_at: string;
  accepted_at: string | null;
  revoked_at: string | null;
  delivery_status: "copy-link-only" | "queued" | "sent" | "failed" | null;
  last_delivery_at: string | null;
  actions: {
    reissue: boolean;
    revoke: boolean;
  };
};

export type IssuedAdministrationInvitation = {
  id: string;
  organization_id: string;
  email: string;
  role: OrganizationRoleValue;
  expires_at: string;
  acceptance_url: string;
  delivery_status: "copy-link-only" | "queued" | "sent" | "failed" | null;
  last_delivery_at: string | null;
};

export type AdministrationOrganization = {
  id: string;
  name: string;
  slug: string;
  kind: "operating" | "client";
  member_count: number;
  created_at: string;
  actions: {
    update: boolean;
  };
};

export type PaginationMeta = {
  current_page: number;
  last_page: number;
  per_page: number;
  total: number;
};

type CollectionResponse<T> = {
  data: T[];
};

type PaginatedResponse<T> = CollectionResponse<T> & {
  meta: PaginationMeta;
};

type IssuedAdministrationInvitationResponse = {
  data: IssuedAdministrationInvitation;
};

type AdministrationOrganizationResponse = {
  data: AdministrationOrganization;
};

export class AuthRequestError extends Error {
  readonly status: number;
  readonly fields: FieldErrors;

  constructor(message: string, status: number, fields: FieldErrors = {}) {
    super(message);
    this.name = "AuthRequestError";
    this.status = status;
    this.fields = fields;
  }
}

function cookieValue(name: string): string | null {
  const prefix = `${name}=`;
  const cookie = document.cookie
    .split(";")
    .map((part) => part.trim())
    .find((part) => part.startsWith(prefix));

  return cookie ? decodeURIComponent(cookie.slice(prefix.length)) : null;
}

async function errorFromResponse(response: Response): Promise<AuthRequestError> {
  let payload: ErrorResponse = {};

  try {
    payload = await response.json() as ErrorResponse;
  } catch {
    // Use the safe fallback below when a proxy or server returns non-JSON.
  }

  const fallback = response.status >= 500
    ? "Katra Server could not complete the request. Please try again."
    : "The request could not be completed.";

  const message = response.status >= 500 ? fallback : (payload.message ?? fallback);

  return new AuthRequestError(message, response.status, payload.errors ?? {});
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
    throw new AuthRequestError("Katra Server did not establish a secure form session.", 419);
  }

  return token;
}

async function mutateAuth<T = void>(
  method: "POST" | "PATCH" | "PUT" | "DELETE",
  path: string,
  data?: Record<string, unknown>,
): Promise<T> {
  const csrfToken = await initializeCsrf();
  const response = await fetch(path, {
    method,
    credentials: "same-origin",
    headers: {
      Accept: "application/json",
      "Content-Type": "application/json",
      "X-XSRF-TOKEN": csrfToken,
    },
    ...(data ? { body: JSON.stringify(data) } : {}),
  });

  if (!response.ok) {
    throw await errorFromResponse(response);
  }

  if (response.status === 204) {
    return undefined as T;
  }

  return await response.json() as T;
}

async function postAuth<T = void>(path: string, data: Record<string, unknown> = {}): Promise<T> {
  return await mutateAuth<T>("POST", path, data);
}

async function getAuth<T>(path: string, signal?: AbortSignal): Promise<T> {
  const response = await fetch(path, {
    credentials: "same-origin",
    headers: { Accept: "application/json" },
    signal,
  });

  if (!response.ok) {
    throw await errorFromResponse(response);
  }

  return await response.json() as T;
}

function isAuthUser(value: unknown): value is AuthUser {
  return typeof value === "object"
    && value !== null
    && "id" in value
    && typeof value.id === "string"
    && "first_name" in value
    && typeof value.first_name === "string"
    && "last_name" in value
    && typeof value.last_name === "string"
    && "name" in value
    && typeof value.name === "string"
    && "email" in value
    && typeof value.email === "string"
    && "email_verified_at" in value
    && (typeof value.email_verified_at === "string" || value.email_verified_at === null)
    && "is_global_administrator" in value
    && typeof value.is_global_administrator === "boolean";
}

export async function getCurrentUser(): Promise<AuthUser> {
  const response = await fetch("/api/v1/auth/user", {
    credentials: "same-origin",
    headers: { Accept: "application/json" },
  });

  if (!response.ok) {
    throw await errorFromResponse(response);
  }

  const payload: unknown = await response.json();

  if (
    typeof payload !== "object"
    || payload === null
    || !("data" in payload)
    || !isAuthUser((payload as CurrentUserResponse).data)
  ) {
    throw new AuthRequestError("Katra Server returned an unsupported user response.", 502);
  }

  return (payload as CurrentUserResponse).data;
}

export async function registerLocalAccount(input: {
  first_name: string;
  last_name: string;
  email: string;
  password: string;
  password_confirmation: string;
}): Promise<void> {
  await postAuth("/auth/register", input);
}

export async function loginLocalAccount(input: {
  email: string;
  password: string;
  remember: boolean;
}): Promise<void> {
  await postAuth("/auth/login", input);
}

export async function logoutLocalAccount(): Promise<void> {
  await postAuth("/auth/logout");
}

export async function getOrganizationInvitation(token: string): Promise<OrganizationInvitation> {
  const payload = await postAuth<OrganizationInvitationResponse>(
    "/auth/invitations/inspect",
    { token },
  );

  return payload.data;
}

export async function acceptOrganizationInvitation(
  token: string,
  input: {
    first_name?: string;
    last_name?: string;
    password?: string;
    password_confirmation?: string;
  } = {},
): Promise<void> {
  await postAuth("/auth/invitations/accept", { ...input, token });
}

export async function updateProfile(input: {
  first_name: string;
  last_name: string;
}): Promise<AuthUser> {
  const payload = await mutateAuth<CurrentUserResponse>("PATCH", "/api/v1/profile", input);

  if (!isAuthUser(payload.data)) {
    throw new AuthRequestError("Katra Server returned an unsupported user response.", 502);
  }

  return payload.data;
}

export async function updateProfilePassword(input: {
  current_password: string;
  password: string;
  password_confirmation: string;
}): Promise<void> {
  await mutateAuth("PUT", "/api/v1/profile/password", input);
}

export async function getMemberAdministrationScopes(signal?: AbortSignal): Promise<MemberAdministrationScope[]> {
  return (await getAuth<CollectionResponse<MemberAdministrationScope>>(
    "/api/v1/member-administration",
    signal,
  )).data;
}

export async function getAdministrationOrganizations(signal?: AbortSignal): Promise<AdministrationOrganization[]> {
  return (await getAuth<CollectionResponse<AdministrationOrganization>>(
    "/api/v1/organization-administration",
    signal,
  )).data;
}

export async function createAdministrationOrganization(
  input: { name: string },
): Promise<AdministrationOrganization> {
  return (await mutateAuth<AdministrationOrganizationResponse>(
    "POST",
    "/api/v1/organization-administration",
    input,
  )).data;
}

export async function updateAdministrationOrganization(
  organizationId: string,
  input: { name: string },
): Promise<AdministrationOrganization> {
  return (await mutateAuth<AdministrationOrganizationResponse>(
    "PATCH",
    `/api/v1/organization-administration/${encodeURIComponent(organizationId)}`,
    input,
  )).data;
}

export async function getAdministrationMembers(
  organizationId: string,
  page = 1,
): Promise<PaginatedResponse<AdministrationMember>> {
  return await getAuth<PaginatedResponse<AdministrationMember>>(
    `/api/v1/member-administration/${encodeURIComponent(organizationId)}/members?page=${page}`,
  );
}

export async function getAdministrationInvitations(
  organizationId: string,
  page = 1,
): Promise<PaginatedResponse<AdministrationInvitation>> {
  return await getAuth<PaginatedResponse<AdministrationInvitation>>(
    `/api/v1/member-administration/${encodeURIComponent(organizationId)}/invitations?page=${page}`,
  );
}

export async function createAdministrationInvitation(
  organizationId: string,
  input: { email: string; role: OrganizationRoleValue },
): Promise<IssuedAdministrationInvitation> {
  return (await mutateAuth<IssuedAdministrationInvitationResponse>(
    "POST",
    `/api/v1/organizations/${encodeURIComponent(organizationId)}/invitations`,
    input,
  )).data;
}

export async function reissueAdministrationInvitation(
  organizationId: string,
  invitationId: string,
): Promise<IssuedAdministrationInvitation> {
  return (await mutateAuth<IssuedAdministrationInvitationResponse>(
    "POST",
    `/api/v1/organizations/${encodeURIComponent(organizationId)}/invitations/${encodeURIComponent(invitationId)}/reissue`,
  )).data;
}

export async function revokeAdministrationInvitation(
  organizationId: string,
  invitationId: string,
): Promise<void> {
  await mutateAuth(
    "DELETE",
    `/api/v1/organizations/${encodeURIComponent(organizationId)}/invitations/${encodeURIComponent(invitationId)}`,
  );
}
