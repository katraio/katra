<script setup lang="ts">
import {
  AtSign,
  Archive,
  ArrowUpDown,
  BellOff,
  ChevronDown,
  ChevronRight,
  Circle,
  Copy,
  EllipsisVertical,
  Hash,
  Headphones,
  Inbox,
  Keyboard,
  LockKeyhole,
  LogOut,
  Plus,
  Search,
  Settings,
  SlidersHorizontal,
  Star,
  Trash2,
  UserRound,
  X,
} from "@lucide/vue";
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch, type Component } from "vue";
import type { AuthUser } from "../../api/auth";
import {
  CommunicationRequestError,
  setChannelFavorite,
  setDirectMessageFavorite,
  type CommunicationChannel,
  type CommunicationDirectMessage,
  type CommunicationOrganization,
} from "../../api/communication";
import ChannelBrowserModal from "./ChannelBrowserModal.vue";
import GlobalSearchModal from "./GlobalSearchModal.vue";
import type { GlobalSearchSelection } from "./globalSearch";
import { userInitials } from "../../settings/settingsPresentation";
import DirectMessageCreateDialog from "../messages/DirectMessageCreateDialog.vue";

type ChannelItem = {
  id: string;
  label: string;
  private: boolean;
  favorite: boolean;
  unreadCount: number;
  mentionCount: number;
  liveMeeting: CommunicationChannel["live_meeting"];
};

type DirectMessageItem = {
  id: string;
  label: string;
  initials: string;
  favorite: boolean;
  unreadCount: number;
};

type FavoriteItem =
  | (ChannelItem & { kind: "channel" })
  | (DirectMessageItem & { kind: "direct-message" });

type SidebarGroupId = "favorites" | "channels" | "direct-messages";
type SortMode = "recent" | "alphabetical";

type ChannelContextAction = {
  id: string;
  label: string;
  icon?: Component;
  danger?: boolean;
  submenu?: boolean;
};

const props = defineProps<{
  activeDestination: string;
  open: boolean;
  width: number;
  minWidth: number;
  maxWidth: number;
  user: AuthUser;
  channels: CommunicationChannel[];
  directMessages: CommunicationDirectMessage[];
  organizations: CommunicationOrganization[];
  operatingOrganizationId: string | null;
  communicationStatus: "loading" | "ready" | "unavailable";
  attentionCount: number;
  showServerSettings: boolean;
}>();

const emit = defineEmits<{
  close: [];
  logout: [];
  navigate: [destinationId: string];
  "channel-created": [channel: CommunicationChannel];
  "channel-updated": [channel: CommunicationChannel];
  "direct-message-updated": [directMessage: CommunicationDirectMessage];
  "direct-message-created": [directMessage: CommunicationDirectMessage];
  "open-search-result": [selection: GlobalSearchSelection];
  resize: [width: number];
  "resize-start": [];
  "resize-end": [];
}>();

const primaryNavItems = computed(() => [
  { id: "inbox", label: "Inbox", icon: Inbox, count: props.attentionCount },
]);

const channelItems = computed<ChannelItem[]>(() => props.channels.map((channel) => ({
  id: `channel-${channel.id}`,
  label: channel.name,
  private: channel.visibility === "private",
  favorite: channel.is_favorite,
  unreadCount: channel.unread_count ?? 0,
  mentionCount: channel.mention_count,
  liveMeeting: channel.live_meeting,
})));

const favoriteItems = computed<FavoriteItem[]>(() => channelItems.value
  .filter((channel) => channel.favorite)
  .map((channel) => ({ ...channel, kind: "channel" } as FavoriteItem))
  .concat(
    directMessageItems.value
      .filter((directMessage) => directMessage.favorite)
      .map((directMessage) => ({ ...directMessage, kind: "direct-message" } as FavoriteItem)),
  ));

const channelContextGroups: ChannelContextAction[][] = [
  [
    { id: "copy", label: "Copy", icon: Copy, submenu: true },
    { id: "move", label: "Move to section", submenu: true },
  ],
  [
    { id: "mute", label: "Mute channel", icon: BellOff },
    { id: "favorite", label: "Add to Favorites", icon: Star },
  ],
  [
    { id: "leave", label: "Leave channel", icon: LogOut, danger: true },
    { id: "archive", label: "Archive channel", icon: Archive },
    { id: "delete", label: "Delete channel", icon: Trash2, danger: true },
  ],
];

const directMessageItems = computed<DirectMessageItem[]>(() => props.directMessages.map((directMessage) => {
  const participants = directMessage.participants.filter((participant) => participant.id !== props.user.id);
  const label = participants.map((participant) => participant.name).join(", ") || "Direct Message";
  const initials = participants
    .slice(0, 2)
    .map((participant) => participant.name.split(/\s+/).map((part) => part[0] ?? "").slice(0, 2).join(""))
    .join("+")
    .toUpperCase();

  return {
    id: `dm-${directMessage.id}`,
    label,
    initials: initials || "DM",
    favorite: directMessage.is_favorite,
    unreadCount: directMessage.unread_count ?? 0,
  };
}));

const sidebarGroupLabels: Record<SidebarGroupId, string> = {
  favorites: "Favorites",
  channels: "Channels",
  "direct-messages": "Direct Messages",
};

const profileMenuItems = [
  { id: "profile", label: "Profile", icon: UserRound },
  { id: "preferences", label: "Preferences", icon: SlidersHorizontal },
  { id: "shortcuts", label: "Keyboard shortcuts", icon: Keyboard },
  { id: "sign-out", label: "Sign out", icon: LogOut, danger: true },
];

