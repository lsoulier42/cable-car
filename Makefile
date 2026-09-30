HOST_GROUP_ID = $(shell id -g)
HOST_USER = ${USER}
HOST_UID = $(shell id -u)

export HOST_UID
export HOST_USER
export HOST_GROUP_ID

DOCKER_COMPOSE_DEV = docker compose

install:
	$(DOCKER_COMPOSE_DEV) build
	$(MAKE) composer-install
	$(MAKE) frontend-install
	$(MAKE) start
	$(MAKE) jwt

composer-install:
	$(DOCKER_COMPOSE_DEV) run --rm php bash -ci 'php -d memory_limit=4G bin/composer install'

composer-update:
	$(DOCKER_COMPOSE_DEV) run --rm php bash -ci 'php -d memory_limit=4G bin/composer update -W'

start:
	$(DOCKER_COMPOSE_DEV) up -d

start-verbose:
	$(DOCKER_COMPOSE_DEV) up

stop:
	$(DOCKER_COMPOSE_DEV) down

connect:
	$(DOCKER_COMPOSE_DEV) exec php bash

clear:
	$(DOCKER_COMPOSE_DEV) exec php php ./bin/console cache:clear

# Generate the Lexik JWT keypair (skipped when the keys already exist)
jwt:
	$(DOCKER_COMPOSE_DEV) exec php php ./bin/console lexik:jwt:generate-keypair --skip-if-exists

# Frontend (React 19 + Vite + Tailwind) — run on the host, or `docker compose up -d frontend` for a containerized dev server
frontend-install:
	cd frontend && npm install

frontend-dev:
	cd frontend && npm run dev

frontend-build:
	cd frontend && npm run build

frontend-lint:
	cd frontend && npm run lint

test:
	$(DOCKER_COMPOSE_DEV) exec php php ./bin/phpunit

phpstan:
	$(DOCKER_COMPOSE_DEV) run --rm php bash -ci 'php -d memory_limit=1G ./vendor/bin/phpstan analyse'

cs:
	$(DOCKER_COMPOSE_DEV) run --rm php bash -ci 'vendor/bin/phpcs'

csfix:
	$(DOCKER_COMPOSE_DEV) run --rm php bash -ci 'vendor/bin/phpcbf'

migrate:
	$(DOCKER_COMPOSE_DEV) run --rm php bash -ci 'php ./bin/console doctrine:migrations:migrate --no-interaction'

fixtures:
	$(DOCKER_COMPOSE_DEV) run --rm php bash -ci 'php ./bin/console doctrine:fixtures:load --no-interaction'

logs:
	$(DOCKER_COMPOSE_DEV) logs -f

worker-logs:
	$(DOCKER_COMPOSE_DEV) logs -f worker

# Run a coding task with the agent, e.g.
#   make agent ARGS='demo "Add a Symfony command that displays the application version"'
agent:
	$(DOCKER_COMPOSE_DEV) exec php php ./bin/console cable-car:run $(ARGS)

# List the workspaces and models known to the harness
agent-models:
	$(DOCKER_COMPOSE_DEV) exec php php ./bin/console cable-car:run --list-models

restart:
	$(DOCKER_COMPOSE_DEV) restart

destroy:
	$(DOCKER_COMPOSE_DEV) down -v

.PHONY: install composer-install composer-update start start-verbose stop connect clear jwt frontend-install frontend-dev frontend-build frontend-lint test phpstan cs csfix migrate fixtures logs worker-logs restart destroy agent agent-models