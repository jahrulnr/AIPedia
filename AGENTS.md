# AGENTS.md — Aipedia

Instructions for any coding agent working in this repo.

## Stack

- PHP 8.1, Laravel 8.83, MySQL 8.0
- Docker: single container (PHP-FPM + nginx + supervisor)
- Base image: official `php:8.1-fpm-alpine`

## Structure

```
infra/          Docker infra (Dockerfile, nginx/, php/, supervisord.conf, start.sh)
web/            Laravel app (default structure, App\ namespace)
compose.yml     Docker Compose
Makefile        Convenience commands
```

## Setup

```bash
cp web/.env.example web/.env
make build && make up && make migrate
```

App at http://localhost:8080

## Portability / naming

This repo is a **portable demo** of an admin AI webchat. Keep code easy to drop into another Laravel admin/CMS host:

1. **Controllers**: name with `Aipedia` prefix or place under `App\Http\Controllers\Aipedia\`
2. **Models**: name with `Aipedia` prefix or place under `App\Models\Aipedia\`
3. **Routes**: use distinct path prefix (e.g. `/aipedia/...`) to avoid collisions with host routes
4. **Views**: place under `resources/views/aipedia/`
5. **Config**: prefix file names (`config/aipedia.php`)
6. **Migrations**: use descriptive names that won't clash (prefix `aipedia_`)
7. **Namespace**: keep default `App\` — do NOT change root namespace

Do **not** embed names or paths of other private product repos in docs, comments, or UI copy in this tree.

## Do / Don't

| Do | Don't |
|---|---|
| Use `make` commands for Docker ops | Run `docker compose` manually unless debugging |
| Keep controllers thin | Put business logic in controllers |
| Follow Laravel 8 conventions | Use Laravel 9++ features incompatible with 8.83 |
| Test inside container (`make test`) | Assume PHP/composer on host |
| Brand UI/docs as **Aipedia** | Reference or leak other CMS product codenames |

## AI implementation

AI/webchat behavior is specified in the sibling contracts repo `AI-ApiContracts` (webchat contract pack). Read that pack's `AGENTS.md` before implementing any AI feature. Do not hardcode private monorepo paths in committed docs.
