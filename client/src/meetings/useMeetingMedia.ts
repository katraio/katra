import {
  ConnectionState,
  LogLevel,
  LocalParticipant,
  RemoteParticipant,
  Room,
  RoomEvent,
  Track,
  setLogLevel,
  type LocalAudioTrack,
  type LocalVideoTrack,
  type RemoteAudioTrack,
  type RemoteVideoTrack,
} from "livekit-client";
import { computed, markRaw, readonly, ref } from "vue";
import type { MeetingMediaCredential } from "../api/communication";

setLogLevel(LogLevel.warn);

export type MeetingParticipantMedia = {
  camera: LocalVideoTrack | RemoteVideoTrack | null;
  microphone: RemoteAudioTrack | null;
  microphoneMuted: boolean | null;
  screenShare: LocalVideoTrack | RemoteVideoTrack | null;
  screenShareAudio: RemoteAudioTrack | null;
  isSpeaking: boolean;
};

export type MeetingMediaDevice = {
  deviceId: string;
  label: string;
};

type MediaParticipant = LocalParticipant | RemoteParticipant;

export function useMeetingMedia() {
  const connected = ref(false);
  const connecting = ref(false);
  const microphoneEnabled = ref(true);
  const cameraEnabled = ref(true);
  const cameraVisible = ref(false);
  const screenShareEnabled = ref(false);
  const audioInputDevices = ref<MeetingMediaDevice[]>([]);
  const videoInputDevices = ref<MeetingMediaDevice[]>([]);
  const selectedAudioInputId = ref("");
  const selectedVideoInputId = ref("");
  const generation = ref<number | null>(null);
  const failure = ref("");
  const revision = ref(0);
  const media = new Map<string, MeetingParticipantMedia>();
  let room: Room | null = null;
  let desiredMicrophoneEnabled = true;
  let desiredCameraEnabled = true;
  let recoveringLocalMedia = false;

  const bump = () => { revision.value += 1; };

  async function refreshDevices(): Promise<void> {
    const [audioInputs, videoInputs] = await Promise.all([
      Room.getLocalDevices("audioinput", false),
      Room.getLocalDevices("videoinput", false),
    ]);
    audioInputDevices.value = audioInputs.map((device, index) => ({
      deviceId: device.deviceId,
      label: device.label || `Microphone ${index + 1}`,
    }));
    videoInputDevices.value = videoInputs.map((device, index) => ({
      deviceId: device.deviceId,
      label: device.label || `Camera ${index + 1}`,
    }));
    selectedAudioInputId.value = room?.getActiveDevice("audioinput") ?? selectedAudioInputId.value;
    selectedVideoInputId.value = room?.getActiveDevice("videoinput") ?? selectedVideoInputId.value;
  }

  function syncParticipant(participant: MediaParticipant): void {
    const cameraPublication = participant.getTrackPublication(Track.Source.Camera);
    const microphonePublication = participant.getTrackPublication(Track.Source.Microphone);
    const screenSharePublication = participant.getTrackPublication(Track.Source.ScreenShare);
    const screenShareAudioPublication = participant.getTrackPublication(Track.Source.ScreenShareAudio);
    const camera = cameraPublication?.track;
    const microphone = participant instanceof RemoteParticipant
      ? microphonePublication?.track
      : null;
    const screenShare = screenSharePublication?.track;
    const screenShareAudio = participant instanceof RemoteParticipant
      ? screenShareAudioPublication?.track
      : null;
    if (participant instanceof LocalParticipant) {
      microphoneEnabled.value = Boolean(microphonePublication?.track && !microphonePublication.isMuted);
      cameraEnabled.value = Boolean(cameraPublication?.track && !cameraPublication.isMuted);
      screenShareEnabled.value = Boolean(screenSharePublication?.track && !screenSharePublication.isMuted);
    }
    media.set(participant.identity, {
      camera: camera?.kind === Track.Kind.Video && !cameraPublication?.isMuted
        ? markRaw(camera as LocalVideoTrack | RemoteVideoTrack)
        : null,
      microphone: microphone?.kind === Track.Kind.Audio ? markRaw(microphone as RemoteAudioTrack) : null,
      microphoneMuted: microphonePublication ? microphonePublication.isMuted : null,
      screenShare: screenShare?.kind === Track.Kind.Video && !screenSharePublication?.isMuted
        ? markRaw(screenShare as LocalVideoTrack | RemoteVideoTrack)
        : null,
      screenShareAudio: screenShareAudio?.kind === Track.Kind.Audio
        ? markRaw(screenShareAudio as RemoteAudioTrack)
        : null,
      isSpeaking: participant.isSpeaking,
    });
    bump();
  }

  function syncRoom(): void {
    if (!room) return;
    syncParticipant(room.localParticipant);
    room.remoteParticipants.forEach(syncParticipant);
  }

  async function recoverLocalMedia(nextRoom: Room): Promise<void> {
    if (room !== nextRoom || recoveringLocalMedia) return;
    recoveringLocalMedia = true;

    try {
      const captureResults = await Promise.allSettled([
        nextRoom.localParticipant.setMicrophoneEnabled(desiredMicrophoneEnabled),
        nextRoom.localParticipant.setCameraEnabled(desiredCameraEnabled),
      ]);
      if (room !== nextRoom) return;
      syncParticipant(nextRoom.localParticipant);
      cameraVisible.value = cameraEnabled.value;
      if (captureResults.some((result) => result.status === "rejected")) {
        failure.value = "Camera or microphone capture is unavailable.";
      }
    } finally {
      recoveringLocalMedia = false;
    }
  }

  function register(nextRoom: Room): void {
    const sync = (_track?: unknown, _publication?: unknown, participant?: MediaParticipant) => {
      if (participant) syncParticipant(participant);
      else syncRoom();
    };
    nextRoom
      .on(RoomEvent.TrackSubscribed, sync)
      .on(RoomEvent.TrackUnsubscribed, sync)
      .on(RoomEvent.TrackPublished, sync)
      .on(RoomEvent.TrackUnpublished, sync)
      .on(RoomEvent.TrackMuted, sync)
      .on(RoomEvent.TrackUnmuted, sync)
      .on(RoomEvent.LocalTrackPublished, () => syncParticipant(nextRoom.localParticipant))
      .on(RoomEvent.LocalTrackUnpublished, () => syncParticipant(nextRoom.localParticipant))
      .on(RoomEvent.ActiveSpeakersChanged, (speakers) => {
        const active = new Set(speakers.map((speaker) => speaker.identity));
        media.forEach((state, identity) => { state.isSpeaking = active.has(identity); });
        bump();
      })
      .on(RoomEvent.MediaDevicesChanged, () => { void refreshDevices(); })
      .on(RoomEvent.ActiveDeviceChanged, (kind, deviceId) => {
        if (kind === "audioinput") selectedAudioInputId.value = deviceId;
        if (kind === "videoinput") selectedVideoInputId.value = deviceId;
      })
      .on(RoomEvent.ParticipantDisconnected, (participant) => {
        media.delete(participant.identity);
        bump();
      })
      .on(RoomEvent.Reconnecting, () => { connecting.value = true; })
      .on(RoomEvent.Reconnected, () => {
        connecting.value = false;
        connected.value = true;
        void recoverLocalMedia(nextRoom);
      })
      .on(RoomEvent.Disconnected, () => {
        connecting.value = false;
        connected.value = false;
        media.clear();
        bump();
      })
      .on(RoomEvent.MediaDevicesError, (error) => { failure.value = error.message; });
  }

  async function disconnect(): Promise<void> {
    const current = room;
    room = null;
    if (current && current.state !== ConnectionState.Disconnected) await current.disconnect();
    connected.value = false;
    connecting.value = false;
    screenShareEnabled.value = false;
    cameraVisible.value = false;
    media.clear();
    bump();
  }

  async function connect(credential: MeetingMediaCredential): Promise<void> {
    await disconnect();
    connecting.value = true;
    failure.value = "";
    generation.value = credential.room_generation;
    const nextRoom = new Room({ adaptiveStream: true, dynacast: true, disconnectOnPageLeave: true });
    room = nextRoom;
    register(nextRoom);

    try {
      await nextRoom.connect(credential.url, credential.token);
      connected.value = true;
      const captureResults = await Promise.allSettled([
        nextRoom.localParticipant.setMicrophoneEnabled(desiredMicrophoneEnabled),
        nextRoom.localParticipant.setCameraEnabled(desiredCameraEnabled),
      ]);
      syncRoom();
      cameraVisible.value = cameraEnabled.value;
      await refreshDevices();
      if (captureResults.some((result) => result.status === "rejected")) {
        failure.value = "Camera or microphone capture is unavailable.";
      }
    } catch (error) {
      failure.value = error instanceof Error ? error.message : "The meeting media connection failed.";
      await disconnect();
      throw error;
    } finally {
      connecting.value = false;
    }
  }

  async function setMicrophoneEnabled(enabled: boolean): Promise<void> {
    if (!room || !connected.value) throw new Error("Meeting media is not connected.");
    await room.localParticipant.setMicrophoneEnabled(enabled);
    desiredMicrophoneEnabled = enabled;
    syncParticipant(room.localParticipant);
  }

  async function setCameraEnabled(enabled: boolean): Promise<void> {
    if (!room || !connected.value) throw new Error("Meeting media is not connected.");
    await room.localParticipant.setCameraEnabled(enabled);
    desiredCameraEnabled = enabled;
    syncParticipant(room.localParticipant);
    cameraEnabled.value = enabled;
    cameraVisible.value = enabled;
    if (!enabled) {
      const state = media.get(room.localParticipant.identity);
      if (state) state.camera = null;
      bump();
    }
  }

  async function selectAudioInput(deviceId: string): Promise<void> {
    if (!room || !connected.value) throw new Error("Meeting media is not connected.");
    const changed = await room.switchActiveDevice("audioinput", deviceId, true);
    if (!changed) throw new Error("The selected microphone is unavailable.");
    selectedAudioInputId.value = deviceId;
    await refreshDevices();
  }

  async function selectVideoInput(deviceId: string): Promise<void> {
    if (!room || !connected.value) throw new Error("Meeting media is not connected.");
    const changed = await room.switchActiveDevice("videoinput", deviceId, true);
    if (!changed) throw new Error("The selected camera is unavailable.");
    selectedVideoInputId.value = deviceId;
    await refreshDevices();
  }

  async function setScreenShareEnabled(enabled: boolean): Promise<void> {
    if (!room || !connected.value) throw new Error("Meeting media is not connected.");
    await room.localParticipant.setScreenShareEnabled(enabled, { audio: true });
    syncParticipant(room.localParticipant);
  }

  function forIdentity(identity?: string | null): MeetingParticipantMedia | null {
    void revision.value;
    return identity ? media.get(identity) ?? null : null;
  }

  const activeScreenShare = computed(() => {
    void revision.value;
    for (const [identity, state] of media) {
      if (state.screenShare) return { identity, media: state };
    }
    return null;
  });

  return {
    activeScreenShare,
    audioInputDevices: readonly(audioInputDevices),
    cameraEnabled: readonly(cameraEnabled),
    cameraVisible: readonly(cameraVisible),
    connected: readonly(connected),
    connecting: readonly(connecting),
    failure: readonly(failure),
    forIdentity,
    generation: readonly(generation),
    microphoneEnabled: readonly(microphoneEnabled),
    screenShareEnabled: readonly(screenShareEnabled),
    selectedAudioInputId: readonly(selectedAudioInputId),
    selectedVideoInputId: readonly(selectedVideoInputId),
    videoInputDevices: readonly(videoInputDevices),
    connect,
    disconnect,
    setCameraEnabled,
    setMicrophoneEnabled,
    setScreenShareEnabled,
    selectAudioInput,
    selectVideoInput,
  };
}
