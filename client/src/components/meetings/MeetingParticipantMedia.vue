<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from "vue";
import type { LocalVideoTrack, RemoteVideoTrack } from "livekit-client";
import type { MeetingParticipantMedia } from "../../meetings/useMeetingMedia";

const props = withDefaults(defineProps<{
  media: MeetingParticipantMedia | null;
  source?: "camera" | "screen-share";
  videoEnabled?: boolean;
}>(), { source: "camera", videoEnabled: true });

const video = ref<HTMLVideoElement | null>(null);
let attachedVideo: LocalVideoTrack | RemoteVideoTrack | null = null;
const visibleVideo = computed(() => props.videoEnabled
  ? props.source === "screen-share" ? props.media?.screenShare ?? null : props.media?.camera ?? null
  : null);

function replaceVideo(next: LocalVideoTrack | RemoteVideoTrack | null): void {
  if (attachedVideo === next) return;
  if (attachedVideo && video.value) attachedVideo.detach(video.value);
  attachedVideo = next;
  if (attachedVideo && video.value) attachedVideo.attach(video.value);
}

watch(visibleVideo, replaceVideo, { immediate: true });
onMounted(() => {
  if (attachedVideo && video.value) attachedVideo.attach(video.value);
});
onBeforeUnmount(() => {
  if (attachedVideo && video.value) attachedVideo.detach(video.value);
});
</script>

<template>
  <span class="meeting-participant-media" :class="{ 'has-video': visibleVideo }">
    <video ref="video" autoplay playsinline muted />
  </span>
</template>
