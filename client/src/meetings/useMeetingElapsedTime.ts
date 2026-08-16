import { computed, onBeforeUnmount, onMounted, ref, watch } from "vue";
import { formatMeetingElapsedTime } from "./meetingPresentation";

export function useMeetingElapsedTime(startedAt: () => string | null) {
  const fallbackStartedAt = Date.now();
  const now = ref(fallbackStartedAt);
  let timer: number | null = null;

  const elapsedTime = computed(() => formatMeetingElapsedTime(startedAt(), now.value, fallbackStartedAt));

  function refresh(): void {
    now.value = Date.now();
  }

  watch(startedAt, refresh);
  onMounted(() => {
    refresh();
    timer = window.setInterval(refresh, 1_000);
  });
  onBeforeUnmount(() => {
    if (timer !== null) window.clearInterval(timer);
  });

  return elapsedTime;
}
