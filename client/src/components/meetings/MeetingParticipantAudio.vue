<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from "vue";
import type { RemoteAudioTrack } from "livekit-client";
import type { MeetingParticipantMedia } from "../../meetings/useMeetingMedia";

const props = defineProps<{
  media: MeetingParticipantMedia | null;
}>();

const microphoneElement = ref<HTMLAudioElement | null>(null);
const screenShareElement = ref<HTMLAudioElement | null>(null);
const microphone = computed(() => props.media?.microphone ?? null);
const screenShareAudio = computed(() => props.media?.screenShareAudio ?? null);
let attachedMicrophone: RemoteAudioTrack | null = null;
let attachedScreenShareAudio: RemoteAudioTrack | null = null;

function replaceTrack(
  current: RemoteAudioTrack | null,
  next: RemoteAudioTrack | null,
  element: HTMLAudioElement | null,
): RemoteAudioTrack | null {
  if (current === next) return current;
  if (current && element) current.detach(element);
  if (next && element) next.attach(element);
  return next;
}

watch(microphone, (next) => {
  attachedMicrophone = replaceTrack(attachedMicrophone, next, microphoneElement.value);
}, { immediate: true });
watch(screenShareAudio, (next) => {
  attachedScreenShareAudio = replaceTrack(attachedScreenShareAudio, next, screenShareElement.value);
}, { immediate: true });

onMounted(() => {
  if (attachedMicrophone && microphoneElement.value) attachedMicrophone.attach(microphoneElement.value);
  if (attachedScreenShareAudio && screenShareElement.value) attachedScreenShareAudio.attach(screenShareElement.value);
});
onBeforeUnmount(() => {
  if (attachedMicrophone && microphoneElement.value) attachedMicrophone.detach(microphoneElement.value);
  if (attachedScreenShareAudio && screenShareElement.value) attachedScreenShareAudio.detach(screenShareElement.value);
});
</script>

<template>
  <span class="meeting-participant-audio" aria-hidden="true">
    <audio ref="microphoneElement" autoplay />
    <audio ref="screenShareElement" autoplay />
  </span>
</template>
