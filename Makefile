.PHONY: help build up down restart logs shell artisan composer test fresh migrate migrate-fresh seed queue-stop queue-restart

help: ## Show this help
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | sort | awk 'BEGIN {FS = ":.*?## "}; {printf "\033[36m%-20s\033[0m %s\n", $$1, $$2}'

build: ## Build Docker images
	docker compose build

up: ## Start all containers (app + db)
	docker compose up -d

down: ## Stop all containers
	docker compose down

restart: ## Restart all containers
	docker compose up -d --force-recreate

logs: ## Tail container logs
	docker compose logs -f app

shell: ## Shell into app container
	docker compose exec app sh

artisan: ## Run artisan command (usage: make artisan tinker)
	docker compose exec app php artisan $(CMD)

composer: ## Run composer command (usage: make composer require pkg)
	docker compose exec app composer $(CMD)

migrate: ## Run migrations
	docker compose exec app php artisan migrate

migrate-fresh: ## Fresh migrate + seed
	docker compose exec app php artisan migrate:fresh --seed

seed: ## Run seeders
	docker compose exec app php artisan db:seed

fresh: ## Fresh install: build + up + migrate:fresh
	docker compose build && docker compose up -d && sleep 3 && docker compose exec app php artisan migrate:fresh --seed

test: ## Run tests
	docker compose exec app php artisan test

queue-restart: ## Restart queue workers
	docker compose exec app php artisan queue:restart

queue-stop: ## Stop queue workers
	docker compose exec app php artisan queue:stop
