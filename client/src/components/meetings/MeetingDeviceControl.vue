<script setup lang="ts">
import { Check, ChevronUp, Mic, MicOff, Video, VideoOff } from "@lucide/vue";
import { computed, onBeforeUnmount, onMounted, ref } from "vue";
import type { MeetingMediaDevice } from "../../meetings/useMeetingMedia";

const props = defineProps<{
  kind: "microphone" | "camera";
  enabled: boolean;
  devices: readonly MeetingMediaDevice[];
  selectedDeviceId: string;
}>();

const emit = defineEmits<{
  toggle: [];
  select: [deviceId: string];
}>();

const root = ref<HTMLElement | null>(null);
const open = ref(false);
const name = computed(() => props.kind === "microphone" ? "microphone" : "camera");
const actionLabel = computed(() => props.kind === "microphone"
  ? props.enabled ? "Mute" : "Unmute"
  : props.enabled ? "Camera" : "Camera off");

function choose(deviceId: string): void {
  emit("select", deviceId);
  open.value = false;
}

function closeOutside(event: PointerEvent): void {
  if (root.value && !root.value.contains(event.target as Node)) open.value = false;
}

function closeOnEscape(event: KeyboardEvent): void {
  if (event.key === "Escape") open.value = false;
}

onMounted(() => {
  document.addEventListener("pointerdown", closeOutside);
  window.addEventListener("keydown", closeOnEscape);
});
onBeforeUnmount(() => {
  document.removeEventListener("pointerdown", closeOutside);
  window.removeEventListener("keydown", closeOnEscape);
});
</script>

<template>
  <div ref="root" class="meeting-device-control" :class="{ 'is-off': !enabled }">
    <button class="meeting-device-toggle" type="button" :aria-pressed="enabled" :aria-label="kind === 'microphone' ? enabled ? 'Mute microphone' : 'Unmute microphone' : enabled ? 'Turn camera off' : 'Turn camera on'" @click="emit('toggle')">
      <template v-if="kind === 'microphone'"><Mic v-if="enabled" :size="18" aria-hidden="true" /><MicOff v-else :size="18" aria-hidden="true" /></template>
      <template v-else><Video v-if="enabled" :size="18" aria-hidden="true" /><VideoOff v-else :size="18" aria-hidden="true" /></template>
      <span>{{ actionLabel }}</span>
    </button>
    <button class="meeting-device-menu-trigger" type="button" :aria-label="`Choose ${name}`" aria-haspopup="menu" :aria-expanded="open" @click="open = !open"><ChevronUp :size="13" aria-hidden="true" /></button>
    <div v-if="open" class="meeting-device-menu" role="menu" :aria-label="`Available ${name}s`">
      <strong>{{ kind === "microphone" ? "Microphone" : "Camera" }}</strong>
      <button v-for="device in devices" :key="device.deviceId" type="button" role="menuitemradio" :aria-checked="device.deviceId === selectedDeviceId" @click="choose(device.deviceId)">
        <Check :size="14" :class="{ 'is-hidden': device.deviceId !== selectedDeviceId }" aria-hidden="true" />
        <span>{{ device.label }}</span>
      </button>
      <p v-if="!devices.length">No {{ name }} found</p>
    </div>
  </div>
</template>
