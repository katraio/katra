<script setup lang="ts">
import { Check, ChevronDown } from "@lucide/vue";
import { computed, nextTick, onBeforeUnmount, ref, watch } from "vue";

export type KatraSelectOption = {
  value: string;
  label: string;
  description?: string;
  disabled?: boolean;
};

const props = withDefaults(defineProps<{
  modelValue: string;
  options: KatraSelectOption[];
  label: string;
  placeholder?: string;
  compact?: boolean;
  large?: boolean;
}>(), {
  placeholder: "Select an option",
  compact: false,
  large: false,
});

const emit = defineEmits<{
  "update:modelValue": [value: string];
  change: [value: string];
}>();

const trigger = ref<HTMLButtonElement | null>(null);
const menu = ref<HTMLElement | null>(null);
const isOpen = ref(false);
const activeIndex = ref(0);
const menuPosition = ref({ top: 0, left: 0, width: 180, maxHeight: 280 });

const selectedOption = computed(() => props.options.find((option) => option.value === props.modelValue));
const enabledIndexes = computed(() => props.options.map((option, index) => option.disabled ? -1 : index).filter((index) => index >= 0));
const menuStyle = computed(() => ({
  top: `${menuPosition.value.top}px`,
  left: `${menuPosition.value.left}px`,
  width: `${menuPosition.value.width}px`,
  maxHeight: `${menuPosition.value.maxHeight}px`,
}));

function updateMenuPosition() {
  const rect = trigger.value?.getBoundingClientRect();
  if (!rect) return;
  const gap = 6;
  const viewportPadding = 10;
  const desiredHeight = Math.min(280, props.options.length * (props.compact ? 34 : 42) + 12);
  const spaceBelow = window.innerHeight - rect.bottom - viewportPadding;
  const spaceAbove = rect.top - viewportPadding;
  const openUpward = spaceBelow < Math.min(desiredHeight, 180) && spaceAbove > spaceBelow;
  const maxHeight = Math.max(96, Math.min(desiredHeight, openUpward ? spaceAbove - gap : spaceBelow - gap));
  const width = Math.max(rect.width, 168);
  const left = Math.min(Math.max(viewportPadding, rect.left), window.innerWidth - width - viewportPadding);
  menuPosition.value = {
    top: openUpward ? Math.max(viewportPadding, rect.top - maxHeight - gap) : rect.bottom + gap,
    left,
    width,
    maxHeight,
  };
}

function focusActiveOption() {
  nextTick(() => menu.value?.querySelector<HTMLElement>(`[data-option-index="${activeIndex.value}"]`)?.focus());
}

function openMenu() {
  if (isOpen.value || !props.options.length) return;
  const selectedIndex = props.options.findIndex((option) => option.value === props.modelValue && !option.disabled);
  activeIndex.value = selectedIndex >= 0 ? selectedIndex : (enabledIndexes.value[0] ?? 0);
  updateMenuPosition();
  isOpen.value = true;
  focusActiveOption();
}

function closeMenu(returnFocus = false) {
  if (!isOpen.value) return;
  isOpen.value = false;
  if (returnFocus) nextTick(() => trigger.value?.focus());
}

function chooseOption(option: KatraSelectOption) {
  if (option.disabled) return;
  emit("update:modelValue", option.value);
  emit("change", option.value);
  closeMenu(true);
}

function moveActive(direction: 1 | -1) {
  if (!enabledIndexes.value.length) return;
  const currentPosition = enabledIndexes.value.indexOf(activeIndex.value);
  const nextPosition = currentPosition < 0
    ? 0
    : (currentPosition + direction + enabledIndexes.value.length) % enabledIndexes.value.length;
  activeIndex.value = enabledIndexes.value[nextPosition];
  focusActiveOption();
}

function onTriggerKeydown(event: KeyboardEvent) {
  if (["ArrowDown", "ArrowUp", "Enter", " "].includes(event.key)) {
    event.preventDefault();
    openMenu();
    if (event.key === "ArrowUp") moveActive(-1);
  }
}

function onMenuKeydown(event: KeyboardEvent) {
  if (event.key === "ArrowDown" || event.key === "ArrowUp") {
    event.preventDefault();
    moveActive(event.key === "ArrowDown" ? 1 : -1);
  } else if (event.key === "Home" || event.key === "End") {
    event.preventDefault();
    activeIndex.value = event.key === "Home" ? (enabledIndexes.value[0] ?? 0) : (enabledIndexes.value.at(-1) ?? 0);
    focusActiveOption();
  } else if (event.key === "Enter" || event.key === " ") {
    event.preventDefault();
    const option = props.options[activeIndex.value];
    if (option) chooseOption(option);
  } else if (event.key === "Escape") {
    event.preventDefault();
    closeMenu(true);
  } else if (event.key === "Tab") {
    closeMenu();
  }
}

function onDocumentPointerDown(event: PointerEvent) {
  const target = event.target as Node;
  if (!trigger.value?.contains(target) && !menu.value?.contains(target)) closeMenu();
}