const activeNav = computed({
  get: () => props.activeDestination,
  set: (destinationId: string) => emit("navigate", destinationId),
});
const profileInitials = computed(() => userInitials(props.user));
const favoritesExpanded = ref(true);
const channelsExpanded = ref(true);
const directMessagesExpanded = ref(true);
const searchQuery = ref("");
const globalSearchOpen = ref(false);
const browseChannelsOpen = ref(false);
const directMessageCreateOpen = ref(false);
const activeGroupHeaderMenu = ref<SidebarGroupId | null>(null);
const groupHeaderSortOpen = ref(false);
const groupSortModes = ref<Record<SidebarGroupId, SortMode>>({
  favorites: "alphabetical",
  channels: "alphabetical",
  "direct-messages": "alphabetical",
});
const channelBrowseTrigger = ref<HTMLButtonElement | null>(null);
const directMessageCreateTrigger = ref<HTMLButtonElement | null>(null);
const globalSearchTrigger = ref<HTMLButtonElement | null>(null);
const groupHeaderMenuElement = ref<HTMLElement | null>(null);
const groupHeaderMenuPosition = ref({ x: 0, y: 0 });
const groupHeaderSortTrigger = ref<HTMLButtonElement | null>(null);
const firstGroupSortItem = ref<HTMLButtonElement | null>(null);
const profileArea = ref<HTMLElement | null>(null);
const profileTrigger = ref<HTMLButtonElement | null>(null);
const firstProfileMenuItem = ref<HTMLButtonElement | null>(null);
const profileMenuOpen = ref(false);
const channelContextMenu = ref<ChannelItem | null>(null);
const channelContextMenuElement = ref<HTMLElement | null>(null);
const channelContextMenuPosition = ref({ x: 0, y: 0 });
const channelContextSubmenu = ref<"copy" | "move" | null>(null);
const favoritePendingId = ref<string | null>(null);
const channelActionError = ref("");
let channelContextTrigger: HTMLButtonElement | null = null;
let groupHeaderMenuTrigger: HTMLButtonElement | null = null;
let resizing = false;
let resizeHandle: HTMLElement | null = null;
let resizePointerId: number | null = null;

function sortedGroupItems<T extends { label: string }>(items: T[], mode: SortMode) {
  if (mode === "recent") {
    return items;
  }

  return [...items].sort((left, right) => left.label.localeCompare(right.label));
}

const visibleFavoriteItems = computed(() => sortedGroupItems(favoriteItems.value, groupSortModes.value.favorites));
const visibleChannelItems = computed(() => {
  const favoriteIds = new Set(favoriteItems.value.map((item) => item.id));
  return sortedGroupItems(
    channelItems.value.filter((channel) => !favoriteIds.has(channel.id)),
    groupSortModes.value.channels,
  );
});
const visibleDirectMessageItems = computed(() =>
  sortedGroupItems(
    directMessageItems.value.filter((directMessage) => !directMessage.favorite),
    groupSortModes.value["direct-messages"],
  ),
);
const activeGroupLabel = computed(() =>
  activeGroupHeaderMenu.value ? sidebarGroupLabels[activeGroupHeaderMenu.value] : "",
);
const activeGroupSortMode = computed<SortMode>(() =>
  activeGroupHeaderMenu.value
    ? groupSortModes.value[activeGroupHeaderMenu.value]
    : "alphabetical",
);

function toggleChannels() {
  channelsExpanded.value = !channelsExpanded.value;
}

function toggleFavorites() {
  favoritesExpanded.value = !favoritesExpanded.value;
}

function toggleDirectMessages() {
  directMessagesExpanded.value = !directMessagesExpanded.value;
}

function handleSectionToggleKeydown(event: KeyboardEvent, toggle: () => void) {
  if (event.key !== "Enter" && event.key !== " ") {
    return;
  }

  event.preventDefault();
  toggle();
}

function closeGroupHeaderMenu(returnFocus = false) {
  if (!activeGroupHeaderMenu.value) {
    return;
  }

  activeGroupHeaderMenu.value = null;
  groupHeaderSortOpen.value = false;

  if (returnFocus) {
    nextTick(() => groupHeaderMenuTrigger?.focus());
  }
}

async function toggleGroupHeaderMenu(groupId: SidebarGroupId, event: MouseEvent) {
  closeChannelContextMenu();
  closeProfileMenu();

  if (activeGroupHeaderMenu.value === groupId) {
    closeGroupHeaderMenu();
    return;
  }

  groupHeaderMenuTrigger = event.currentTarget as HTMLButtonElement;
  activeGroupHeaderMenu.value = groupId;
  groupHeaderSortOpen.value = false;

  const heading = groupHeaderMenuTrigger.closest<HTMLElement>(".nav-section-heading");
  const bounds = heading?.getBoundingClientRect();

  if (bounds) {
    groupHeaderMenuPosition.value = { x: bounds.left, y: bounds.bottom + 4 };
  }

  await nextTick();

  const menu = groupHeaderMenuElement.value;

  if (menu) {
    const menuBounds = menu.getBoundingClientRect();
    groupHeaderMenuPosition.value = {
      x: Math.max(10, Math.min(groupHeaderMenuPosition.value.x, window.innerWidth - menuBounds.width - 10)),
      y: Math.max(10, Math.min(groupHeaderMenuPosition.value.y, window.innerHeight - menuBounds.height - 10)),
    };
  }

  if (event.detail === 0) {
    groupHeaderSortTrigger.value?.focus();
  }
}

function openBrowseChannels() {
  closeDirectMessageCreate(false);
  closeChannelContextMenu();
  closeGroupHeaderMenu();
  closeProfileMenu();
  browseChannelsOpen.value = true;
}

function openDirectMessageCreate() {
  closeBrowseChannels(false);
  closeChannelContextMenu();
  closeGroupHeaderMenu();
  closeProfileMenu();
  directMessageCreateOpen.value = true;
}

function closeDirectMessageCreate(returnFocus = true) {
  if (!directMessageCreateOpen.value) return;
  directMessageCreateOpen.value = false;

  if (returnFocus) {
    nextTick(() => directMessageCreateTrigger.value?.focus());
  }
}

function handleDirectMessageCreated(directMessage: CommunicationDirectMessage) {
  emit("direct-message-created", directMessage);
  closeDirectMessageCreate();
}

function openGlobalSearch() {
  closeDirectMessageCreate(false);
  closeBrowseChannels(false);
  closeChannelContextMenu();
  closeGroupHeaderMenu();
  closeProfileMenu();
  globalSearchOpen.value = true;
}

function closeGlobalSearch(returnFocus = true) {
  if (!globalSearchOpen.value) {
    return;
  }

  globalSearchOpen.value = false;

  if (returnFocus) {
    nextTick(() => globalSearchTrigger.value?.focus());
  }
}

function selectGlobalSearchResult(selection: GlobalSearchSelection) {
  if (selection.focus) {
    emit("open-search-result", selection);
  } else {
    activeNav.value = selection.destinationId;
  }
  closeGlobalSearch();
}

