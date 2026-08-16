export type KatraServerConnection = {
  status: "connected";
  application: "Katra Server";
  api_version: "v1";
};

type KatraServerConnectionResponse = {
  data: KatraServerConnection;
};

function isConnectionResponse(value: unknown): value is KatraServerConnectionResponse {
  if (typeof value !== "object" || value === null || !("data" in value)) {
    return false;
  }

  const data = value.data;

  return typeof data === "object"
    && data !== null
    && "status" in data
    && data.status === "connected"
    && "application" in data
    && data.application === "Katra Server"
    && "api_version" in data
    && data.api_version === "v1";
}

export async function getKatraServerConnection(signal?: AbortSignal): Promise<KatraServerConnection> {
  const response = await fetch("/api/v1/system/connection", {
    headers: {
      Accept: "application/json",
    },
    signal,
  });

  if (!response.ok) {
    throw new Error(`Katra Server connection failed with status ${response.status}.`);
  }

  const payload: unknown = await response.json();

  if (!isConnectionResponse(payload)) {
    throw new Error("Katra Server returned an unsupported connection contract.");
  }

  return payload.data;
}
