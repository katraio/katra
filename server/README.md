# Katra Server

The Katra Server is the Laravel API and coordination service for Katra. It provides authentication, organizations, channels, direct messages, meetings, realtime events, and the application data layer.

Use the repository-root Docker Compose environment for normal development. To run Server checks inside the local stack:

```sh
docker compose exec server composer validate --strict
docker compose exec server vendor/bin/pint --test
docker compose exec server php artisan test
```

Setup, configuration, and API documentation is maintained at [katra.io/docs](https://katra.io/docs/).