function closeBrowseChannels(returnFocus = true) {
  browseChannelsOpen.value = false;

  if (returnFocus) {
    nextTick(() => channelBrowseTrigger.value?.focus());
  }
}

function selectBrowsedChannel(channelId: string) {
  const channel = channelItems.value.find(
    (item) => item.id === channelId || item.id === `channel-${channelId}` || item.label === channelId,
  );

  if (channel) {
    activeNav.value = channel.id;
  }

  closeBrowseChannels();
}

function handleChannelCreated(channel: CommunicationChannel) {
  emit("channel-created", channel);
  closeBrowseChannels();
}

async function openGroupSortMenu(focusFirst = false) {
  groupHeaderSortOpen.value = true;

  if (focusFirst) {
    await nextTick();
    firstGroupSortItem.value?.focus();
  }
}

function selectGroupSort(mode: SortMode) {
  if (!activeGroupHeaderMenu.value) {
    return;
  }

  groupSortModes.value[activeGroupHeaderMenu.value] = mode;
  closeGroupHeaderMenu(true);
}

function handleGroupHeaderSortTriggerKeydown(event: KeyboardEvent) {
  if (!["ArrowRight", "ArrowDown", "Enter", " "].includes(event.key)) {
    return;
  }

  event.preventDefault();
  void openGroupSortMenu(true);
}

function handleGroupSortMenuKeydown(event: KeyboardEvent) {
  const menu = event.currentTarget as HTMLElement;
  const items = Array.from(menu.querySelectorAll<HTMLButtonElement>("[role='menuitemradio']"));
  const currentIndex = items.indexOf(document.activeElement as HTMLButtonElement);

  if (event.key === "ArrowLeft") {
    event.preventDefault();
    groupHeaderSortOpen.value = false;
    nextTick(() => groupHeaderSortTrigger.value?.focus());
    return;
  }

  if (event.key === "Escape") {
    event.preventDefault();
    closeGroupHeaderMenu(true);
    return;
  }

  if (!["ArrowDown", "ArrowUp", "Home", "End"].includes(event.key)) {
    return;
  }

  event.preventDefault();

  if (event.key === "Home") {
    items[0]?.focus();
    return;
  }

  if (event.key === "End") {
    items.at(-1)?.focus();
    return;
  }

  const direction = event.key === "ArrowDown" ? 1 : -1;
  const nextIndex = currentIndex < 0 ? 0 : (currentIndex + direction + items.length) % items.length;
  items[nextIndex]?.focus();
}

function closeChannelContextMenu(returnFocus = false) {
  if (!channelContextMenu.value) {
    return;
  }

  channelContextMenu.value = null;
  channelContextSubmenu.value = null;
  channelActionError.value = "";

  if (returnFocus) {
    nextTick(() => channelContextTrigger?.focus());
  }
}

async function positionChannelContextMenu(x: number, y: number, focusFirst = false) {
  channelContextMenuPosition.value = { x, y };
  await nextTick();

  const menu = channelContextMenuElement.value;

  if (!menu) {
    return;
  }

  const margin = 10;
  const bounds = menu.getBoundingClientRect();
  channelContextMenuPosition.value = {
    x: Math.max(margin, Math.min(x, window.innerWidth - bounds.width - margin)),
    y: Math.max(margin, Math.min(y, window.innerHeight - bounds.height - margin)),
  };

  if (focusFirst) {
    await nextTick();
    menu.querySelector<HTMLButtonElement>("[role='menuitem']")?.focus();
  }
}

async function openChannelContextMenu(event: MouseEvent, item: ChannelItem) {
  closeGroupHeaderMenu();
  closeProfileMenu();
  channelContextTrigger = event.currentTarget as HTMLButtonElement;
  channelContextMenu.value = item;
  channelContextSubmenu.value = null;
  channelActionError.value = "";
  await positionChannelContextMenu(event.clientX, event.clientY);
}

async function handleChannelRowKeydown(event: KeyboardEvent, item: ChannelItem) {
  if (event.key !== "ContextMenu" && !(event.shiftKey && event.key === "F10")) {
    return;
  }

  event.preventDefault();
  closeGroupHeaderMenu();
  closeProfileMenu();
  channelContextTrigger = event.currentTarget as HTMLButtonElement;
  channelContextMenu.value = item;
  channelContextSubmenu.value = null;

  const bounds = channelContextTrigger.getBoundingClientRect();
  await positionChannelContextMenu(bounds.right - 8, bounds.top + 8, true);
}

function selectNavItem(id: string) {
  closeChannelContextMenu();
  closeGroupHeaderMenu();
  activeNav.value = id;
}

function openFavoriteContextMenu(event: MouseEvent, item: FavoriteItem) {
  if (item.kind === "channel") {
    event.preventDefault();
    void openChannelContextMenu(event, item);
  }
}

function handleFavoriteRowKeydown(event: KeyboardEvent, item: FavoriteItem) {
  if (item.kind === "channel") {
    void handleChannelRowKeydown(event, item);
  }
}

function channelContextActionLabel(action: ChannelContextAction) {
  if (action.id !== "favorite" || !channelContextMenu.value) {
    return action.label;
  }

  return channelContextMenu.value.favorite ? "Remove from Favorites" : "Add to Favorites";
}

async function toggleChannelFavorite(item: ChannelItem): Promise<void> {
  if (favoritePendingId.value) {
    return;
  }

  const channelId = item.id.slice("channel-".length);
  favoritePendingId.value = item.id;
  channelActionError.value = "";

  try {
    const updated = await setChannelFavorite(channelId, !item.favorite);
    emit("channel-updated", updated);
    closeChannelContextMenu();
  } catch (error) {
    channelActionError.value = error instanceof CommunicationRequestError
      ? error.message
      : "Katra Server is unavailable. The favorite was not changed.";
  } finally {
    favoritePendingId.value = null;
  }
}

async function toggleDirectMessageFavorite(item: DirectMessageItem): Promise<void> {
  if (favoritePendingId.value) {
    return;
  }

  const directMessageId = item.id.slice("dm-".length);
  favoritePendingId.value = item.id;
  channelActionError.value = "";

  try {
    const updated = await setDirectMessageFavorite(directMessageId, !item.favorite);
    emit("direct-message-updated", updated);
  } catch (error) {
    channelActionError.value = error instanceof CommunicationRequestError
      ? error.message
      : "Katra Server is unavailable. The favorite was not changed.";
  } finally {
    favoritePendingId.value = null;
  }
}