function onViewportChange() {
  if (isOpen.value) updateMenuPosition();
}

watch(isOpen, (open) => {
  if (open) {
    document.addEventListener("pointerdown", onDocumentPointerDown);
    window.addEventListener("resize", onViewportChange);
    window.addEventListener("scroll", onViewportChange, true);
  } else {
    document.removeEventListener("pointerdown", onDocumentPointerDown);
    window.removeEventListener("resize", onViewportChange);
    window.removeEventListener("scroll", onViewportChange, true);
  }
});

onBeforeUnmount(() => {
  document.removeEventListener("pointerdown", onDocumentPointerDown);
  window.removeEventListener("resize", onViewportChange);
  window.removeEventListener("scroll", onViewportChange, true);
});
</script>

<template>
  <div class="katra-select" :class="{ 'katra-select--compact': compact, 'katra-select--large': large, 'is-open': isOpen }">
    <button
      ref="trigger"
      type="button"
      class="katra-select-trigger"
      :aria-label="label"
      :aria-expanded="isOpen"
      aria-haspopup="listbox"
      @click="isOpen ? closeMenu() : openMenu()"
      @keydown="onTriggerKeydown"
    >
      <span>{{ selectedOption?.label ?? placeholder }}</span>
      <ChevronDown :size="14" aria-hidden="true" />
    </button>

    <Teleport to="body">
      <div
        v-if="isOpen"
        ref="menu"
        class="katra-select-menu"
        :class="{ 'katra-select-menu--compact': compact }"
        :style="menuStyle"
        role="listbox"
        :aria-label="label"
        @keydown="onMenuKeydown"
      >
        <button
          v-for="(option, index) in options"
          :key="option.value"
          type="button"
          class="katra-select-option"
          :class="{ 'is-selected': option.value === modelValue, 'is-active': index === activeIndex }"
          :disabled="option.disabled"
          :tabindex="index === activeIndex ? 0 : -1"
          :data-option-index="index"
          role="option"
          :aria-selected="option.value === modelValue"
          @pointerenter="!option.disabled && (activeIndex = index)"
          @click="chooseOption(option)"
        >
          <span><strong>{{ option.label }}</strong><small v-if="option.description">{{ option.description }}</small></span>
          <Check v-if="option.value === modelValue" :size="14" aria-hidden="true" />
        </button>
      </div>
    </Teleport>
  </div>
</template>

<style scoped>
.katra-select { position: relative; width: 100%; min-width: 0; }
.katra-select-trigger { display: grid; width: 100%; height: 38px; grid-template-columns: minmax(0, 1fr) 18px; align-items: center; gap: 8px; padding: 0 10px 0 11px; border: 0; border-radius: 8px; outline: 0; background: #252c35; color: #e2e6ec; font: inherit; text-align: left; cursor: pointer; }
.katra-select-trigger:hover, .katra-select-trigger:focus-visible, .katra-select.is-open .katra-select-trigger { background: #29313b; box-shadow: 0 0 0 2px rgb(196 154 192 / 18%); }
.katra-select-trigger > span { min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.katra-select-trigger svg { color: #929daa; transition: transform 140ms ease; }
.katra-select.is-open .katra-select-trigger svg { transform: rotate(180deg); }
.katra-select--compact .katra-select-trigger { height: 30px; border-radius: 7px; font-size: 8px; }
.katra-select--large .katra-select-trigger { height: 42px; border-radius: 9px; font-size: 12px; }
.katra-select-menu { position: fixed; z-index: 1000; display: grid; gap: 2px; overflow-y: auto; padding: 6px; border-radius: 10px; background: #252c35; box-shadow: 0 18px 44px rgb(3 5 8 / 46%), 0 0 0 1px rgb(255 255 255 / 5%); }
.katra-select-option { display: grid; width: 100%; min-height: 38px; grid-template-columns: minmax(0, 1fr) 18px; align-items: center; gap: 8px; padding: 7px 9px; border: 0; border-radius: 7px; background: transparent; color: #c9d0d9; text-align: left; cursor: pointer; }
.katra-select-option:hover, .katra-select-option:focus-visible, .katra-select-option.is-active { outline: 0; background: #303844; }
.katra-select-option.is-selected { background: #514651; color: #ebd7e8; }
.katra-select-option > span { display: grid; min-width: 0; gap: 2px; }
.katra-select-option strong { overflow: hidden; color: inherit; font-size: 10px; font-weight: 680; text-overflow: ellipsis; white-space: nowrap; }
.katra-select-option small { overflow: hidden; color: #8f9aa7; font-size: 8px; text-overflow: ellipsis; white-space: nowrap; }
.katra-select-option svg { color: #d4b5d1; }
.katra-select-option:disabled { opacity: .42; cursor: not-allowed; }
.katra-select-menu--compact .katra-select-option { min-height: 30px; padding: 5px 8px; }
.katra-select-menu--compact .katra-select-option strong { font-size: 8px; }
</style>
