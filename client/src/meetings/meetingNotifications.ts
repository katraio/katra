export type MeetingNotificationPermission = NotificationPermission | "unsupported";

type NotificationPermissionApi = Pick<typeof Notification, "permission" | "requestPermission">;

export type MeetingNotificationPermissionResult = {
  permission: MeetingNotificationPermission;
  issue: "blocked" | "unavailable" | null;
};

export function readMeetingNotificationPermission(
  notificationApi: NotificationPermissionApi | undefined,
): MeetingNotificationPermission {
  return notificationApi?.permission ?? "unsupported";
}

export async function requestMeetingNotificationPermission(
  notificationApi: NotificationPermissionApi | undefined,
): Promise<MeetingNotificationPermissionResult> {
  if (!notificationApi) {
    return { permission: "unsupported", issue: "unavailable" };
  }

  if (notificationApi.permission !== "default") {
    return {
      permission: notificationApi.permission,
      issue: notificationApi.permission === "denied" ? "blocked" : null,
    };
  }

  try {
    const permission = await notificationApi.requestPermission();

    return {
      permission,
      issue: permission === "denied"
        ? "blocked"
        : permission === "default"
          ? "unavailable"
          : null,
    };
  } catch {
    const permission = readMeetingNotificationPermission(notificationApi);

    return {
      permission,
      issue: permission === "denied" ? "blocked" : "unavailable",
    };
  }
}
