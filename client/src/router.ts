import { createRouter, createWebHistory } from "vue-router";
import LoginPage from "./components/auth/LoginPage.vue";
import RegistrationPage from "./components/auth/RegistrationPage.vue";
import InvitationAcceptancePage from "./components/auth/InvitationAcceptancePage.vue";
import GuestMeetingPage from "./components/meetings/GuestMeetingPage.vue";
import AuthenticatedApp from "./components/shell/AuthenticatedApp.vue";
import { authSession, initializeAuthentication } from "./auth/authSession";

export const router = createRouter({
  history: createWebHistory(),
  routes: [
    {
      path: "/login",
      name: "login",
      component: LoginPage,
      meta: { guestOnly: true },
    },
    {
      path: "/register",
      name: "register",
      component: RegistrationPage,
      meta: { guestOnly: true },
    },
    {
      path: "/accept-invitation",
      name: "accept-invitation",
      component: InvitationAcceptancePage,
    },
    {
      path: "/meeting-guests/:meetingId",
      name: "meeting-guest",
      component: GuestMeetingPage,
    },
    {
      path: "/meeting-invitations/:invitationId",
      name: "meeting-email-invitation",
      component: GuestMeetingPage,
    },
    {
      path: "/:pathMatch(.*)*",
      name: "workspace",
      component: AuthenticatedApp,
      meta: { requiresAuth: true },
    },
  ],
});

router.beforeEach(async (to) => {
  await initializeAuthentication();

  if (to.meta.requiresAuth && authSession.status.value !== "authenticated") {
    return {
      name: "login",
      query: to.fullPath === "/" ? {} : { redirect: to.fullPath },
    };
  }

  if (to.meta.guestOnly && authSession.status.value === "authenticated") {
    const redirect = typeof to.query.redirect === "string" ? to.query.redirect : "/";
    return redirect;
  }

  return true;
});
