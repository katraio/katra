<script setup lang="ts">
import {
  Building2,
  Check,
  ChevronLeft,
  ChevronRight,
  Copy,
  KeyRound,
  Link2,
  Mail,
  Pencil,
  Plus,
  RefreshCw,
  ShieldCheck,
  UserRound,
  UsersRound,
  X,
} from "@lucide/vue";
import { computed, ref, watch } from "vue";
import {
  AuthRequestError,
  createAdministrationOrganization,
  createAdministrationInvitation,
  getAdministrationOrganizations,
  getAdministrationInvitations,
  getAdministrationMembers,
  reissueAdministrationInvitation,
  revokeAdministrationInvitation,
  updateAdministrationOrganization,
  updateProfile,
  updateProfilePassword,
  type AdministrationInvitation,
  type AdministrationMember,
  type AdministrationOrganization,
  type AuthUser,
  type FieldErrors,
  type IssuedAdministrationInvitation,
  type MemberAdministrationScope,
  type OrganizationRoleValue,
  type PaginationMeta,
} from "../../api/auth";
import {
  invitationDeliveryLabel,
  organizationRoleLabel,
  userInitials,
} from "../../settings/settingsPresentation";
import KatraSelect, { type KatraSelectOption } from "../ui/KatraSelect.vue";

const props = defineProps<{
  currentUser: AuthUser;
  mode: "profile" | "server";
  administrationScopes?: MemberAdministrationScope[];
  administrationStatus?: LoadState;
}>();

const emit = defineEmits<{
  "user-updated": [user: AuthUser];
  "organizations-updated": [];
}>();

type SettingsSection = "profile" | "security" | "people" | "organizations";
type LoadState = "loading" | "ready" | "unavailable";

const emptyPagination = (): PaginationMeta => ({
  current_page: 1,
  last_page: 1,
  per_page: 25,
  total: 0,
});

const activeSection = ref<SettingsSection>(props.mode === "server"
  ? (props.currentUser.is_global_administrator ? "organizations" : "people")
  : "profile");
const firstName = ref(props.currentUser.first_name);
const lastName = ref(props.currentUser.last_name);
const profileSubmitting = ref(false);
const profileFieldErrors = ref<FieldErrors>({});
const profileError = ref("");
const profileNotice = ref("");
const currentPassword = ref("");
const password = ref("");
const passwordConfirmation = ref("");
const passwordSubmitting = ref(false);
const passwordFieldErrors = ref<FieldErrors>({});
const passwordError = ref("");
const passwordNotice = ref("");
const selectedScopeId = ref("");
const members = ref<AdministrationMember[]>([]);
const invitations = ref<AdministrationInvitation[]>([]);
const membersState = ref<LoadState>("loading");
const invitationsState = ref<LoadState>("loading");
const membersMeta = ref<PaginationMeta>(emptyPagination());
const invitationsMeta = ref<PaginationMeta>(emptyPagination());
const invitationEmail = ref("");
const invitationRole = ref("");
const invitationSubmitting = ref(false);
const invitationActionId = ref<string | null>(null);
const invitationFieldErrors = ref<FieldErrors>({});
const invitationError = ref("");
const issuedInvitation = ref<IssuedAdministrationInvitation | null>(null);
const copyNotice = ref("");
const confirmingRevokeId = ref<string | null>(null);
const organizations = ref<AdministrationOrganization[]>([]);
const organizationsState = ref<LoadState>("loading");
const organizationsLoaded = ref(false);
const organizationName = ref("");
const organizationSubmitting = ref(false);
const organizationFieldErrors = ref<FieldErrors>({});
const organizationError = ref("");
const organizationNotice = ref("");
const editingOrganizationId = ref<string | null>(null);
const editingOrganizationName = ref("");
const organizationActionId = ref<string | null>(null);

const initials = computed(() => userInitials(props.currentUser));
const scopes = computed(() => props.administrationScopes ?? []);
const scopesState = computed<LoadState>(() => props.administrationStatus ?? "ready");
const selectedScope = computed(() => scopes.value.find(
  (scope) => scope.organization.id === selectedScopeId.value,
) ?? null);
const scopeOptions = computed<KatraSelectOption[]>(() => scopes.value.map((scope) => ({
  value: scope.organization.id,
  label: scope.organization.name,
  description: scope.organization.kind === "operating" ? "Operating organization" : "Client organization",
})));
const roleOptions = computed<KatraSelectOption[]>(() => (
  selectedScope.value?.allowed_invitation_roles.map((role) => ({
    value: role.value,
    label: role.label,
  })) ?? []
));
const settingsSections = computed(() => props.mode === "server"
  ? [
      ...(props.currentUser.is_global_administrator
        ? [{ id: "organizations" as const, label: "Organizations", description: "Client boundaries", icon: Building2 }]
        : []),
      { id: "people" as const, label: "People", description: "Members and invitations", icon: UsersRound },
    ]
  : [
      { id: "profile" as const, label: "Profile", description: "Name and account identity", icon: UserRound },
      { id: "security" as const, label: "Security", description: "Password and sign-in", icon: ShieldCheck },
    ]);
const settingsHeading = computed(() => props.mode === "server" ? "Server settings" : "Your Katra account");
const settingsEyebrow = computed(() => props.mode === "server" ? "Administration" : "Profile");
const settingsDescription = computed(() => props.mode === "server"
  ? "Manage authorized organizations, members, and invitations."
  : "Manage your identity and sign-in security.");

function firstError(errors: FieldErrors, field: string): string | undefined {
  return errors[field]?.[0];
}

function errorMessage(error: unknown, fallback: string): string {
  return error instanceof AuthRequestError ? error.message : fallback;
}

function formatDate(value: string | null): string {
  if (!value) return "Not available";
  const date = new Date(value);
  if (Number.isNaN(date.valueOf())) return "Not available";

  return new Intl.DateTimeFormat(undefined, {
    month: "short",
    day: "numeric",
    year: "numeric",
  }).format(date);
}

