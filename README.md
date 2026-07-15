# AIPedia

Laravel 8.83 / PHP 8.1 project. Docker setup single-container PHP-FPM + nginx + supervisor

## Quick start

```bash
cp .env.example .env
make build
make up
make migrate
```

App: http://localhost:8080

## Structure

```
infra/              # Docker infrastructure (Dockerfile, nginx, php, supervisord)
web/                # Laravel application
compose.yml         # Docker Compose (app + mysql)
Makefile            # Convenience commands
.env.example        # Environment template
```

## Make commands

```bash
make help           # list all commands
make build          # build docker images
make up             # start containers
make down           # stop containers
make logs           # tail app logs
make shell          # shell into app container
make migrate        # run migrations
make migrate-fresh  # fresh migrate + seed
make fresh          # full reset: build + up + migrate:fresh
make test           # run tests
```

## Stack

| Component | Version |
|---|---|
| PHP | 8.1 (fpm-alpine) |
| Laravel | 8.83 |
| MySQL | 8.0 |
| nginx | alpine |
| Supervisor | php-fpm + nginx + queue worker |
