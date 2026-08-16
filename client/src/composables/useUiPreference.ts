import { ref, watch, type Ref } from "vue";

const UI_PREFERENCE_PREFIX = "katra.ui.v1";

type PreferenceValidator<T> = (value: unknown) => value is T;
type PreferenceScope = "local" | "session";

export function useUiPreference<T>(
  key: string,
  fallback: T,
  isValid: PreferenceValidator<T>,
  scope: PreferenceScope = "session",
): Ref<T> {
  const storageKey = `${UI_PREFERENCE_PREFIX}.${key}`;
  let initialValue = fallback;

  if (typeof window !== "undefined") {
    try {
      const storage = scope === "session" ? window.sessionStorage : window.localStorage;
      const storedValue = storage.getItem(storageKey);

      if (storedValue !== null) {
        const parsedValue: unknown = JSON.parse(storedValue);
        if (isValid(parsedValue)) initialValue = parsedValue;
      }
    } catch {
      // A malformed or unavailable preference must never block the workspace.
    }
  }

  const preference = ref(initialValue) as Ref<T>;

  watch(
    preference,
    (value) => {
      try {
        const storage = scope === "session" ? window.sessionStorage : window.localStorage;
        storage.setItem(storageKey, JSON.stringify(value));
      } catch {
        // Keep the in-memory interaction working when storage is unavailable.
      }
    },
    { deep: true, flush: "sync" },
  );

  return preference;
}

export function isFiniteNumber(value: unknown): value is number {
  return typeof value === "number" && Number.isFinite(value);
}