async function saveProfile() {
  profileSubmitting.value = true;
  profileFieldErrors.value = {};
  profileError.value = "";
  profileNotice.value = "";

  try {
    const updated = await updateProfile({
      first_name: firstName.value,
      last_name: lastName.value,
    });
    emit("user-updated", updated);
    profileNotice.value = "Profile updated.";
  } catch (error) {
    if (error instanceof AuthRequestError) profileFieldErrors.value = error.fields;
    profileError.value = Object.keys(profileFieldErrors.value).length > 0
      ? ""
      : errorMessage(error, "Katra Server could not update your profile.");
  } finally {
    profileSubmitting.value = false;
  }
}

async function savePassword() {
  passwordSubmitting.value = true;
  passwordFieldErrors.value = {};
  passwordError.value = "";
  passwordNotice.value = "";

  try {
    await updateProfilePassword({
      current_password: currentPassword.value,
      password: password.value,
      password_confirmation: passwordConfirmation.value,
    });
    passwordNotice.value = "Password changed. Your current session remains active.";
  } catch (error) {
    if (error instanceof AuthRequestError) passwordFieldErrors.value = error.fields;
    passwordError.value = Object.keys(passwordFieldErrors.value).length > 0
      ? ""
      : errorMessage(error, "Katra Server could not change your password.");
  } finally {
    currentPassword.value = "";
    password.value = "";
    passwordConfirmation.value = "";
    passwordSubmitting.value = false;
  }
}

async function loadMembers(page = 1) {
  if (!selectedScopeId.value) return;
  membersState.value = "loading";

  try {
    const response = await getAdministrationMembers(selectedScopeId.value, page);
    members.value = response.data;
    membersMeta.value = response.meta;
    membersState.value = "ready";
  } catch (error) {
    members.value = [];
    membersState.value = "unavailable";
    invitationError.value = errorMessage(error, "Katra Server could not load members.");
  }
}

async function loadInvitations(page = 1) {
  if (!selectedScopeId.value) return;
  invitationsState.value = "loading";

  try {
    const response = await getAdministrationInvitations(selectedScopeId.value, page);
    invitations.value = response.data;
    invitationsMeta.value = response.meta;
    invitationsState.value = "ready";
  } catch (error) {
    invitations.value = [];
    invitationsState.value = "unavailable";
    invitationError.value = errorMessage(error, "Katra Server could not load invitations.");
  }
}

async function loadPeople() {
  invitationError.value = "";
  confirmingRevokeId.value = null;
  invitationRole.value = selectedScope.value?.allowed_invitation_roles[0]?.value ?? "";
  issuedInvitation.value = null;
  copyNotice.value = "";
  await Promise.all([loadMembers(), loadInvitations()]);
}

async function loadOrganizations() {
  if (!props.currentUser.is_global_administrator) return;
  organizationsState.value = "loading";
  organizationError.value = "";

  try {
    organizations.value = await getAdministrationOrganizations();
    organizationsState.value = "ready";
    organizationsLoaded.value = true;
  } catch (error) {
    organizations.value = [];
    organizationsState.value = "unavailable";
    organizationError.value = errorMessage(error, "Katra Server could not load Organizations.");
  }
}

async function createOrganization() {
  organizationSubmitting.value = true;
  organizationFieldErrors.value = {};
  organizationError.value = "";
  organizationNotice.value = "";

  try {
    const created = await createAdministrationOrganization({ name: organizationName.value });
    organizations.value = [...organizations.value, created]
      .sort((left, right) => left.name.localeCompare(right.name));
    organizationName.value = "";
    organizationNotice.value = `${created.name} is ready with its client team Channel.`;
    emit("organizations-updated");
  } catch (error) {
    if (error instanceof AuthRequestError) organizationFieldErrors.value = error.fields;
    organizationError.value = Object.keys(organizationFieldErrors.value).length > 0
      ? ""
      : errorMessage(error, "Katra Server could not create the Organization.");
  } finally {
    organizationSubmitting.value = false;
  }
}

function startOrganizationRename(organization: AdministrationOrganization) {
  editingOrganizationId.value = organization.id;
  editingOrganizationName.value = organization.name;
  organizationFieldErrors.value = {};
  organizationError.value = "";
  organizationNotice.value = "";
}

function cancelOrganizationRename() {
  editingOrganizationId.value = null;
  editingOrganizationName.value = "";
  organizationFieldErrors.value = {};
}

async function renameOrganization(organization: AdministrationOrganization) {
  if (organizationActionId.value) return;
  organizationActionId.value = organization.id;
  organizationFieldErrors.value = {};
  organizationError.value = "";
  organizationNotice.value = "";

  try {
    const updated = await updateAdministrationOrganization(
      organization.id,
      { name: editingOrganizationName.value },
    );
    organizations.value = organizations.value
      .map((candidate) => candidate.id === updated.id ? updated : candidate)
      .sort((left, right) => left.name.localeCompare(right.name));
    cancelOrganizationRename();
    organizationNotice.value = `${updated.name} was renamed. Its stable slug is unchanged.`;
    emit("organizations-updated");
  } catch (error) {
    if (error instanceof AuthRequestError) organizationFieldErrors.value = error.fields;
    organizationError.value = Object.keys(organizationFieldErrors.value).length > 0
      ? ""
      : errorMessage(error, "Katra Server could not rename the Organization.");
  } finally {
    organizationActionId.value = null;
  }
}

async function issueInvitation() {
  if (!selectedScope.value || !invitationRole.value) return;
  invitationSubmitting.value = true;
  invitationFieldErrors.value = {};
  invitationError.value = "";
  issuedInvitation.value = null;
  copyNotice.value = "";

  try {
    issuedInvitation.value = await createAdministrationInvitation(
      selectedScope.value.organization.id,
      {
        email: invitationEmail.value,
        role: invitationRole.value as OrganizationRoleValue,
      },
    );
    invitationEmail.value = "";
    await loadInvitations();
  } catch (error) {
    if (error instanceof AuthRequestError) invitationFieldErrors.value = error.fields;
    invitationError.value = Object.keys(invitationFieldErrors.value).length > 0
      ? ""
      : errorMessage(error, "Katra Server could not issue the invitation.");
  } finally {
    invitationSubmitting.value = false;
  }
}

