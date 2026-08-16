<script setup lang="ts">
import { Eye, EyeOff } from "@lucide/vue";
import { computed, onMounted, ref } from "vue";
import { RouterLink, useRouter } from "vue-router";
import {
  acceptOrganizationInvitation,
  AuthRequestError,
  getOrganizationInvitation,
  type FieldErrors,
  type OrganizationInvitation,
} from "../../api/auth";
import { authSession, refreshAuthentication } from "../../auth/authSession";
import AuthLayout from "./AuthLayout.vue";

const router = useRouter();
const invitationTokenStorageKey = "katra.organization-invitation.token";
const token = ref("");
const invitation = ref<OrganizationInvitation | null>(null);
const loading = ref(true);
const submitting = ref(false);
const firstName = ref("");
const lastName = ref("");
const password = ref("");
const passwordConfirmation = ref("");
const passwordVisible = ref(false);
const fieldErrors = ref<FieldErrors>({});
const formError = ref("");

const authenticated = computed(() => authSession.status.value === "authenticated");
const needsSignIn = computed(() => invitation.value?.existing_account && !authenticated.value);
const needsAccount = computed(() => invitation.value && !invitation.value.existing_account && !authenticated.value);
const signInDestination = computed(() => ({
  name: "login",
  query: { redirect: "/accept-invitation" },
}));

const roleLabel = computed(() => {
  const labels: Record<string, string> = {
    "organization-administrator": "Organization administrator",
    "internal-member": "Internal member",
    "client-administrator": "Client administrator",
    "client-member": "Client member",
  };

  return invitation.value ? labels[invitation.value.role] : "Member";
});

function firstError(field: string): string | undefined {
  return fieldErrors.value[field]?.[0];
}

async function loadInvitation() {
  loading.value = true;
  formError.value = "";

  try {
    invitation.value = await getOrganizationInvitation(token.value);
  } catch (error) {
    formError.value = error instanceof AuthRequestError
      ? error.message
      : "Katra Server is unavailable. Check the local environment and try again.";
  } finally {
    loading.value = false;
  }
}

async function acceptInvitation() {
  submitting.value = true;
  fieldErrors.value = {};
  formError.value = "";

  try {
    await acceptOrganizationInvitation(token.value, needsAccount.value ? {
      first_name: firstName.value,
      last_name: lastName.value,
      password: password.value,
      password_confirmation: passwordConfirmation.value,
    } : {});
    sessionStorage.removeItem(invitationTokenStorageKey);
    await refreshAuthentication();
    await router.replace("/");
  } catch (error) {
    if (error instanceof AuthRequestError) {
      fieldErrors.value = error.fields;
      formError.value = needsAccount.value && Object.keys(error.fields).length > 0 ? "" : error.message;
    } else {
      formError.value = "Katra Server is unavailable. Check the local environment and try again.";
    }
  } finally {
    submitting.value = false;
  }
}

onMounted(async () => {
  const fragmentToken = new URLSearchParams(window.location.hash.slice(1)).get("token") ?? "";

  if (fragmentToken) {
    sessionStorage.setItem(invitationTokenStorageKey, fragmentToken);
    window.history.replaceState(null, "", `${window.location.pathname}${window.location.search}`);
  }

  token.value = fragmentToken || sessionStorage.getItem(invitationTokenStorageKey) || "";

  if (!token.value) {
    loading.value = false;
    formError.value = "This invitation link is unavailable or has expired.";

    return;
  }

  await loadInvitation();
});
</script>

<template>
  <AuthLayout>
    <header class="auth-form-header">
      <h1>Join your team</h1>
      <p v-if="loading">Checking your invitation…</p>
      <p v-else-if="invitation">You have been invited to collaborate in Katra.</p>
      <p v-else>We could not open this invitation.</p>
    </header>

    <div v-if="formError" class="auth-form-alert" role="alert">{{ formError }}</div>

    <template v-if="invitation">
      <dl class="invitation-summary">
        <div>
          <dt>Organization</dt>
          <dd>{{ invitation.organization_name }}</dd>
        </div>
        <div>
          <dt>Invited as</dt>
          <dd>{{ roleLabel }}</dd>
        </div>
        <div>
          <dt>Email address</dt>
          <dd>{{ invitation.email }}</dd>
        </div>
      </dl>

      <div v-if="needsSignIn" class="invitation-action">
        <p>This email already has a Katra account. Sign in with it to accept the invitation.</p>
        <RouterLink class="auth-submit" :to="signInDestination">Sign in to accept</RouterLink>
      </div>

      <form v-else class="auth-form" novalidate @submit.prevent="acceptInvitation">
        <template v-if="needsAccount">
          <div class="auth-name-fields">
            <label class="auth-field">
              <span>First name</span>
              <input
                v-model="firstName"
                type="text"
                name="first_name"
                autocomplete="given-name"
                required
                autofocus
                :aria-invalid="Boolean(firstError('first_name'))"
              />
              <small v-if="firstError('first_name')" class="auth-field-error">{{ firstError("first_name") }}</small>
            </label>

            <label class="auth-field">
              <span>Last name</span>
              <input
                v-model="lastName"
                type="text"
                name="last_name"
                autocomplete="family-name"
                required
                :aria-invalid="Boolean(firstError('last_name'))"
              />
              <small v-if="firstError('last_name')" class="auth-field-error">{{ firstError("last_name") }}</small>
            </label>
          </div>

          <label class="auth-field">
            <span>Password</span>
            <span class="auth-password-field">
              <input
                v-model="password"
                :type="passwordVisible ? 'text' : 'password'"
                name="password"
                autocomplete="new-password"
                minlength="12"
                required
                :aria-invalid="Boolean(firstError('password'))"
              />
              <button
                type="button"
                :aria-label="passwordVisible ? 'Hide password' : 'Show password'"
                @click="passwordVisible = !passwordVisible"
              >
                <EyeOff v-if="passwordVisible" :size="18" :stroke-width="1.7" aria-hidden="true" />
                <Eye v-else :size="18" :stroke-width="1.7" aria-hidden="true" />
              </button>
            </span>
            <small v-if="firstError('password')" class="auth-field-error">{{ firstError("password") }}</small>
            <small v-else class="auth-field-hint">Use at least 12 characters.</small>
          </label>

          <label class="auth-field">
            <span>Confirm password</span>
            <input
              v-model="passwordConfirmation"
              :type="passwordVisible ? 'text' : 'password'"
              name="password_confirmation"
              autocomplete="new-password"
              minlength="12"
              required
            />
          </label>
        </template>

        <button class="auth-submit" type="submit" :disabled="submitting">
          {{ submitting ? "Joining workspace…" : "Accept invitation" }}
        </button>
      </form>
    </template>
  </AuthLayout>
</template>
