# Testing notes

## `file_get_contents(/var/www/html/.env)` warnings in `make test`

If you run tests inside the `php-fpm` container and see repeated warnings like:

- `file_get_contents(/var/www/html/.env): Failed to open stream: No such file or directory`

it means the Laravel app directory mounted at `/var/www/html` does not currently contain an `.env` file.

### Why this happens

`docker compose` injects many runtime environment variables directly into the container, so the app can still boot without `.env` in many paths. Some code paths and/or package boot logic still attempt to read the physical `.env` file, which produces warnings when the file is missing.

### Fix

From the repository root:

```bash
cp app/.env.example app/.env
```

Then re-run:

```bash
make test
```

This keeps local/container test runs quiet and avoids warning noise that can hide real failures.