async function reissueInvitation(invitation: AdministrationInvitation) {
  if (!selectedScope.value || invitationActionId.value) return;
  invitationActionId.value = invitation.id;
  invitationError.value = "";
  issuedInvitation.value = null;
  copyNotice.value = "";

  try {
    issuedInvitation.value = await reissueAdministrationInvitation(
      selectedScope.value.organization.id,
      invitation.id,
    );
    await loadInvitations();
  } catch (error) {
    invitationError.value = errorMessage(error, "Katra Server could not reissue the invitation.");
  } finally {
    invitationActionId.value = null;
  }
}

async function revokeInvitation(invitation: AdministrationInvitation) {
  if (!selectedScope.value || invitationActionId.value) return;

  if (confirmingRevokeId.value !== invitation.id) {
    confirmingRevokeId.value = invitation.id;
    return;
  }

  invitationActionId.value = invitation.id;
  invitationError.value = "";

  try {
    await revokeAdministrationInvitation(selectedScope.value.organization.id, invitation.id);
    confirmingRevokeId.value = null;
    await loadInvitations();
  } catch (error) {
    invitationError.value = errorMessage(error, "Katra Server could not revoke the invitation.");
  } finally {
    invitationActionId.value = null;
  }
}

async function copyAcceptanceLink() {
  if (!issuedInvitation.value) return;

  try {
    await navigator.clipboard.writeText(issuedInvitation.value.acceptance_url);
    copyNotice.value = "Invitation link copied.";
  } catch {
    copyNotice.value = "Select and copy the link manually.";
  }
}

function selectInput(event: FocusEvent) {
  if (event.target instanceof HTMLInputElement) {
    event.target.select();
  }
}

watch(() => props.currentUser, (user) => {
  firstName.value = user.first_name;
  lastName.value = user.last_name;
}, { deep: true });

watch(selectedScopeId, (next, previous) => {
  if (next && next !== previous) void loadPeople();
});

watch(activeSection, (section) => {
  if (section === "organizations" && !organizationsLoaded.value) {
    void loadOrganizations();
  }
}, { immediate: true });

watch(() => props.currentUser.is_global_administrator, (isGlobalAdministrator) => {
  if (!isGlobalAdministrator && activeSection.value === "organizations") {
    activeSection.value = "people";
  }
});

watch(
  () => props.administrationScopes,
  (availableScopes) => {
    const available = availableScopes ?? [];
    const currentStillAvailable = available.some(
      (scope) => scope.organization.id === selectedScopeId.value,
    );
    selectedScopeId.value = currentStillAvailable
      ? selectedScopeId.value
      : (available[0]?.organization.id ?? "");
  },
  { immediate: true, deep: true },
);
</script>

