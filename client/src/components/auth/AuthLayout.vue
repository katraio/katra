<script setup lang="ts">
import { LockKeyhole } from "@lucide/vue";
</script>

<template>
  <main class="auth-page">
    <section class="auth-brand-pane" aria-label="Katra">
      <img class="auth-wordmark" src="/brand/katra-logo.svg" alt="Katra" />

      <div class="auth-brand-message">
        <p>Team communication and meetings in one self-hosted workspace.</p>
      </div>

      <div class="auth-local-note">
        <LockKeyhole :size="19" :stroke-width="1.7" aria-hidden="true" />
        <span>
          <strong>Local account</strong>
          <small>Your credentials stay with this Katra Server.</small>
        </span>
      </div>
    </section>

    <section class="auth-workspace">
      <div class="auth-form-frame">
        <slot />
      </div>
    </section>
  </main>
</template>