function toggleFavoriteItem(item: FavoriteItem): Promise<void> {
  return item.kind === "channel"
    ? toggleChannelFavorite(item)
    : toggleDirectMessageFavorite(item);
}

async function openChannelContextSubmenu(actionId: "copy" | "move", focusFirst = false) {
  channelContextSubmenu.value = actionId;

  if (focusFirst) {
    await nextTick();
    channelContextMenuElement.value
      ?.querySelector<HTMLButtonElement>(`[data-context-submenu='${actionId}'] [role='menuitem']`)
      ?.focus();
  }
}

function closeChannelContextSubmenu(returnFocus = false) {
  const submenu = channelContextSubmenu.value;
  channelContextSubmenu.value = null;

  if (returnFocus && submenu) {
    nextTick(() =>
      channelContextMenuElement.value
        ?.querySelector<HTMLButtonElement>(`[data-context-action='${submenu}']`)
        ?.focus(),
    );
  }
}

function handleChannelContextPointerEnter(action: ChannelContextAction) {
  if (action.id === "copy" || action.id === "move") {
    void openChannelContextSubmenu(action.id);
    return;
  }

  closeChannelContextSubmenu();
}

async function activateChannelContextAction(action: ChannelContextAction, event: MouseEvent) {
  if (action.submenu) {
    await openChannelContextSubmenu(action.id as "copy" | "move", event.detail === 0);
    return;
  }

  if (action.id === "favorite" && channelContextMenu.value) {
    await toggleChannelFavorite(channelContextMenu.value);
    return;
  }

  closeChannelContextMenu(true);
}

async function activateChannelContextSubmenuItem(actionId: "copy-name" | "copy-id" | "new-section") {
  if (channelContextMenu.value && actionId !== "new-section") {
    const value =
      actionId === "copy-name" ? channelContextMenu.value.label : channelContextMenu.value.id;

    try {
      await navigator.clipboard.writeText(value);
    } catch {
      // Clipboard access can be unavailable in preview environments.
    }
  }

  closeChannelContextMenu(true);
}

function handleChannelContextSubmenuKeydown(event: KeyboardEvent) {
  const menu = event.currentTarget as HTMLElement;
  const items = Array.from(menu.querySelectorAll<HTMLButtonElement>("[role='menuitem']"));
  const currentIndex = items.indexOf(document.activeElement as HTMLButtonElement);

  if (event.key === "ArrowLeft") {
    event.preventDefault();
    event.stopPropagation();
    closeChannelContextSubmenu(true);
    return;
  }

  if (event.key === "Escape") {
    event.preventDefault();
    event.stopPropagation();
    closeChannelContextMenu(true);
    return;
  }

  if (!["ArrowDown", "ArrowUp", "Home", "End"].includes(event.key)) {
    return;
  }

  event.preventDefault();
  event.stopPropagation();

  if (event.key === "Home") {
    items[0]?.focus();
    return;
  }

  if (event.key === "End") {
    items.at(-1)?.focus();
    return;
  }

  const direction = event.key === "ArrowDown" ? 1 : -1;
  const nextIndex = currentIndex < 0 ? 0 : (currentIndex + direction + items.length) % items.length;
  items[nextIndex]?.focus();
}

function handleChannelContextMenuKeydown(event: KeyboardEvent) {
  const menu = channelContextMenuElement.value;

  if (!menu) {
    return;
  }

  const items = Array.from(menu.querySelectorAll<HTMLButtonElement>("[data-context-level='main']"));
  const currentIndex = items.indexOf(document.activeElement as HTMLButtonElement);

  if (event.key === "ArrowRight") {
    const currentAction = (document.activeElement as HTMLButtonElement | null)?.dataset.contextAction;

    if (currentAction === "copy" || currentAction === "move") {
      event.preventDefault();
      void openChannelContextSubmenu(currentAction, true);
    }

    return;
  }

  if (event.key === "Escape") {
    event.preventDefault();
    closeChannelContextMenu(true);
    return;
  }

  if (!["ArrowDown", "ArrowUp", "Home", "End"].includes(event.key)) {
    return;
  }

  event.preventDefault();

  if (event.key === "Home") {
    items[0]?.focus();
    return;
  }

  if (event.key === "End") {
    items.at(-1)?.focus();
    return;
  }

  const direction = event.key === "ArrowDown" ? 1 : -1;
  const nextIndex = currentIndex < 0 ? 0 : (currentIndex + direction + items.length) % items.length;
  items[nextIndex]?.focus();
}

async function toggleProfileMenu(event: MouseEvent) {
  closeChannelContextMenu();
  closeGroupHeaderMenu();
  const openedWithKeyboard = event.detail === 0;
  profileMenuOpen.value = !profileMenuOpen.value;

  if (profileMenuOpen.value && openedWithKeyboard) {
    await nextTick();
    firstProfileMenuItem.value?.focus();
  }
}

async function handleProfileTriggerKeydown(event: KeyboardEvent) {
  if (event.key !== "Enter" && event.key !== " ") {
    return;
  }

  event.preventDefault();
  profileMenuOpen.value = !profileMenuOpen.value;

  if (profileMenuOpen.value) {
    await nextTick();
    firstProfileMenuItem.value?.focus();
  }
}

function closeProfileMenu(returnFocus = false) {
  if (!profileMenuOpen.value) {
    return;
  }

  profileMenuOpen.value = false;

  if (returnFocus) {
    nextTick(() => profileTrigger.value?.focus());
  }
}

function handleSettingsClick() {
  closeChannelContextMenu();
  closeGroupHeaderMenu();
  closeProfileMenu();
  activeNav.value = "server-settings";
}

function handleProfileMenuItem(itemId: string) {
  closeProfileMenu(itemId !== "sign-out");

  if (itemId === "sign-out") {
    emit("logout");
    return;
  }

  if (itemId === "profile") {
    activeNav.value = "profile";
  }
}