<template>
  <section class="settings-page" aria-labelledby="settings-title">
    <header class="settings-header">
      <div>
        <span>{{ settingsEyebrow }}</span>
        <h1 id="settings-title">{{ settingsHeading }}</h1>
        <p>{{ settingsDescription }}</p>
      </div>
    </header>

    <div class="settings-layout">
      <nav
        class="settings-nav"
        :aria-label="mode === 'server' ? 'Server settings sections' : 'Profile settings sections'"
      >
        <button
          v-for="section in settingsSections"
          :key="section.id"
          type="button"
          :class="{ 'is-active': activeSection === section.id }"
          :aria-current="activeSection === section.id ? 'page' : undefined"
          @click="activeSection = section.id"
        >
          <component :is="section.icon" :size="18" :stroke-width="1.8" aria-hidden="true" />
          <span><strong>{{ section.label }}</strong><small>{{ section.description }}</small></span>
        </button>
      </nav>

      <div class="settings-content">
        <section v-if="activeSection === 'profile'" class="settings-section" aria-labelledby="profile-settings-title">
          <header>
            <div>
              <h2 id="profile-settings-title">Profile</h2>
              <p>Your name is used throughout Channels, Direct Messages, and meetings.</p>
            </div>
          </header>

          <form class="settings-form" novalidate @submit.prevent="saveProfile">
            <div class="profile-identity-row">
              <span class="profile-identity-avatar" aria-hidden="true">{{ initials }}</span>
              <span><strong>{{ currentUser.name }}</strong><small>Name-derived initials are used until avatar uploads have an approved privacy policy.</small></span>
            </div>

            <div v-if="profileError" class="settings-alert" role="alert">{{ profileError }}</div>
            <div v-if="profileNotice" class="settings-notice" role="status"><Check :size="15" aria-hidden="true" />{{ profileNotice }}</div>

            <div class="settings-name-grid">
              <label>
                <span>First name</span>
                <input v-model="firstName" name="first_name" autocomplete="given-name" :aria-invalid="Boolean(firstError(profileFieldErrors, 'first_name'))" />
                <small v-if="firstError(profileFieldErrors, 'first_name')" class="settings-field-error">{{ firstError(profileFieldErrors, "first_name") }}</small>
              </label>
              <label>
                <span>Last name</span>
                <input v-model="lastName" name="last_name" autocomplete="family-name" :aria-invalid="Boolean(firstError(profileFieldErrors, 'last_name'))" />
                <small v-if="firstError(profileFieldErrors, 'last_name')" class="settings-field-error">{{ firstError(profileFieldErrors, "last_name") }}</small>
              </label>
            </div>

            <label>
              <span>Email address</span>
              <span class="settings-readonly-field"><Mail :size="16" aria-hidden="true" /><span>{{ currentUser.email }}</span><em>{{ currentUser.email_verified_at ? "Verified" : "Not verified" }}</em></span>
              <small>Email changes remain locked until Katra has a verified address-change and recovery flow.</small>
            </label>

            <footer>
              <button class="settings-primary-action" type="submit" :disabled="profileSubmitting">
                {{ profileSubmitting ? "Saving…" : "Save profile" }}
              </button>
            </footer>
          </form>
        </section>

        <section v-else-if="activeSection === 'security'" class="settings-section" aria-labelledby="security-settings-title">
          <header>
            <div>
              <h2 id="security-settings-title">Security</h2>
              <p>Change your password without ending the browser session you are using now.</p>
            </div>
            <KeyRound :size="22" :stroke-width="1.7" aria-hidden="true" />
          </header>

          <form class="settings-form" novalidate @submit.prevent="savePassword">
            <div v-if="passwordError" class="settings-alert" role="alert">{{ passwordError }}</div>
            <div v-if="passwordNotice" class="settings-notice" role="status"><Check :size="15" aria-hidden="true" />{{ passwordNotice }}</div>

            <label>
              <span>Current password</span>
              <input v-model="currentPassword" type="password" name="current_password" autocomplete="current-password" :aria-invalid="Boolean(firstError(passwordFieldErrors, 'current_password'))" />
              <small v-if="firstError(passwordFieldErrors, 'current_password')" class="settings-field-error">{{ firstError(passwordFieldErrors, "current_password") }}</small>
            </label>
            <div class="settings-name-grid">
              <label>
                <span>New password</span>
                <input v-model="password" type="password" name="password" autocomplete="new-password" minlength="12" :aria-invalid="Boolean(firstError(passwordFieldErrors, 'password'))" />
                <small v-if="firstError(passwordFieldErrors, 'password')" class="settings-field-error">{{ firstError(passwordFieldErrors, "password") }}</small>
                <small v-else>Use at least 12 characters.</small>
              </label>
              <label>
                <span>Confirm new password</span>
                <input v-model="passwordConfirmation" type="password" name="password_confirmation" autocomplete="new-password" minlength="12" />
              </label>
            </div>
            <footer>
              <button class="settings-primary-action" type="submit" :disabled="passwordSubmitting">
                {{ passwordSubmitting ? "Changing…" : "Change password" }}
              </button>
            </footer>
          </form>
        </section>

        <section v-else-if="activeSection === 'organizations'" class="settings-section settings-organizations" aria-labelledby="organizations-settings-title">
          <header>
            <div>
              <h2 id="organizations-settings-title">Organizations</h2>
              <p>Establish client boundaries and keep their stable identifiers intact.</p>
            </div>
            <Building2 :size="22" :stroke-width="1.7" aria-hidden="true" />
          </header>

          <form class="organization-create-form" novalidate @submit.prevent="createOrganization">
            <div>
              <h3>Add a client Organization</h3>
              <p>Katra also creates the Organization's client team Channel.</p>
            </div>
            <label>
              <span>Organization name</span>
              <input v-model="organizationName" name="name" autocomplete="organization" placeholder="Acme Field Services" :aria-invalid="Boolean(firstError(organizationFieldErrors, 'name'))" />
              <small v-if="firstError(organizationFieldErrors, 'name')" class="settings-field-error">{{ firstError(organizationFieldErrors, "name") }}</small>
              <small v-else>The stable slug is derived once and cannot be edited here.</small>
            </label>
            <button class="settings-primary-action" type="submit" :disabled="organizationSubmitting || !organizationName.trim()">
              <Plus :size="15" aria-hidden="true" />{{ organizationSubmitting ? "Adding…" : "Add Organization" }}
            </button>
          </form>

          <div v-if="organizationError" class="settings-alert" role="alert">{{ organizationError }}</div>
          <div v-if="organizationNotice" class="settings-notice" role="status"><Check :size="15" aria-hidden="true" />{{ organizationNotice }}</div>

          <section class="organization-directory" aria-labelledby="organization-directory-title">
            <header>
              <div>
                <h3 id="organization-directory-title">Server Organizations</h3>
                <p>{{ organizations.length }} {{ organizations.length === 1 ? "boundary" : "boundaries" }} on this installation</p>
              </div>
              <small>Names may change. Slugs and types remain stable.</small>
            </header>

            <div v-if="organizationsState === 'loading'" class="settings-empty-state">Loading Organizations…</div>
            <div v-else-if="organizationsState === 'unavailable'" class="settings-empty-state">Organizations are unavailable.</div>
            <div v-else-if="organizations.length === 0" class="settings-empty-state">No Organizations are available.</div>
            <div v-else class="organization-rows">
              <article v-for="organization in organizations" :key="organization.id" class="organization-row">
                <span class="organization-symbol" aria-hidden="true"><Building2 :size="17" :stroke-width="1.7" /></span>

                <form v-if="editingOrganizationId === organization.id" class="organization-rename-form" novalidate @submit.prevent="renameOrganization(organization)">
                  <label>
                    <span class="sr-only">Organization name</span>
                    <input v-model="editingOrganizationName" name="name" autocomplete="organization" :aria-invalid="Boolean(firstError(organizationFieldErrors, 'name'))" />
                    <small v-if="firstError(organizationFieldErrors, 'name')" class="settings-field-error">{{ firstError(organizationFieldErrors, "name") }}</small>
                    <small v-else>/{{ organization.slug }} remains unchanged</small>
                  </label>
                  <span class="organization-edit-actions">
                    <button type="button" :disabled="organizationActionId === organization.id" @click="cancelOrganizationRename">Cancel</button>
                    <button class="is-primary" type="submit" :disabled="organizationActionId === organization.id || !editingOrganizationName.trim()">
                      {{ organizationActionId === organization.id ? "Saving…" : "Save" }}
                    </button>
                  </span>
                </form>

                <template v-else>
                  <span class="organization-primary">
                    <strong>{{ organization.name }}</strong>
                    <small>/{{ organization.slug }} · Created {{ formatDate(organization.created_at) }}</small>
                  </span>
                  <span class="organization-kind" :class="`is-${organization.kind}`">{{ organization.kind === "operating" ? "Operating business" : "Client Organization" }}</span>
                  <span class="organization-members"><strong>{{ organization.member_count }}</strong><small>active {{ organization.member_count === 1 ? "member" : "members" }}</small></span>
                  <button v-if="organization.actions.update" class="organization-rename-action" type="button" @click="startOrganizationRename(organization)"><Pencil :size="14" aria-hidden="true" />Rename</button>
                </template>
              </article>
            </div>
          </section>
        </section>

        <section v-else class="settings-section settings-people" aria-labelledby="people-settings-title">
          <header>
            <div>
              <h2 id="people-settings-title">People</h2>
              <p>Invite members and review access without exposing public registration.</p>
            </div>
            <div v-if="scopes.length" class="settings-organization-select">
              <KatraSelect v-model="selectedScopeId" :options="scopeOptions" label="Administration organization" large />
            </div>
          </header>

          <div v-if="scopesState === 'loading'" class="settings-empty-state">Loading authorized organizations…</div>
          <div v-else-if="scopesState === 'unavailable'" class="settings-alert" role="alert">Katra Server could not load member administration.</div>
          <div v-else-if="!selectedScope" class="settings-empty-state">You do not have a member-administration scope.</div>

          <template v-else>
            <form v-if="selectedScope.actions.invite" class="people-invite-form" novalidate @submit.prevent="issueInvitation">
              <div>
                <h3>Invite a member</h3>
                <p>Katra creates a seven-day, single-use link. Copy it immediately after issuing.</p>
              </div>
              <label>
                <span>Email address</span>
                <input v-model="invitationEmail" type="email" name="email" autocomplete="email" placeholder="teammate@example.com" :aria-invalid="Boolean(firstError(invitationFieldErrors, 'email'))" />
                <small v-if="firstError(invitationFieldErrors, 'email')" class="settings-field-error">{{ firstError(invitationFieldErrors, "email") }}</small>
              </label>
              <label>
                <span>Role</span>
                <KatraSelect v-model="invitationRole" :options="roleOptions" label="Invitation role" large />
                <small v-if="firstError(invitationFieldErrors, 'role')" class="settings-field-error">{{ firstError(invitationFieldErrors, "role") }}</small>
              </label>
              <button class="settings-primary-action" type="submit" :disabled="invitationSubmitting || !invitationEmail || !invitationRole">
                {{ invitationSubmitting ? "Issuing…" : "Create invitation" }}
              </button>
            </form>

            <div v-if="invitationError" class="settings-alert" role="alert">{{ invitationError }}</div>

            <section v-if="issuedInvitation" class="issued-invitation" aria-labelledby="issued-invitation-title">
              <span class="issued-invitation-icon" aria-hidden="true"><Link2 :size="19" /></span>
              <div>
                <h3 id="issued-invitation-title">Invitation link ready</h3>
                <p v-if="issuedInvitation.delivery_status === 'copy-link-only'">Email delivery is not configured. Share this link securely with {{ issuedInvitation.email }}.</p>
                <p v-else>Email delivery is {{ issuedInvitation.delivery_status }}. You can also share this link directly.</p>
                <div class="issued-link-row">
                  <input :value="issuedInvitation.acceptance_url" readonly aria-label="New invitation acceptance link" @focus="selectInput" />
                  <button type="button" @click="copyAcceptanceLink"><Copy :size="15" aria-hidden="true" />Copy link</button>
                </div>
                <small role="status">{{ copyNotice || "This secret link cannot be retrieved later. Reissue it if it is lost." }}</small>
              </div>
              <button type="button" class="issued-invitation-dismiss" aria-label="Dismiss invitation link" @click="issuedInvitation = null"><X :size="17" /></button>
            </section>

            <section class="people-directory" aria-labelledby="members-heading">
              <header><div><h3 id="members-heading">Members</h3><p>{{ membersMeta.total }} authorized {{ membersMeta.total === 1 ? "member" : "members" }}</p></div></header>
              <div v-if="membersState === 'loading'" class="settings-empty-state">Loading members…</div>
              <div v-else-if="membersState === 'unavailable'" class="settings-empty-state">Members are unavailable.</div>
              <div v-else-if="members.length === 0" class="settings-empty-state">No members are visible in this scope.</div>
              <div v-else class="people-rows">
                <article v-for="member in members" :key="member.id" class="people-row">
                  <span class="people-avatar" aria-hidden="true">{{ member.name.split(/\s+/).map((part) => part[0]).slice(0, 2).join("").toUpperCase() }}</span>
                  <span class="people-primary"><strong>{{ member.name }}</strong><small>{{ member.email }}</small></span>
                  <span class="people-role">{{ organizationRoleLabel(member.role) }}</span>
                  <span class="people-state" :class="`is-${member.status}`">{{ member.status }}</span>
                </article>
              </div>
              <footer v-if="membersMeta.last_page > 1" class="settings-pagination">
                <button type="button" :disabled="membersMeta.current_page <= 1" aria-label="Previous member page" @click="loadMembers(membersMeta.current_page - 1)"><ChevronLeft :size="16" /></button>
                <span>Page {{ membersMeta.current_page }} of {{ membersMeta.last_page }}</span>
                <button type="button" :disabled="membersMeta.current_page >= membersMeta.last_page" aria-label="Next member page" @click="loadMembers(membersMeta.current_page + 1)"><ChevronRight :size="16" /></button>
              </footer>
            </section>

            <section class="people-directory" aria-labelledby="invitations-heading">
              <header><div><h3 id="invitations-heading">Invitations</h3><p>Acceptance secrets never appear in this history.</p></div></header>
              <div v-if="invitationsState === 'loading'" class="settings-empty-state">Loading invitations…</div>
              <div v-else-if="invitationsState === 'unavailable'" class="settings-empty-state">Invitations are unavailable.</div>
              <div v-else-if="invitations.length === 0" class="settings-empty-state">No invitations have been issued in this scope.</div>
              <div v-else class="invitation-rows">
                <article v-for="invitation in invitations" :key="invitation.id" class="invitation-row">
                  <span class="invitation-primary"><strong>{{ invitation.email }}</strong><small>{{ organizationRoleLabel(invitation.role) }} · invited by {{ invitation.invited_by.name }}</small></span>
                  <span class="invitation-delivery"><strong>{{ invitationDeliveryLabel(invitation.delivery_status) }}</strong><small>{{ invitation.status === "pending" ? `Expires ${formatDate(invitation.expires_at)}` : `Status updated ${formatDate(invitation.accepted_at ?? invitation.revoked_at ?? invitation.expires_at)}` }}</small></span>
                  <span class="invitation-state" :class="`is-${invitation.status}`">{{ invitation.status }}</span>
                  <span class="invitation-actions">
                    <button v-if="invitation.actions.reissue" type="button" :disabled="invitationActionId === invitation.id" @click="reissueInvitation(invitation)"><RefreshCw :size="14" aria-hidden="true" />Reissue</button>
                    <button v-if="invitation.actions.revoke" type="button" class="is-danger" :disabled="invitationActionId === invitation.id" @click="revokeInvitation(invitation)">{{ confirmingRevokeId === invitation.id ? "Confirm revoke" : "Revoke" }}</button>
                  </span>
                </article>
              </div>
              <footer v-if="invitationsMeta.last_page > 1" class="settings-pagination">
                <button type="button" :disabled="invitationsMeta.current_page <= 1" aria-label="Previous invitation page" @click="loadInvitations(invitationsMeta.current_page - 1)"><ChevronLeft :size="16" /></button>
                <span>Page {{ invitationsMeta.current_page }} of {{ invitationsMeta.last_page }}</span>
                <button type="button" :disabled="invitationsMeta.current_page >= invitationsMeta.last_page" aria-label="Next invitation page" @click="loadInvitations(invitationsMeta.current_page + 1)"><ChevronRight :size="16" /></button>
              </footer>
            </section>
          </template>
        </section>
      </div>
    </div>
  </section>
