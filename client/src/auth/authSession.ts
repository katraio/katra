import { readonly, ref } from "vue";
import {
  AuthRequestError,
  getCurrentUser,
  loginLocalAccount,
  logoutLocalAccount,
  registerLocalAccount,
  type AuthUser,
} from "../api/auth";

type AuthenticationStatus = "checking" | "authenticated" | "guest" | "unavailable";

const currentUser = ref<AuthUser | null>(null);
const status = ref<AuthenticationStatus>("checking");
let initialization: Promise<void> | null = null;

function statusAfterAuthFailure(error: unknown): AuthenticationStatus {
  if (error instanceof AuthRequestError && error.status < 500) {
    return "guest";
  }

  return "unavailable";
}

export const authSession = {
  user: readonly(currentUser),
  status: readonly(status),
};

export function initializeAuthentication(): Promise<void> {
  if (initialization) {
    return initialization;
  }

  initialization = (async () => {
    try {
      currentUser.value = await getCurrentUser();
      status.value = "authenticated";
    } catch (error) {
      currentUser.value = null;
      status.value = error instanceof AuthRequestError && error.status === 401 ? "guest" : "unavailable";
    }
  })();

  return initialization;
}

export async function refreshAuthentication(): Promise<void> {
  initialization = null;
  status.value = "checking";
  await initializeAuthentication();
}

export function adoptAuthenticatedUser(user: AuthUser): void {
  currentUser.value = user;
  status.value = "authenticated";
}

export async function signIn(input: {
  email: string;
  password: string;
  remember: boolean;
}): Promise<AuthUser> {
  status.value = "checking";

  try {
    await loginLocalAccount(input);
    const user = await getCurrentUser();
    currentUser.value = user;
    status.value = "authenticated";

    return user;
  } catch (error) {
    currentUser.value = null;
    status.value = statusAfterAuthFailure(error);

    throw error;
  }
}

export async function signUp(input: {
  first_name: string;
  last_name: string;
  email: string;
  password: string;
  password_confirmation: string;
}): Promise<AuthUser> {
  status.value = "checking";

  try {
    await registerLocalAccount(input);
    const user = await getCurrentUser();
    currentUser.value = user;
    status.value = "authenticated";

    return user;
  } catch (error) {
    currentUser.value = null;
    status.value = statusAfterAuthFailure(error);

    throw error;
  }
}

export async function signOut(): Promise<void> {
  await logoutLocalAccount();
  currentUser.value = null;
  status.value = "guest";
}