function handleDocumentPointerDown(event: PointerEvent) {
  if (channelContextMenu.value && !channelContextMenuElement.value?.contains(event.target as Node)) {
    closeChannelContextMenu();
  }

  if (profileMenuOpen.value && !profileArea.value?.contains(event.target as Node)) {
    closeProfileMenu();
  }

  if (
    activeGroupHeaderMenu.value &&
    !groupHeaderMenuTrigger?.closest(".nav-section")?.contains(event.target as Node) &&
    !groupHeaderMenuElement.value?.contains(event.target as Node)
  ) {
    closeGroupHeaderMenu();
  }
}

function handleDocumentKeydown(event: KeyboardEvent) {
  if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === "k") {
    event.preventDefault();
    openGlobalSearch();
    return;
  }

  if (event.key === "Escape" && globalSearchOpen.value) {
    event.preventDefault();
    closeGlobalSearch();
    return;
  }

  if (event.key === "Escape" && browseChannelsOpen.value) {
    event.preventDefault();
    closeBrowseChannels();
    return;
  }

  if (event.key === "Escape" && channelContextMenu.value) {
    event.preventDefault();
    closeChannelContextMenu(true);
    return;
  }

  if (event.key === "Escape" && profileMenuOpen.value) {
    event.preventDefault();
    closeProfileMenu(true);
    return;
  }

  if (event.key === "Escape" && activeGroupHeaderMenu.value) {
    event.preventDefault();
    closeGroupHeaderMenu(true);
  }
}

function handleWindowResize() {
  closeChannelContextMenu();
  closeGroupHeaderMenu();
}

function handleSidebarScroll() {
  closeChannelContextMenu();
  closeGroupHeaderMenu();
}

onMounted(() => {
  document.addEventListener("pointerdown", handleDocumentPointerDown);
  document.addEventListener("keydown", handleDocumentKeydown);
  window.addEventListener("resize", handleWindowResize);
});

watch(
  () => props.open,
  (open) => {
    if (!open) {
      closeChannelContextMenu();
      closeGroupHeaderMenu();
      closeProfileMenu();
      closeBrowseChannels();
      closeGlobalSearch(false);
    }
  },
);

function clampWidth(width: number) {
  return Math.min(props.maxWidth, Math.max(props.minWidth, width));
}

function resizeSidebar(event: PointerEvent) {
  if (!resizing) {
    return;
  }

  emit("resize", clampWidth(event.clientX));
}

function startSidebarResize(event: PointerEvent) {
  if (window.matchMedia("(max-width: 900px)").matches || (event.pointerType === "mouse" && event.button !== 0)) {
    return;
  }

  event.preventDefault();

  const handle = event.currentTarget as HTMLElement;
  handle.setPointerCapture(event.pointerId);
  resizing = true;
  resizeHandle = handle;
  resizePointerId = event.pointerId;
  window.addEventListener("pointermove", resizeSidebar);
  window.addEventListener("pointerup", stopSidebarResize);
  window.addEventListener("pointercancel", stopSidebarResize);
  emit("resize-start");
  resizeSidebar(event);
}

function stopSidebarResize(event: PointerEvent) {
  if (!resizing) {
    return;
  }

  if (resizeHandle && resizePointerId !== null && resizeHandle.hasPointerCapture(resizePointerId)) {
    resizeHandle.releasePointerCapture(resizePointerId);
  }

  window.removeEventListener("pointermove", resizeSidebar);
  window.removeEventListener("pointerup", stopSidebarResize);
  window.removeEventListener("pointercancel", stopSidebarResize);
  resizing = false;
  resizeHandle = null;
  resizePointerId = null;
  emit("resize-end");
}

onBeforeUnmount(() => {
  document.removeEventListener("pointerdown", handleDocumentPointerDown);
  document.removeEventListener("keydown", handleDocumentKeydown);
  window.removeEventListener("resize", handleWindowResize);
  window.removeEventListener("pointermove", resizeSidebar);
  window.removeEventListener("pointerup", stopSidebarResize);
  window.removeEventListener("pointercancel", stopSidebarResize);
});

function resizeSidebarWithKeyboard(event: KeyboardEvent) {
  const increments: Record<string, number> = {
    ArrowLeft: -12,
    ArrowRight: 12,
  };

  if (event.key === "Home") {
    event.preventDefault();
    emit("resize", props.minWidth);
    return;
  }

  if (event.key === "End") {
    event.preventDefault();
    emit("resize", props.maxWidth);
    return;
  }

  const increment = increments[event.key];
  if (increment === undefined) {
    return;
  }

  event.preventDefault();
  emit("resize", clampWidth(props.width + increment));
}
</script>

