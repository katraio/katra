# Contributing to Katra

Thanks for helping improve Katra.

Before opening a pull request, please search the existing issues and keep the change focused on one problem. For larger changes, open an issue first so the approach can be discussed before implementation.

## Development

Start the local stack from the repository root:

```sh
docker compose up --build
```

Before submitting a change, run the checks that cover it. The standard checks are:

```sh
docker compose exec server php artisan test
docker compose exec server vendor/bin/pint --test
docker compose exec client npm run check
docker compose exec client npm run build
docker compose exec client npm run test:sites
```

Do not include credentials, customer data, private planning documents, production configuration, or generated build output in a contribution.

By contributing, you agree that your contribution is licensed under the project's MIT License.
