# Katra LiveKit for Laravel

`katra/livekit-laravel` is Katra's first-party Laravel integration for a self-hosted LiveKit server.

The package deliberately owns a narrow transport boundary:

- participant join credentials scoped to one room and allowed media sources;
- room-administration credentials;
- participant removal;
- room deletion;
- Laravel configuration and dependency-injection bindings.

Application authorization remains the consuming application's responsibility. The package never decides who may join or administer a room.

## Configuration

Publish `livekit.php` or configure these environment variables:

```dotenv
LIVEKIT_URL=http://livekit:7880
LIVEKIT_PUBLIC_URL=ws://localhost:7880
LIVEKIT_API_KEY=
LIVEKIT_API_SECRET=
LIVEKIT_JOIN_TOKEN_TTL=120
LIVEKIT_SERVICE_TOKEN_TTL=60
LIVEKIT_HTTP_TIMEOUT=5
```

The API key and secret stay server-side. `LIVEKIT_PUBLIC_URL` is the only service location intended for a browser response.

## Laravel contracts

Resolve `Katra\LiveKit\Contracts\AccessTokenFactory` to create an exact-room participant credential. Resolve `Katra\LiveKit\Contracts\RoomService` to remove a participant or delete a room.

The Room Service client uses LiveKit's documented JSON-over-Twirp endpoints rather than mirroring the full upstream protocol surface.
