import assert from "node:assert/strict";
import test from "node:test";
import {
  canManageServerOrganizations,
  canManageServerPeople,
  invitationDeliveryLabel,
  organizationRoleLabel,
  userInitials,
} from "../src/settings/settingsPresentation.ts";

test("shows Server settings only for Server-authorized member administrators", () => {
  assert.equal(canManageServerPeople([]), false);
  assert.equal(canManageServerPeople([{
    organization: { id: "org-1", name: "Katra QA", kind: "operating" },
    allowed_invitation_roles: [],
    actions: { view_members: true, view_invitations: true, invite: false },
  }]), true);
});

test("shows Organization administration only to global administrators", () => {
  assert.equal(canManageServerOrganizations({ is_global_administrator: true }), true);
  assert.equal(canManageServerOrganizations({ is_global_administrator: false }), false);
});

test("uses name-derived initials with a stable email fallback", () => {
  assert.equal(userInitials({ first_name: "Ada", last_name: "Lovelace", email: "ada@example.com" }), "AL");
  assert.equal(userInitials({ first_name: "", last_name: "", email: "katra@example.com" }), "K");
});

test("presents bounded organization roles in plain language", () => {
  assert.equal(organizationRoleLabel("organization-administrator"), "Organization administrator");
  assert.equal(organizationRoleLabel("client-member"), "Client member");
  assert.equal(organizationRoleLabel(null), "Member");
});

test("does not claim email delivery for copy-link-only invitations", () => {
  assert.equal(invitationDeliveryLabel("copy-link-only"), "Link copied manually");
  assert.equal(invitationDeliveryLabel("queued"), "Email queued");
  assert.equal(invitationDeliveryLabel(null), "No delivery attempt");
});
