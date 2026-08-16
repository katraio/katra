<script setup lang="ts">
import { Eye, EyeOff } from "@lucide/vue";
import { ref } from "vue";
import { RouterLink, useRouter } from "vue-router";
import { AuthRequestError, type FieldErrors } from "../../api/auth";
import { signUp } from "../../auth/authSession";
import AuthLayout from "./AuthLayout.vue";

const router = useRouter();
const firstName = ref("");
const lastName = ref("");
const email = ref("");
const password = ref("");
const passwordConfirmation = ref("");
const passwordVisible = ref(false);
const submitting = ref(false);
const fieldErrors = ref<FieldErrors>({});
const formError = ref("");

function firstError(field: string): string | undefined {
  return fieldErrors.value[field]?.[0];
}

async function submit() {
  submitting.value = true;
  fieldErrors.value = {};
  formError.value = "";

  try {
    await signUp({
      first_name: firstName.value,
      last_name: lastName.value,
      email: email.value,
      password: password.value,
      password_confirmation: passwordConfirmation.value,
    });
    await router.replace("/");
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
</script>

<template>
  <AuthLayout>
    <header class="auth-form-header">
      <h1>Create your account</h1>
      <p>Start with a local Katra account. Organization setup comes next.</p>
    </header>

    <div v-if="formError" class="auth-form-alert" role="alert">{{ formError }}</div>

    <form class="auth-form" novalidate @submit.prevent="submit">
      <div class="auth-name-fields">
        <label class="auth-field">
          <span>First name</span>
          <input
            v-model="firstName"
            type="text"
            name="first_name"
            autocomplete="given-name"
            required
            :aria-invalid="Boolean(firstError('first_name'))"
            :aria-describedby="firstError('first_name') ? 'register-first-name-error' : undefined"
          />
          <small v-if="firstError('first_name')" id="register-first-name-error" class="auth-field-error">{{ firstError("first_name") }}</small>
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
            :aria-describedby="firstError('last_name') ? 'register-last-name-error' : undefined"
          />
          <small v-if="firstError('last_name')" id="register-last-name-error" class="auth-field-error">{{ firstError("last_name") }}</small>
        </label>
      </div>

      <label class="auth-field">
        <span>Email address</span>
        <input
          v-model="email"
          type="email"
          name="email"
          autocomplete="email"
          inputmode="email"
          required
          :aria-invalid="Boolean(firstError('email'))"
          :aria-describedby="firstError('email') ? 'register-email-error' : undefined"
        />
        <small v-if="firstError('email')" id="register-email-error" class="auth-field-error">{{ firstError("email") }}</small>
      </label>

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
            :aria-describedby="firstError('password') ? 'register-password-error' : 'register-password-hint'"
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
        <small v-if="firstError('password')" id="register-password-error" class="auth-field-error">{{ firstError("password") }}</small>
        <small v-else id="register-password-hint" class="auth-field-hint">Use at least 12 characters.</small>
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

      <button class="auth-submit" type="submit" :disabled="submitting">
        {{ submitting ? "Creating account…" : "Create account" }}
      </button>
    </form>

    <p class="auth-switch">
      Already have an account?
      <RouterLink to="/login">Sign in</RouterLink>
    </p>
  </AuthLayout>
</template>