<template>
  <aside class="app-sidebar" :class="{ 'is-open': open }" aria-label="Primary navigation">
    <div class="sidebar-upper" @scroll="handleSidebarScroll">
      <div class="brand-row">
        <img class="brand-wordmark" src="/brand/katra-logo.svg" alt="Katra" />
        <button class="mobile-close" type="button" aria-label="Close navigation" @click="$emit('close')">
          <X :size="18" :stroke-width="1.8" aria-hidden="true" />
        </button>
      </div>

      <button
        ref="globalSearchTrigger"
        class="search-shell search-trigger"
        type="button"
        aria-label="Search Katra"
        aria-haspopup="dialog"
        :aria-expanded="globalSearchOpen"
        @click="openGlobalSearch"
      >
        <Search :size="18" :stroke-width="1.8" aria-hidden="true" />
        <span>Search</span>
        <kbd>⌘K</kbd>
      </button>

      <nav class="nav-shell" aria-label="Workspace navigation">
        <button
          v-for="item in primaryNavItems"
          :key="item.id"
          class="nav-row"
          :class="{ 'nav-row--active': activeNav === item.id }"
          type="button"
          :aria-current="activeNav === item.id ? 'page' : undefined"
          @click="activeNav = item.id"
        >
          <component :is="item.icon" class="nav-icon" :size="18" :stroke-width="1.8" aria-hidden="true" />
          <span class="nav-label">{{ item.label }}</span>
          <span
            v-if="item.count"
            class="nav-count"
            :aria-label="`${item.count} ${item.count === 1 ? 'item' : 'items'}`"
          >{{ item.count }}</span>
        </button>

        <section v-if="visibleFavoriteItems.length > 0" class="nav-section" aria-labelledby="favorites-heading">
          <h2
            class="nav-section-heading"
            :class="{ 'nav-section-heading--actions-open': activeGroupHeaderMenu === 'favorites' }"
          >
            <button
              id="favorites-heading"
              class="nav-section-toggle"
              type="button"
              aria-controls="favorites-list"
              :aria-expanded="favoritesExpanded"
              @click="toggleFavorites"
              @keydown="handleSectionToggleKeydown($event, toggleFavorites)"
            >
              <span>Favorites</span>
              <ChevronDown
                class="section-chevron"
                :class="{ 'is-expanded': favoritesExpanded }"
                :size="14"
                :stroke-width="1.8"
                aria-hidden="true"
              />
            </button>

            <span class="nav-section-actions">
              <button
                class="nav-section-action"
                :class="{ 'is-active': activeGroupHeaderMenu === 'favorites' }"
                type="button"
                aria-label="Favorites options"
                aria-haspopup="menu"
                :aria-expanded="activeGroupHeaderMenu === 'favorites'"
                aria-controls="group-header-menu"
                @click.stop="toggleGroupHeaderMenu('favorites', $event)"
              >
                <EllipsisVertical :size="17" :stroke-width="2.2" aria-hidden="true" />
              </button>
            </span>
          </h2>
          <div v-show="favoritesExpanded" id="favorites-list" class="nav-section-list">
            <div
              v-for="item in visibleFavoriteItems"
              :key="item.id"
              class="nav-channel-entry"
            >
              <button
                class="nav-row nav-row--compact"
                :class="{
                  'nav-row--active': activeNav === item.id,
                  'nav-row--context': channelContextMenu?.id === item.id,
                }"
                type="button"
                :aria-current="activeNav === item.id ? 'page' : undefined"
                @click="selectNavItem(item.id)"
                @contextmenu.prevent="openFavoriteContextMenu($event, item)"
                @keydown="handleFavoriteRowKeydown($event, item)"
              >
                <span v-if="item.kind === 'direct-message'" class="dm-avatar-shell dm-avatar-initials" aria-hidden="true">{{ item.initials }}</span>
                <Hash v-else class="nav-icon" :size="16" :stroke-width="1.8" aria-hidden="true" />
                <span class="nav-label">{{ item.label }}</span>
                <span v-if="item.kind === 'channel' && item.liveMeeting" class="nav-live-meeting" :aria-label="`Active meeting in ${item.label}`" :title="`${item.liveMeeting.organizer.name} started ${item.liveMeeting.title}`">
                  <Headphones :size="11" :stroke-width="2.1" aria-hidden="true" /><span>Live</span>
                </span>
                <span v-if="item.kind === 'channel' && item.mentionCount" class="nav-mention-count" :aria-label="`${item.mentionCount} unread ${item.mentionCount === 1 ? 'mention' : 'mentions'}`">
                  <AtSign :size="11" :stroke-width="2.2" aria-hidden="true" />{{ item.mentionCount }}
                </span>
                <span v-if="item.unreadCount" class="nav-count" :aria-label="`${item.unreadCount} unread`">{{ item.unreadCount }}</span>
              </button>
              <button
                class="nav-channel-favorite is-favorite"
                type="button"
                :disabled="favoritePendingId === item.id"
                :aria-label="`Remove ${item.label} from Favorites`"
                @click.stop="toggleFavoriteItem(item)"
              >
                <Star :size="14" :stroke-width="1.8" fill="currentColor" aria-hidden="true" />
              </button>
            </div>
          </div>
        </section>

        <section class="nav-section nav-section--channels" aria-labelledby="channels-heading">
          <h2
            class="nav-section-heading"
            :class="{ 'nav-section-heading--actions-open': activeGroupHeaderMenu === 'channels' }"
          >
            <button
              id="channels-heading"
              class="nav-section-toggle"
              type="button"
              aria-controls="channels-list"
              :aria-expanded="channelsExpanded"
              @click="toggleChannels"
              @keydown="handleSectionToggleKeydown($event, toggleChannels)"
            >
              <span>Channels</span>
              <ChevronDown
                class="section-chevron"
                :class="{ 'is-expanded': channelsExpanded }"
                :size="14"
                :stroke-width="1.8"
                aria-hidden="true"
              />
            </button>

            <span class="nav-section-actions">
              <button
                ref="channelBrowseTrigger"
                class="nav-section-action"
                type="button"
                aria-label="Browse channels"
                @click.stop="openBrowseChannels"
              >
                <Plus :size="17" :stroke-width="1.8" aria-hidden="true" />
              </button>
              <button
                class="nav-section-action"
                :class="{ 'is-active': activeGroupHeaderMenu === 'channels' }"
                type="button"
                aria-label="Channel options"
                aria-haspopup="menu"
                :aria-expanded="activeGroupHeaderMenu === 'channels'"
                aria-controls="group-header-menu"
                @click.stop="toggleGroupHeaderMenu('channels', $event)"
              >
                <EllipsisVertical :size="17" :stroke-width="2.2" aria-hidden="true" />
              </button>
            </span>
          </h2>

          <div v-show="channelsExpanded" id="channels-list" class="nav-section-list">
            <div
              v-for="item in visibleChannelItems"
              :key="item.id"
              class="nav-channel-entry"
            >
              <button
                class="nav-row nav-row--compact"
                :class="{
                  'nav-row--active': activeNav === item.id,
                  'nav-row--context': channelContextMenu?.id === item.id,
                }"
                type="button"
                :aria-current="activeNav === item.id ? 'page' : undefined"
                @click="selectNavItem(item.id)"
                @contextmenu.prevent="openChannelContextMenu($event, item)"
                @keydown="handleChannelRowKeydown($event, item)"
              >
                <component
                  :is="item.private ? LockKeyhole : Hash"
                  class="nav-icon"
                  :size="16"
                  :stroke-width="1.8"
                  aria-hidden="true"
                />
                <span class="nav-label">{{ item.label }}</span>
                <span v-if="item.liveMeeting" class="nav-live-meeting" :aria-label="`Active meeting in ${item.label}`" :title="`${item.liveMeeting.organizer.name} started ${item.liveMeeting.title}`">
                  <Headphones :size="11" :stroke-width="2.1" aria-hidden="true" /><span>Live</span>
                </span>
                <span v-if="item.mentionCount" class="nav-mention-count" :aria-label="`${item.mentionCount} unread ${item.mentionCount === 1 ? 'mention' : 'mentions'}`">
                  <AtSign :size="11" :stroke-width="2.2" aria-hidden="true" />{{ item.mentionCount }}
                </span>
                <span v-if="item.unreadCount" class="nav-count" :aria-label="`${item.unreadCount} unread`">{{ item.unreadCount }}</span>
              </button>
              <button
                class="nav-channel-favorite"
                type="button"
                :disabled="favoritePendingId === item.id"
                :aria-label="`Add ${item.label} to Favorites`"
                @click.stop="toggleChannelFavorite(item)"
              >
                <Star :size="14" :stroke-width="1.8" aria-hidden="true" />
              </button>
            </div>
            <p v-if="communicationStatus === 'loading'" class="nav-empty-state">Loading…</p>
            <p v-else-if="communicationStatus === 'unavailable'" class="nav-empty-state">Channels unavailable</p>
            <p v-else-if="visibleChannelItems.length === 0" class="nav-empty-state">No other channels yet</p>
          </div>
        </section>

        <section class="nav-section nav-section--direct" aria-labelledby="direct-messages-heading">
          <h2
            class="nav-section-heading"
            :class="{ 'nav-section-heading--actions-open': activeGroupHeaderMenu === 'direct-messages' }"
          >
            <button
              id="direct-messages-heading"
              class="nav-section-toggle"
              type="button"
              aria-controls="direct-messages-list"
              :aria-expanded="directMessagesExpanded"
              @click="toggleDirectMessages"
              @keydown="handleSectionToggleKeydown($event, toggleDirectMessages)"
            >
              <span>Direct Messages</span>
              <ChevronDown
                class="section-chevron"
                :class="{ 'is-expanded': directMessagesExpanded }"
                :size="14"
                :stroke-width="1.8"
                aria-hidden="true"
              />
            </button>

            <span class="nav-section-actions">
              <button
                v-if="organizations.length > 0"
                ref="directMessageCreateTrigger"
                class="nav-section-action"
                type="button"
                aria-label="New Direct Message"
                @click.stop="openDirectMessageCreate"
              >
                <Plus :size="17" :stroke-width="1.8" aria-hidden="true" />
              </button>
              <button
                class="nav-section-action"
                :class="{ 'is-active': activeGroupHeaderMenu === 'direct-messages' }"
                type="button"
                aria-label="Direct Messages options"
                aria-haspopup="menu"
                :aria-expanded="activeGroupHeaderMenu === 'direct-messages'"
                aria-controls="group-header-menu"
                @click.stop="toggleGroupHeaderMenu('direct-messages', $event)"
              >
                <EllipsisVertical :size="17" :stroke-width="2.2" aria-hidden="true" />
              </button>
            </span>
          </h2>
          <div v-show="directMessagesExpanded" id="direct-messages-list" class="nav-section-list">
            <div
              v-for="item in visibleDirectMessageItems"
              :key="item.id"
              class="nav-channel-entry"
            >
              <button
                class="nav-row nav-row--compact"
                :class="{ 'nav-row--active': activeNav === item.id }"
                type="button"
                :aria-current="activeNav === item.id ? 'page' : undefined"
                @click="activeNav = item.id"
              >
                <span class="dm-avatar-shell dm-avatar-initials" aria-hidden="true">{{ item.initials }}</span>
                <span class="nav-label">{{ item.label }}</span>
                <span v-if="item.unreadCount" class="nav-count" :aria-label="`${item.unreadCount} unread`">{{ item.unreadCount }}</span>
              </button>
              <button
                class="nav-channel-favorite"
                type="button"
                :disabled="favoritePendingId === item.id"
                :aria-label="`Add ${item.label} to Favorites`"
                @click.stop="toggleDirectMessageFavorite(item)"
              >
                <Star :size="14" :stroke-width="1.8" aria-hidden="true" />
              </button>
            </div>
            <p v-if="communicationStatus === 'loading'" class="nav-empty-state">Loading…</p>
            <p v-else-if="communicationStatus === 'unavailable'" class="nav-empty-state">Direct Messages unavailable</p>
            <p v-else-if="visibleDirectMessageItems.length === 0" class="nav-empty-state">
              {{ directMessageItems.length > 0 ? 'No other Direct Messages' : 'No Direct Messages yet' }}
            </p>
          </div>
        </section>

        <Teleport to="body">
          <div
            v-if="activeGroupHeaderMenu"
            id="group-header-menu"
            ref="groupHeaderMenuElement"
            class="channel-header-menu"
            role="menu"
            :aria-label="`${activeGroupLabel} options`"
            :style="{
              left: `${groupHeaderMenuPosition.x}px`,
              top: `${groupHeaderMenuPosition.y}px`,
            }"
          >
            <div class="channel-header-menu-entry" @pointerenter="openGroupSortMenu()">
              <button
                ref="groupHeaderSortTrigger"
                class="channel-header-menu-item"
                type="button"
                role="menuitem"
                aria-haspopup="menu"
                :aria-expanded="groupHeaderSortOpen"
                aria-controls="group-sort-menu"
                @click="openGroupSortMenu(true)"
                @keydown="handleGroupHeaderSortTriggerKeydown"
              >
                <ArrowUpDown :size="18" :stroke-width="1.8" aria-hidden="true" />
                <span>Sort</span>
                <ChevronRight :size="17" :stroke-width="1.8" aria-hidden="true" />
              </button>

              <div
                v-if="groupHeaderSortOpen"
                id="group-sort-menu"
                class="channel-header-submenu"
                role="menu"
                :aria-label="`Sort ${activeGroupLabel}`"
                @keydown="handleGroupSortMenuKeydown"
              >
                <button
                  ref="firstGroupSortItem"
                  class="channel-header-submenu-item"
                  type="button"
                  role="menuitemradio"
                  :aria-checked="activeGroupSortMode === 'recent'"
                  @click="selectGroupSort('recent')"
                >
                  <span class="channel-sort-selection">
                    <Circle
                      v-if="activeGroupSortMode === 'recent'"
                      :size="7"
                      :stroke-width="0"
                      fill="currentColor"
                      aria-hidden="true"
                    />
                  </span>
                  <span>Recent</span>
                </button>
                <button
                  class="channel-header-submenu-item"
                  type="button"
                  role="menuitemradio"
                  :aria-checked="activeGroupSortMode === 'alphabetical'"
                  @click="selectGroupSort('alphabetical')"
                >
                  <span class="channel-sort-selection">
                    <Circle
                      v-if="activeGroupSortMode === 'alphabetical'"
                      :size="7"
                      :stroke-width="0"
                      fill="currentColor"
                      aria-hidden="true"
                    />
                  </span>
                  <span>A–Z</span>
                </button>
              </div>
            </div>
          </div>
        </Teleport>
      </nav>
    </div>

    <div ref="profileArea" class="profile-area">
      <div v-if="profileMenuOpen" id="profile-menu" class="profile-menu" role="menu" aria-label="Profile settings">
        <button
          v-for="(item, index) in profileMenuItems"
          :key="item.id"
          :ref="(element) => { if (index === 0) firstProfileMenuItem = element as HTMLButtonElement }"
          class="profile-menu-item"
          :class="{
            'profile-menu-item--danger': item.danger,
            'profile-menu-item--separated': item.id === 'sign-out',
          }"
          type="button"
          role="menuitem"
          @click="handleProfileMenuItem(item.id)"
        >
          <component :is="item.icon" :size="17" :stroke-width="1.8" aria-hidden="true" />
          <span>{{ item.label }}</span>
        </button>
      </div>

      <div class="profile-shell">
        <button
          ref="profileTrigger"
          class="profile-trigger"
          type="button"
          aria-haspopup="menu"
          :aria-expanded="profileMenuOpen"
          aria-controls="profile-menu"
          @click="toggleProfileMenu"
          @keydown="handleProfileTriggerKeydown"
        >
          <span class="profile-mark" aria-hidden="true">{{ profileInitials }}</span>
          <span class="profile-copy">
            <strong>{{ user.name }}</strong>
            <span>Local account</span>
          </span>
        </button>

        <button
          v-if="showServerSettings"
          class="profile-settings-button"
          type="button"
          aria-label="Open Server settings"
          title="Server settings"
          @click="handleSettingsClick"
        >
          <Settings :size="18" :stroke-width="1.8" aria-hidden="true" />
        </button>
      </div>
    </div>

    <div
      class="sidebar-resize-handle"
      role="separator"
      aria-label="Resize navigation sidebar"
      aria-orientation="vertical"
      :aria-valuemin="minWidth"
      :aria-valuemax="maxWidth"
      :aria-valuenow="width"
      tabindex="0"
      @keydown="resizeSidebarWithKeyboard"
      @pointerdown="startSidebarResize"
    ><span aria-hidden="true" /></div>

    <Teleport to="body">
      <div
        v-if="channelContextMenu"
        ref="channelContextMenuElement"
        class="channel-context-menu"
        role="menu"
        :aria-label="`${channelContextMenu.label} channel actions`"
        :style="{
          left: `${channelContextMenuPosition.x}px`,
          top: `${channelContextMenuPosition.y}px`,
        }"
        @keydown="handleChannelContextMenuKeydown"
      >
        <div
          v-for="(group, groupIndex) in channelContextGroups"
          :key="groupIndex"
          class="channel-context-group"
          role="group"
        >
          <div
            v-for="action in group"
            :key="action.id"
            class="channel-context-entry"
            @pointerenter="handleChannelContextPointerEnter(action)"
          >
            <button
              class="channel-context-item"
              :class="{
                'channel-context-item--danger': action.danger,
                'channel-context-item--no-icon': !action.icon,
              }"
              type="button"
              role="menuitem"
              data-context-level="main"
              :data-context-action="action.id"
              :disabled="action.id === 'favorite' && favoritePendingId !== null"
              :aria-haspopup="action.submenu ? 'menu' : undefined"
              :aria-expanded="action.submenu ? channelContextSubmenu === action.id : undefined"
              @click="activateChannelContextAction(action, $event)"
            >
              <component
                :is="action.icon"
                v-if="action.icon"
                class="channel-context-icon"
                :size="18"
                :stroke-width="1.8"
                aria-hidden="true"
              />
              <span>{{ channelContextActionLabel(action) }}</span>
              <ChevronRight
                v-if="action.submenu"
                class="channel-context-chevron"
                :size="17"
                :stroke-width="1.8"
                aria-hidden="true"
              />
            </button>

            <div
              v-if="action.submenu && channelContextSubmenu === action.id"
              class="channel-context-submenu"
              :data-context-submenu="action.id"
              role="menu"
              :aria-label="`${action.label} options`"
              @keydown="handleChannelContextSubmenuKeydown"
            >
              <template v-if="action.id === 'copy'">
                <button
                  class="channel-context-submenu-item"
                  type="button"
                  role="menuitem"
                  @click="activateChannelContextSubmenuItem('copy-name')"
                >
                  Copy channel name
                </button>
                <button
                  class="channel-context-submenu-item"
                  type="button"
                  role="menuitem"
                  @click="activateChannelContextSubmenuItem('copy-id')"
                >
                  Copy channel ID
                </button>
              </template>
              <button
                v-else
                class="channel-context-submenu-item channel-context-submenu-item--with-icon"
                type="button"
                role="menuitem"
                @click="activateChannelContextSubmenuItem('new-section')"
              >
                <Plus :size="18" :stroke-width="1.8" aria-hidden="true" />
                <span>New section…</span>
              </button>
            </div>
          </div>
        </div>
      </div>
    </Teleport>

    <GlobalSearchModal
      :open="globalSearchOpen"
      :query="searchQuery"
      :active-destination="activeDestination"
      :attention-count="attentionCount"
      @close="closeGlobalSearch"
      @select="selectGlobalSearchResult"
      @update:query="searchQuery = $event"
    />

    <ChannelBrowserModal
      :open="browseChannelsOpen"
      :channels="channels"
      :operating-organization-id="operatingOrganizationId"
      @close="closeBrowseChannels"
      @created="handleChannelCreated"
      @select="selectBrowsedChannel"
    />

    <DirectMessageCreateDialog
      v-if="directMessageCreateOpen"
      :organizations="organizations"
      :current-user="user"
      @close="closeDirectMessageCreate"
      @created="handleDirectMessageCreated"
    />

    <p v-if="channelActionError" class="nav-action-error" role="alert">{{ channelActionError }}</p>
  </aside>
</template>