</template>

<style scoped>
.settings-page { width: 100%; min-width: 0; height: 100%; overflow-y: auto; padding: 32px 36px 52px; border-radius: 12px; background: #2e3745; color: #d8dee9; }
.settings-header { max-width: 1080px; margin: 0 auto; }
.settings-header > div:first-child > span { color: #c99bc6; font-size: 10px; font-weight: 750; letter-spacing: .055em; text-transform: uppercase; }
.settings-header h1 { margin: 7px 0 0; color: #f1f3f6; font-size: 25px; letter-spacing: -.027em; }
.settings-header p { max-width: 620px; margin: 8px 0 0; color: #98a3b0; font-size: 12px; line-height: 1.55; }
.profile-identity-avatar, .people-avatar { display: grid; place-items: center; border-radius: 50%; background: #5a4d5b; color: #f0dbed; font-weight: 780; letter-spacing: .02em; }
.settings-layout { display: grid; max-width: 1080px; grid-template-columns: 220px minmax(0, 1fr); align-items: start; gap: 34px; margin: 34px auto 0; }
.settings-nav { display: grid; gap: 5px; position: sticky; top: 0; }
.settings-nav button { display: grid; width: 100%; min-height: 58px; grid-template-columns: 24px minmax(0, 1fr); align-items: center; gap: 10px; padding: 10px 11px; border: 0; border-radius: 9px; outline: 0; background: transparent; color: #99a4b1; text-align: left; cursor: pointer; }
.settings-nav button:hover, .settings-nav button:focus-visible { background: #343d4a; color: #dfe4e9; }
.settings-nav button.is-active { background: #514651; color: #efdced; }
.settings-nav button > span { display: grid; gap: 3px; }
.settings-nav strong { color: inherit; font-size: 12px; }
.settings-nav small { color: #818d9b; font-size: 9px; line-height: 1.35; }
.settings-nav button.is-active small { color: #c4a9c1; }
.settings-content { min-width: 0; }
.settings-section { display: grid; gap: 22px; }
.settings-section > header { display: flex; min-height: 54px; align-items: flex-start; justify-content: space-between; gap: 24px; }
.settings-section > header h2 { margin: 0; color: #edf0f3; font-size: 18px; letter-spacing: -.018em; }
.settings-section > header p { margin: 7px 0 0; color: #909ba8; font-size: 11px; line-height: 1.5; }
.settings-section > header > svg { color: #b999b7; }
.settings-form, .people-invite-form, .organization-create-form, .issued-invitation, .people-directory, .organization-directory { border-radius: 12px; background: #343d49; }
.settings-form { display: grid; gap: 18px; padding: 25px; }
.settings-form label, .people-invite-form label, .organization-create-form label, .organization-rename-form label { display: grid; min-width: 0; gap: 7px; }
.settings-form label > span:first-child, .people-invite-form label > span:first-child, .organization-create-form label > span:first-child { color: #adb6c1; font-size: 10px; font-weight: 680; }
.settings-form input, .people-invite-form input, .organization-create-form input, .organization-rename-form input, .issued-link-row input { width: 100%; height: 42px; border: 0; border-radius: 8px; outline: 0; background: #29313b; color: #e5e8ec; font: 520 12px/1.4 Inter, sans-serif; }
.settings-form input, .people-invite-form input, .organization-create-form input, .organization-rename-form input { padding: 0 12px; }
.settings-form input:focus, .people-invite-form input:focus, .organization-create-form input:focus, .organization-rename-form input:focus, .issued-link-row input:focus { box-shadow: 0 0 0 2px rgb(196 154 192 / 24%); }
.settings-form label > small, .people-invite-form label > small, .organization-create-form label > small, .organization-rename-form label > small { color: #7f8b99; font-size: 9px; line-height: 1.45; }
.settings-name-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); align-items: start; gap: 16px; }
.profile-identity-row { display: flex; align-items: center; gap: 14px; padding-bottom: 4px; }
.profile-identity-avatar { width: 56px; height: 56px; flex: 0 0 56px; font-size: 17px; }
.profile-identity-row > span:last-child { display: grid; gap: 5px; }
.profile-identity-row strong { color: #e9edf1; font-size: 14px; }
.profile-identity-row small { max-width: 540px; color: #8793a1; font-size: 9px; line-height: 1.45; }
.settings-readonly-field { display: grid; height: 42px; grid-template-columns: 20px minmax(0, 1fr) auto; align-items: center; gap: 8px; padding: 0 11px; border-radius: 8px; background: #2f3742; color: #adb6c1; }
.settings-readonly-field svg { color: #8793a1; }
.settings-readonly-field > span { overflow: hidden; font-size: 11px; text-overflow: ellipsis; white-space: nowrap; }
.settings-readonly-field em { color: #9bc3ae; font-size: 9px; font-style: normal; font-weight: 700; text-transform: uppercase; }
.settings-form footer { display: flex; justify-content: flex-end; padding-top: 4px; }
.settings-primary-action { display: inline-flex; min-height: 40px; align-items: center; justify-content: center; gap: 8px; padding: 0 15px; border: 0; border-radius: 8px; outline: 0; background: #c494c2; color: #242a33; font-size: 11px; font-weight: 760; cursor: pointer; }
.settings-primary-action:hover, .settings-primary-action:focus-visible { background: #d3a8d0; box-shadow: 0 0 0 2px rgb(211 168 208 / 19%); }
.settings-primary-action:disabled { opacity: .5; cursor: wait; }
.settings-alert, .settings-notice { display: flex; min-height: 38px; align-items: center; gap: 8px; padding: 9px 11px; border-radius: 8px; font-size: 10px; line-height: 1.45; }
.settings-alert { background: rgb(191 97 106 / 15%); color: #e0a1a7; }
.settings-notice { background: rgb(163 190 140 / 12%); color: #b8d1aa; }
.settings-field-error { color: #d9969d !important; }
.settings-organization-select { width: min(280px, 42vw); }
.people-invite-form { display: grid; grid-template-columns: minmax(200px, 1fr) minmax(190px, 1fr) minmax(180px, .72fr) auto; align-items: end; gap: 14px; padding: 21px; }
.organization-create-form { display: grid; grid-template-columns: minmax(210px, .9fr) minmax(240px, 1.1fr) auto; align-items: start; gap: 18px; padding: 21px; }
.people-invite-form > div:first-child { align-self: center; }
.organization-create-form > div:first-child { align-self: start; }
.organization-create-form > .settings-primary-action { margin-top: 22px; }
.people-invite-form h3, .organization-create-form h3, .issued-invitation h3, .people-directory h3, .organization-directory h3 { margin: 0; color: #e7ebef; font-size: 12px; }
.people-invite-form p, .organization-create-form p, .issued-invitation p, .people-directory header p, .organization-directory header p { margin: 5px 0 0; color: #8793a1; font-size: 9px; line-height: 1.45; }
.issued-invitation { display: grid; grid-template-columns: 38px minmax(0, 1fr) 30px; align-items: start; gap: 12px; padding: 18px; background: #4a414c; }
.issued-invitation-icon { display: grid; width: 36px; height: 36px; place-items: center; border-radius: 10px; background: #5d4e5f; color: #e2c4df; }
.issued-link-row { display: grid; grid-template-columns: minmax(0, 1fr) auto; gap: 8px; margin-top: 12px; }
.issued-link-row input { padding: 0 11px; font-size: 10px; }
.issued-link-row button, .invitation-actions button, .settings-pagination button, .issued-invitation-dismiss { border: 0; outline: 0; cursor: pointer; }
.issued-link-row button { display: inline-flex; align-items: center; gap: 7px; padding: 0 12px; border-radius: 8px; background: #d0a4ce; color: #252a32; font-size: 10px; font-weight: 750; }
.issued-invitation > div > small { display: block; margin-top: 8px; color: #c2a9bf; font-size: 9px; }
.issued-invitation-dismiss { display: grid; width: 30px; height: 30px; place-items: center; border-radius: 7px; background: transparent; color: #aa96a8; }
.issued-invitation-dismiss:hover, .issued-invitation-dismiss:focus-visible { background: #5a4b5b; color: #eeddec; }
.people-directory, .organization-directory { overflow: hidden; }
.people-directory > header { display: flex; min-height: 62px; align-items: center; justify-content: space-between; padding: 0 18px; background: #37414d; }
.organization-directory > header { display: flex; min-height: 62px; align-items: center; justify-content: space-between; gap: 18px; padding: 0 18px; background: #37414d; }
.organization-directory > header > small { color: #778493; font-size: 9px; text-align: right; }
.people-rows, .invitation-rows { display: grid; }
.organization-rows { display: grid; }
.people-row, .invitation-row { min-height: 62px; align-items: center; gap: 12px; padding: 10px 18px; }
.organization-row { display: grid; min-height: 70px; grid-template-columns: 38px minmax(190px, 1fr) minmax(120px, .6fr) 88px auto; align-items: center; gap: 14px; padding: 11px 18px; }
.organization-row:hover { background: rgb(216 222 233 / 2.7%); }
.organization-symbol { display: grid; width: 36px; height: 36px; place-items: center; border-radius: 10px; background: #414b58; color: #bd9bbb; }
.organization-primary, .organization-members { display: grid; min-width: 0; gap: 4px; }
.organization-primary strong, .organization-members strong { overflow: hidden; color: #dce1e6; font-size: 11px; text-overflow: ellipsis; white-space: nowrap; }
.organization-primary small, .organization-members small { overflow: hidden; color: #818d9b; font-size: 9px; text-overflow: ellipsis; white-space: nowrap; }
.organization-kind { justify-self: start; padding: 5px 7px; border-radius: 6px; background: rgb(136 192 208 / 9%); color: #9fc1cc; font-size: 9px; font-weight: 680; }
.organization-kind.is-operating { background: rgb(180 142 173 / 13%); color: #c9a7c6; }
.organization-rename-action, .organization-edit-actions button { display: inline-flex; min-height: 32px; align-items: center; justify-content: center; gap: 6px; padding: 0 10px; border: 0; border-radius: 7px; outline: 0; background: #414b58; color: #cbd2da; font-size: 9px; cursor: pointer; }
.organization-rename-action:hover, .organization-rename-action:focus-visible, .organization-edit-actions button:hover, .organization-edit-actions button:focus-visible { background: #4b5664; color: #f0f2f4; }
.organization-rename-form { display: grid; grid-column: 2 / -1; grid-template-columns: minmax(220px, 1fr) auto; align-items: start; gap: 12px; }
.organization-edit-actions { display: inline-flex; gap: 7px; padding-top: 5px; }
.organization-edit-actions button.is-primary { background: #c494c2; color: #242a33; font-weight: 750; }
.organization-edit-actions button:disabled { opacity: .5; cursor: wait; }
.people-row { display: grid; grid-template-columns: 34px minmax(160px, 1fr) minmax(130px, .55fr) 74px; }
.people-row:hover, .invitation-row:hover { background: rgb(216 222 233 / 2.7%); }
.people-avatar { width: 32px; height: 32px; font-size: 9px; }
.people-primary, .invitation-primary, .invitation-delivery { display: grid; min-width: 0; gap: 4px; }
.people-primary strong, .invitation-primary strong, .invitation-delivery strong { overflow: hidden; color: #dce1e6; font-size: 11px; text-overflow: ellipsis; white-space: nowrap; }
.people-primary small, .invitation-primary small, .invitation-delivery small { overflow: hidden; color: #818d9b; font-size: 9px; text-overflow: ellipsis; white-space: nowrap; }
.people-role { color: #a9b2bd; font-size: 10px; }
.people-state, .invitation-state { justify-self: start; color: #9bc3ae; font-size: 9px; font-weight: 760; letter-spacing: .035em; text-transform: uppercase; }
.people-state.is-suspended, .invitation-state.is-revoked, .invitation-state.is-expired { color: #c9a079; }
.invitation-state.is-accepted { color: #9bc3ae; }
.invitation-row { display: grid; grid-template-columns: minmax(190px, 1.2fr) minmax(140px, .75fr) 76px auto; }
.invitation-actions { display: inline-flex; justify-content: flex-end; gap: 6px; }
.invitation-actions button { display: inline-flex; min-height: 30px; align-items: center; gap: 5px; padding: 0 9px; border-radius: 7px; background: #414b58; color: #cbd2da; font-size: 9px; }
.invitation-actions button:hover, .invitation-actions button:focus-visible { background: #4b5664; color: #f0f2f4; }
.invitation-actions button.is-danger { background: rgb(191 97 106 / 13%); color: #d99aa1; }
.invitation-actions button:disabled { opacity: .5; cursor: wait; }
.settings-pagination { display: flex; min-height: 46px; align-items: center; justify-content: center; gap: 10px; background: #313a45; color: #8e99a7; font-size: 9px; }
.settings-pagination button { display: grid; width: 28px; height: 28px; place-items: center; border-radius: 7px; background: #3c4653; color: #bec6cf; }
.settings-pagination button:disabled { opacity: .35; cursor: default; }
.settings-empty-state { display: grid; min-height: 92px; place-items: center; padding: 20px; color: #8793a1; font-size: 11px; text-align: center; }

@media (max-width: 1040px) {
  .people-invite-form { grid-template-columns: minmax(0, 1fr) minmax(180px, .72fr); }
  .organization-create-form { grid-template-columns: minmax(0, 1fr) auto; }
  .organization-create-form > div:first-child { grid-column: 1 / -1; }
  .people-invite-form > div:first-child { grid-column: 1 / -1; }
  .people-invite-form .settings-primary-action { align-self: end; }
  .invitation-row { grid-template-columns: minmax(170px, 1fr) minmax(135px, .65fr) 70px; }
  .invitation-actions { grid-column: 1 / -1; justify-content: flex-start; padding-left: 0; }
}

@media (max-width: 760px) {
  .settings-page { padding: 72px 16px 42px; }
  .settings-layout { grid-template-columns: 1fr; gap: 22px; margin-top: 25px; }
  .settings-nav { position: static; display: flex; overflow-x: auto; padding-bottom: 3px; }
  .settings-nav button { min-width: 150px; }
  .settings-section > header { flex-direction: column; gap: 13px; }
  .settings-organization-select { width: 100%; }
  .settings-form { padding: 19px; }
  .settings-name-grid { grid-template-columns: 1fr; }
  .people-invite-form { grid-template-columns: 1fr; padding: 18px; }
  .organization-create-form { grid-template-columns: 1fr; padding: 18px; }
  .organization-create-form > div:first-child { grid-column: auto; }
  .organization-create-form > .settings-primary-action { margin-top: 0; }
  .people-invite-form > div:first-child { grid-column: auto; }
  .people-row { grid-template-columns: 34px minmax(0, 1fr) auto; }
  .people-role { grid-column: 2; }
  .people-state { grid-column: 3; grid-row: 1 / span 2; }
  .invitation-row { grid-template-columns: minmax(0, 1fr) auto; }
  .invitation-delivery { grid-column: 1; }
  .invitation-state { grid-column: 2; grid-row: 1; }
  .invitation-actions { grid-column: 1 / -1; }
  .organization-directory > header { align-items: flex-start; flex-direction: column; justify-content: center; padding: 14px 18px; }
  .organization-directory > header > small { text-align: left; }
  .organization-row { grid-template-columns: 38px minmax(0, 1fr) auto; gap: 10px; }
  .organization-kind { grid-column: 2; }
  .organization-members { grid-column: 3; grid-row: 1 / span 2; text-align: right; }
  .organization-rename-action { grid-column: 2 / -1; justify-self: start; }
  .organization-rename-form { grid-column: 2 / -1; grid-template-columns: 1fr; }
  .organization-edit-actions { padding-top: 0; }
  .issued-invitation { grid-template-columns: 34px minmax(0, 1fr) 28px; padding: 15px; }
  .issued-link-row { grid-template-columns: 1fr; }
  .issued-link-row button { min-height: 38px; justify-content: center; }
}
</style>
