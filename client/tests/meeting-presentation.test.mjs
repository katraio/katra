import assert from "node:assert/strict";
import test from "node:test";
import {
  formatMeetingElapsedTime,
  retainPresentRaisedParticipantIds,
  updateRaisedParticipantIds,
} from "../src/meetings/meetingPresentation.ts";
import {
  readMeetingNotificationPermission,
  requestMeetingNotificationPermission,
} from "../src/meetings/meetingNotifications.ts";

test("formats meeting elapsed time from the server start time", () => {
  const startedAt = "2026-08-13T12:00:00.000Z";
  assert.equal(formatMeetingElapsedTime(startedAt, Date.parse("2026-08-13T12:01:05.000Z")), "01:05");
  assert.equal(formatMeetingElapsedTime(startedAt, Date.parse("2026-08-13T13:02:03.000Z")), "01:02:03");
});

test("uses the room mount time when a prototype meeting has no server start time", () => {
  assert.equal(formatMeetingElapsedTime(null, 12_500, 10_000), "00:02");
});

test("keeps raised hands visible until lowered or the participant leaves", () => {
  const raised = updateRaisedParticipantIds(new Set(), "participant-1", "raise-hand");
  assert.deepEqual([...raised], ["participant-1"]);
  assert.equal(updateRaisedParticipantIds(raised, "participant-1", "celebrate"), raised);
  assert.deepEqual([...updateRaisedParticipantIds(raised, "participant-1", "lower-hand")], []);
  assert.deepEqual([...retainPresentRaisedParticipantIds(raised, new Set(["participant-2"]))], []);
});

test("requests meeting-notification permission only from the explicit enable action", async () => {
  let requestCount = 0;
  const notificationApi = {
    permission: "default",
    async requestPermission() {
      requestCount += 1;
      return "granted";
    },
  };

  assert.equal(readMeetingNotificationPermission(notificationApi), "default");
  assert.deepEqual(await requestMeetingNotificationPermission(notificationApi), {
    permission: "granted",
    issue: null,
  });
  assert.equal(requestCount, 1);
});

test("reports browser-blocked and unavailable notification permission states", async () => {
  let deniedRequestCount = 0;
  const deniedApi = {
    permission: "denied",
    async requestPermission() {
      deniedRequestCount += 1;
      return "denied";
    },
  };
  const unchangedApi = {
    permission: "default",
    async requestPermission() {
      return "default";
    },
  };

  assert.deepEqual(await requestMeetingNotificationPermission(deniedApi), {
    permission: "denied",
    issue: "blocked",
  });
  assert.equal(deniedRequestCount, 0);
  assert.deepEqual(await requestMeetingNotificationPermission(unchangedApi), {
    permission: "default",
    issue: "unavailable",
  });
  assert.deepEqual(await requestMeetingNotificationPermission(undefined), {
    permission: "unsupported",
    issue: "unavailable",
  });
});
