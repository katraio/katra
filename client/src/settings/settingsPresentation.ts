import type {
  AdministrationInvitation,
  AuthUser,
  MemberAdministrationScope,
  OrganizationRoleValue,
} from "../api/auth";

export function canManageServerPeople(scopes: MemberAdministrationScope[]): boolean {
  return scopes.length > 0;
}

export function canManageServerOrganizations(user: Pick<AuthUser, "is_global_administrator">): boolean {
  return user.is_global_administrator;
}

export function userInitials(user: Pick<AuthUser, "first_name" | "last_name" | "email">): string {
  const initials = [user.first_name, user.last_name]
    .map((part) => part.trim().charAt(0))
    .filter(Boolean)
    .join("")
    .toUpperCase();

  return initials || user.email.trim().charAt(0).toUpperCase() || "K";
}

export function organizationRoleLabel(role: OrganizationRoleValue | null): string {
  const labels: Record<OrganizationRoleValue, string> = {
    "organization-administrator": "Organization administrator",
    "internal-member": "Internal member",
    "client-administrator": "Client administrator",
    "client-member": "Client member",
  };

  return role ? labels[role] : "Member";
}

export function invitationDeliveryLabel(
  deliveryStatus: AdministrationInvitation["delivery_status"],
): string {
  const labels: Record<NonNullable<AdministrationInvitation["delivery_status"]>, string> = {
    "copy-link-only": "Link copied manually",
    queued: "Email queued",
    sent: "Email sent",
    failed: "Email failed",
  };

  return deliveryStatus ? labels[deliveryStatus] : "No delivery attempt";
}
