<script setup lang="ts">
import { Eye, EyeOff } from "@lucide/vue";
import { computed, onMounted, ref } from "vue";
import { RouterLink, useRoute, useRouter } from "vue-router";
import { AuthRequestError, type FieldErrors } from "../../api/auth";
import { authSession, refreshAuthentication, signIn } from "../../auth/authSession";
import AuthLayout from "./AuthLayout.vue";

const route = useRoute();
const router = useRouter();
const email = ref("");
const password = ref("");
const remember = ref(false);
const passwordVisible = ref(false);
const submitting = ref(false);
const fieldErrors = ref<FieldErrors>({});
const formError = ref("");
const registrationEnabled = import.meta.env.VITE_REGISTRATION_ENABLED !== "false";

const serverUnavailable = computed(() => authSession.status.value === "unavailable");

function firstError(field: string): string | undefined {
  return fieldErrors.value[field]?.[0];
}

async function submit() {
  submitting.value = true;
  fieldErrors.value = {};
  formError.value = "";

  try {
    await signIn({
      email: email.value,
      password: password.value,
      remember: remember.value,
    });

    const redirect = typeof route.query.redirect === "string" ? route.query.redirect : "/";
    await router.replace(redirect);
  } catch (error) {
    if (error instanceof AuthRequestError) {
      fieldErrors.value = error.fields;
      formError.value = Object.keys(error.fields).length === 0 ? error.message : "";
    } else {
      formError.value = "Katra Server is unavailable. Check the local environment and try again.";
    }
  } finally {
    submitting.value = false;
  }
}

onMounted(async () => {
  if (authSession.status.value !== "unavailable") {
    return;
  }

  await refreshAuthentication();

  if (authSession.user.value) {
    const redirect = typeof route.query.redirect === "string" ? route.query.redirect : "/";
    await router.replace(redirect);
  }
});
</script>

<template>
  <AuthLayout>
    <header class="auth-form-header">
      <h1>Welcome back</h1>
      <p>Sign in to continue to your Katra workspace.</p>
    </header>

    <div v-if="serverUnavailable || formError" class="auth-form-alert" role="alert">
      {{ formError || "Katra Server is unavailable. You can retry when the local environment is ready." }}
    </div>

    <form class="auth-form" novalidate @submit.prevent="submit">
      <label class="auth-field">
        <span>Email address</span>
        <input
          v-model="email"
          type="email"
          name="email"
          autocomplete="email"
          inputmode="email"
          required
          autofocus
          :aria-invalid="Boolean(firstError('email'))"
          :aria-describedby="firstError('email') ? 'login-email-error' : undefined"
        />
        <small v-if="firstError('email')" id="login-email-error" class="auth-field-error">{{ firstError("email") }}</small>
      </label>

      <label class="auth-field">
        <span>Password</span>
        <span class="auth-password-field">
          <input
            v-model="password"
            :type="passwordVisible ? 'text' : 'password'"
            name="password"
            autocomplete="current-password"
            required
            :aria-invalid="Boolean(firstError('password'))"
            :aria-describedby="firstError('password') ? 'login-password-error' : undefined"
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
        <small v-if="firstError('password')" id="login-password-error" class="auth-field-error">{{ firstError("password") }}</small>
      </label>

      <label class="auth-checkbox">
        <input v-model="remember" type="checkbox" name="remember" />
        <span>Keep me signed in</span>
      </label>

      <button class="auth-submit" type="submit" :disabled="submitting">
        {{ submitting ? "Signing in…" : "Sign in" }}
      </button>
    </form>

    <p v-if="registrationEnabled" class="auth-switch">
      New to Katra?
      <RouterLink to="/register">Create an account</RouterLink>
    </p>
  </AuthLayout>
</template>
