# Plan d'évolution — Stack Docker FrankenPHP (v1)

> Document de travail — **décisions §1.2 validées ; plan mis en œuvre (phases 1 à 4 livrées)**.
> Version : 1.1 — 30/09/2026

## 1. Objectif et décisions actées

Remplacer la paire **Nginx + PHP-FPM** par **FrankenPHP** (serveur d'application PHP
embarqué dans Caddy) dans le `docker-compose.yaml` du starter, sans changer les URLs,
le parcours dev ni les qualités de l'existant.

### 1.1. Objectifs

- **Un seul service applicatif** pour servir la SPA React buildée *et* l'API Symfony
  (même origine, plus de FastCGI, plus de double configuration web).
- **Conserver** : port publié `${APP_PORT}` (8081), SPA + fallback client-side,
  `/api` → `public/index.php`, Postgres 18, Mailpit, service `frontend` Vite,
  commandes Makefile, gates de qualité.
- **Préparer** le worker mode (perf type Octane) pour une itération « prod » ultérieure,
  sans l'activer en dev.

### 1.2. Décisions validées

| Question | Décision |
|---|---|
| Périmètre | **Dev d'abord** : migration du compose actuel, même niveau de service. Le durcissement prod (overlay) est préparé mais hors périmètre. |
| Mode de serving | **Classic en dev** (comportement PHP-FPM : rechargement immédiat, zéro piège d'état) ; **worker mode en prod** plus tard. |
| Workers Messenger | **Service compose dédié** `worker` (même image, `messenger:consume`) ; **suppression de supervisord**. |
| Xdebug | **Retiré** de l'image (réinstallable via `install-php-extensions` si besoin). |

### 1.3. Décisions techniques prises par défaut (amendables)

| Sujet | Choix | Raison |
|---|---|---|
| Image | `dunglas/frankenphp:1.12.7-php8.5` (Debian Trixie, PHP 8.5.11) | Tag vérifié (publié le 25/09/2026) ; Debian recommandé par les mainteneurs (caveats musl). Cohérent avec l'épinglage des autres images. |
| Port interne | **8080**, conteneur non-root (`docker`) | Pas de bind privilégié, pas de `setcap` ; le mapping `${APP_PORT}:8080` garde l'URL publique. |
| Nom du service | `php` conservé | Aucun changement Makefile/README côté commandes (`connect`, `test`, `cs`…). |
| Caddyfile | `docker/frankenphp/Caddyfile`, monté en dev, copié dans l'image | Même pattern que l'actuel `docker/nginx/default.conf`. |
| Healthcheck | `curl -fsS http://127.0.0.1:8080/api/docs` | Vérifie Caddy **et** le boot Symfony (le healthcheck actuel est un simple test TCP 9000). |
| Logs | stdout/stderr Docker (`tty: true` en dev) | Supprime le fichier `var/log/nginx_access.log` ; `make logs` reste la source unique. |
| UID/GID | build args `UID`/`GID` alimentés par les variables `HOST_UID`/`HOST_GROUP_ID` déjà exportées par le Makefile | Évite les conflits de permissions sur les volumes montés (`var/`, fixtures…). |
| `xdebug` | Non installé | Décision 1.2 ; documenter la réactivation. |

## 2. Analyse de l'existant

### 2.1. Services actuels

| Service | Image / build | Rôle | Couplages |
|---|---|---|---|
| `database` | `postgres:18.2-alpine` | Postgres 18 + volume local | inchangé |
| `php` | build `docker/php` (base `php:8.5-fpm`) | PHP-FPM :9000, supervisord → `messenger:consume`, Composer, Xdebug, util. dev (`vim`, `sudo`, `procps`) | `nginx` l'appelle en FastCGI |
| `nginx` | `nginx:1.29.5-alpine` | Sert `frontend/dist` (fallback SPA) et proxifie `/api` → `php:9000` | dépend de `php` healthy |
| `frontend` | `node:24-alpine` | Vite dev + HMR, proxy `/api` | `VITE_API_PROXY_TARGET=http://nginx:80` |
| `mailer` | `axllent/mailpit` | SMTP + UI | inchangé |

### 2.2. Points de couplage identifiés

- `docker/nginx/default.conf` : `root frontend/dist`, `try_files … /index.html`,
  `location /api` → FastCGI `php:9000`, `deny .php`, logs fichiers.
- `docker/php/run_php.sh` + `docker/php/messenger-workers.conf` : supervisord + php-fpm.
- Healthcheck `php` en TCP brut (`/dev/tcp/127.0.0.1/9000`).
- `docker-compose.yaml` : `VITE_API_PROXY_TARGET: http://nginx:80`, commentaires nginx.
- CI : `docker compose config` + `docker compose build php`.
- README : stack, architecture, section Docker, arbre projet (multiples mentions Nginx/FPM).

### 2.3. Limites de l'existant (motivation)

1. **Deux runtimes web** pour un serveur qui n'expose que `/api` et des fichiers statiques :
   configuration FastCGI, buffers, règles `deny`, deux images à maintenir.
2. **Outils dev inutiles dans l'image PHP** (`supervisor`, `sudo`, `vim`, `procps`, Xdebug).
3. **Worker Messenger embarqué** dans le conteneur web : couplage des cycles de vie,
   pièce supervisord supplémentaire.
4. **Healthcheck non applicatif**, logs nginx dans un fichier monté.
5. Aucun chemin vers les gains FrankenPHP (**worker mode**, HTTP/2-H3, HTTPS auto, Early Hints)
   alors que la doc officielle Symfony Docker est justement bâtie sur FrankenPHP.

## 3. Architecture cible

```
AVANT
browser ──▶ nginx :80 ──/api──fastcgi──▶ php-fpm :9000
               │                            └─ supervisord ─ messenger:consume
               └── frontend/dist (SPA)
                       + conteneur frontend (Vite 5173 → nginx)

APRÈS
browser ──▶ php :8080 (FrankenPHP = Caddy + PHP embarqué)
               ├── /api/*        → Symfony public/index.php (mode classic)
               └── tout le reste → frontend/dist (SPA + fallback index.html)
             + conteneur worker (même image) → php bin/console messenger:consume
             + conteneur frontend (Vite 5173 → php:8080)
```

- Même origine en prod comme en dev : **pas de CORS**, URLs publiques inchangées
  (`http://localhost:8081`, Swagger `…/api/docs`, Vite `:5173`).
- Le worker mode prod tiendra en une variable : `FRANKENPHP_CONFIG="worker /var/www/html/public/index.php"`.

## 4. Impacts fichier par fichier

| Fichier | Action | Détail |
|---|---|---|
| `docker/frankenphp/Dockerfile` | **créé** | (ex-`docker/php/Dockerfile`) base FrankenPHP, extensions, Composer, user non-root, ENTRYPOINT/CMD. |
| `docker/frankenphp/Caddyfile` | **créé** | Routes API + SPA, bloc `frankenphp` paramétrable par `FRANKENPHP_CONFIG`. |
| `docker/frankenphp/conf.d/10-app.ini` | **créé** | `memory_limit=512M`, `max_execution_time=30` (aucune surcharge PHP n'existe aujourd'hui). |
| `docker/php/` | **supprimé** | `Dockerfile`, `run_php.sh`, `messenger-workers.conf` (remplacés/renommés). |
| `docker/nginx/` | **supprimé** | Remplacé par le Caddyfile. |
| `docker-compose.yaml` | modifié | Service `php` FrankenPHP (port/healthcheck/volumes/tty/build args), nouveau service `worker`, service `nginx` supprimé, `VITE_API_PROXY_TARGET: http://php:8080`. |
| `Makefile` | quasi inchangé | Service `php` conservé → `connect`/`clear`/`test`/`cs`/`migrate`… fonctionnent tels quels. Ajouts optionnels : recette `worker-logs`. |
| `frontend/vite.config.ts` | inchangé | Proxy hôte toujours `http://localhost:8081`. |
| `.env` / `.env.test` | inchangés | Aucune variable nouvelle (pas de volumes Caddy, HTTPS off). |
| `.github/workflows/ci.yml` | modifié | Job `docker` : build de la nouvelle image + `frankenphp validate --config` (garde-fou Caddyfile). Le smoke test `up` optionnel est listé §8. |
| `README.md` | modifié | Badge/description, stack (Nginx → FrankenPHP), architecture, section Docker, arbre projet. |
| `docs/plan-evolution.md` | inchangé | Document historique de l'évolution précédente. |
| `docs/plan-frankenphp.md` | **créé** | Ce document. |

Aucun changement côté application Symfony (`public/index.php`, config, tests) : le
contrat HTTP reste identique.

## 5. Détail des nouveaux fichiers

### 5.1. `docker/frankenphp/Dockerfile`

```dockerfile
# Image épinglée pour des builds reproductibles : https://hub.docker.com/r/dunglas/frankenphp/tags
# Tag par défaut = Debian Trixie (variante recommandée par les mainteneurs).
FROM dunglas/frankenphp:1.12.7-php8.5

RUN apt-get update && apt-get install -y --no-install-recommends \
        git \
        unzip \
        curl \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Même jeu d'extensions que l'image FPM actuelle, sans xdebug, + opcache et Composer.
RUN install-php-extensions pgsql pdo_pgsql intl zip apcu mbstring sodium opcache @composer

# Configuration PHP applicative.
COPY ./conf.d/10-app.ini $PHP_INI_DIR/conf.d/10-app.ini

# Configuration Caddy/FrankenPHP (surchargée en dev par le mount compose).
COPY ./Caddyfile /etc/frankenphp/Caddyfile

ENV WORKDIR=/var/www/html
WORKDIR $WORKDIR

# Utilisateur non-root aligné sur l'utilisateur hôte (volumes montés sans conflit de permissions).
ARG UID=1000
ARG GID=1000
RUN groupadd -g $GID docker \
    && useradd -m -u $UID -g $GID -s /bin/bash docker \
    && mkdir -p /data/caddy /config/caddy \
    && chown -R docker:docker /data /config

EXPOSE 8080

USER docker

# Entrypoint officiel PHP : `docker compose run php bash` et `php bin/console …` restent
# utilisables tels quels (le CMD lance le serveur FrankenPHP).
ENTRYPOINT ["docker-php-entrypoint"]
CMD ["frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile"]
```

> L'image officielle embarque un `HEALTHCHECK` (API admin Caddy sur `localhost:2019`) :
> le service `php` le remplace par un check applicatif (`/api/docs`) et le service `worker`
> le désactive (`healthcheck.disable: true`), puisqu'il ne sert aucun trafic HTTP.

> Le nom d'utilisateur `docker` et le home `/home/docker` sont conservés : les volumes
> `~/.composer` et `~/.ssh` du compose restent valides.
> Le shim `php` de la doc « known issues » n'est **pas** nécessaire : l'image officielle
> embarque le binaire CLI PHP (utilisé par `make composer-install`, `make test`, etc.).

### 5.2. `docker/frankenphp/Caddyfile`

```caddyfile
{
	# Options globales FrankenPHP.
	# Prod (itération suivante) : définir FRANKENPHP_CONFIG="worker /var/www/html/public/index.php" ;
	# en dev la variable est vide → mode classic (comportement PHP-FPM).
	frankenphp {
		{$FRANKENPHP_CONFIG}
	}

	# HTTP simple : le port publié est géré par Docker, pas d'HTTPS automatique ici.
	auto_https off
}

:8080 {
	root * /var/www/html/public
	encode zstd br gzip

	# API Symfony / API Platform (+ Swagger UI /api/docs).
	@api path /api /api/*
	handle @api {
		php_server
	}

	# SPA React buildée (make frontend-build) : assets puis fallback index.html.
	handle {
		root * /var/www/html/frontend/dist
		try_files {path} /index.html
		file_server
	}
}
```

Points de vigilance :
- le matcher couvre `/api` **et** `/api/*` (équivalent du `location /api` nginx) ;
- les 404 API restent du JSON Symfony ; les routes inconnues hors `/api` retombent sur la SPA ;
- `encode zstd br gzip` est supporté par l'image officielle (identiquement à Symfony Docker) ;
- les access logs Caddy sont sur stderr → visibles via `make logs`.

### 5.3. `docker/frankenphp/conf.d/10-app.ini`

```ini
memory_limit = 512M
max_execution_time = 30
```

> `composer install` continue de forcer `-d memory_limit=4G` via le Makefile (le CLI
> officiel supporte `-d`, contrairement à `frankenphp php-cli`).

### 5.4. `docker-compose.yaml` (extrait cible)

```yaml
  php:
    build:
      context: ./docker/frankenphp
      args:
        UID: "${HOST_UID:-1000}"
        GID: "${HOST_GROUP_ID:-1000}"
    restart: unless-stopped
    tty: true
    healthcheck:
      test: ["CMD", "curl", "-fsS", "-o", "/dev/null", "http://127.0.0.1:8080/api/docs"]
      interval: 3s
      timeout: 5s
      retries: 30
      start_period: 15s
    volumes:
      - ".:/var/www/html"
      - "~/.composer:/home/docker/.composer"
      - "~/.ssh:/home/docker/.ssh"
      - "./docker/frankenphp/Caddyfile:/etc/frankenphp/Caddyfile:ro"
    ports:
      - "${APP_PORT}:8080"
    depends_on:
      database:
        condition: service_healthy

  # Exécute les messages asynchrones (ex-supervisord) ; même image, pas de port.
  worker:
    build:
      context: ./docker/frankenphp
      args:
        UID: "${HOST_UID:-1000}"
        GID: "${HOST_GROUP_ID:-1000}"
    restart: unless-stopped
    # L'image FrankenPHP embarque un healthcheck (API admin Caddy) inutile pour un worker CLI.
    healthcheck:
      disable: true
    entrypoint: ["php"]
    command: ["bin/console", "messenger:consume", "async", "--memory-limit=192M", "--time-limit=3600", "-vv"]
    volumes:
      - ".:/var/www/html"
      - "~/.composer:/home/docker/.composer"
      - "~/.ssh:/home/docker/.ssh"
    depends_on:
      database:
        condition: service_healthy
```

- Le service `nginx` disparaît ; le service `frontend` passe à
  `VITE_API_PROXY_TARGET: http://php:8080` et reste conditionné à `php` healthy.
- Les services `php` et `worker` partagent le même contexte de build (cache Docker commun).
- Pas de volumes `caddy_data`/`caddy_config` : `auto_https off` et pas de persistance
  d'état Caddy en dev. À ajouter le jour où HTTPS est activé.

## 6. Étapes de mise en œuvre

| Phase | Contenu | Definition of Done |
|---|---|---|
| 1 — Image | Créer `docker/frankenphp/` (Dockerfile, Caddyfile, conf.d) ; builder ; lancer à la main sur le code monté | Build OK ; `frankenphp validate --config /etc/frankenphp/Caddyfile` OK ; `/api/docs` répond ; SPA servie depuis `frontend/dist` |
| 2 — Compose | Basculer le service `php` sur la nouvelle image (port, healthcheck, volumes, tty) ; supprimer `nginx` ; créer `worker` ; mettre à jour `frontend` | `docker compose up -d` : tout healthy ; parcours API + SPA + proxy Vite OK ; worker connecté au transport |
| 3 — Nettoyage | Supprimer `docker/nginx/`, `docker/php/` (run_php.sh, messenger-workers.conf, Dockerfile) ; purger les références `nginx`/`9000`/`supervisor` | `grep -ri "nginx\|9000\|supervisord"` ne renvoie plus que l'historique (`docs/plan-evolution.md`, ce plan) |
| 4 — CI & docs | Job `docker` : build + `frankenphp validate` ; README (stack, archi, Docker, arbre) ; finaliser ce plan | CI verte ; `make install` de zéro OK ; README à jour |

### Checklist de non-régression (fin de phase 4)

- [ ] `make install` (build from scratch) puis `make migrate && make fixtures`.
- [ ] `make test`, `make phpstan`, `make cs` verts.
- [ ] `POST /api/login` (admin@example.com) → token ; `GET /api/me` avec Bearer.
- [ ] Swagger UI `http://localhost:8081/api/docs` exploitable.
- [ ] `make frontend-build` : `/` sert la SPA, `/profile` renvoie `index.html` (fallback),
      `/api/inconnu` renvoie un 404 JSON.
- [ ] `docker compose up -d frontend` : `http://localhost:5173` + proxy `/api` OK.
- [ ] `docker compose logs worker` montre `Consuming messages from transport "async"`.
- [ ] Plus aucun conteneur nginx ; logs FrankenPHP visibles dans `make logs`.
- [ ] CI verte (`docker compose config`, build image, validate Caddyfile).

## 7. Risques et parades

| Risque | Parade |
|---|---|
| **Worker mode (prod, plus tard)** : état persistant entre requêtes (statics, services stateful) | Non activé en dev ; avant activation prod : audit `igor-php` + smoke test en worker mode + reset kernel Symfony (`FRANKENPHP_RESET_KERNEL`) documenté. |
| **Divergence dev/prod** (classic vs worker) | Assumé pour l'instant ; l'overlay prod sera testé en CI (smoke test) avant toute bascule. |
| **Xdebug retiré** : perte du debug pas-à-pas | Réactivation documentée (`install-php-extensions xdebug` + `XDEBUG_MODE=debug`) ; à terme, un stage de build `dev-xdebug` optionnel. |
| **Permissions volumes** (uid hôte ≠ uid conteneur) | Build args `UID`/`GID` depuis `HOST_UID`/`HOST_GROUP_ID` du Makefile. |
| **Caddyfile invalide** = conteneur down | `frankenphp validate` en CI + test local de la phase 1. |
| **Port 8080 non-root** : mapping interne modifié | Le port publié `${APP_PORT}` ne change pas ; alternative documentée si on veut garder 80 interne (`setcap CAP_NET_BIND_SERVICE`). |
| **Healthcheck lié à/ api/docs** (Twig requis) | Si les docs sont désactivées plus tard : ajouter une route Symfony `/api/health` dédiée et l'utiliser. |
| **Image upstream mouvante** (1.x) | Tag patch épinglé `1.12.7-php8.5` ; bump manuel/renovate comme les autres images. |
| **musl/Alpine** (GLOB_BRACE, extensions) | Non concerné : variante Debian Trixie choisie. |
| **`encode zstd` indisponible** | Vérifié dans l'image officielle (Symfony Docker l'utilise tel quel) ; repli `encode br gzip` trivial. |

## 8. Hors périmètre (itération suivante)

1. **Overlay prod `compose.prod.yaml`** : `APP_ENV=prod`, image sans outils dev,
   `FRANKENPHP_CONFIG="worker …"`, sources non montées, assets buildés, HTTPS/H2/H3 Caddy
   (volumes `caddy_data`/`caddy_config`), healthcheck dédié.
2. **Image prod multi-stage** (vendor + build frontend dans l'image, inspiration Symfony Docker).
3. **Xdebug** (stage dev optionnel) — décision 1.2.
4. **Mercure / hot reload FrankenPHP** : non nécessaires, le HMR Vite couvre le front
   et le mode classic recharge le PHP à chaque requête.
5. **Audit worker mode** (`igor-php`) et smoke test CI worker.
6. **Recette utilitaire** éventuelle `make worker-logs` (facultatif, `make logs` suffit).

## 9. Références

- FrankenPHP — Docker images : https://frankenphp.dev/docs/docker/
- FrankenPHP — Migrating from Nginx/PHP-FPM : https://frankenphp.dev/docs/migrate/
- FrankenPHP — Worker mode : https://frankenphp.dev/docs/worker/
- FrankenPHP — Symfony (worker mode natif depuis Symfony 7.4) : https://frankenphp.dev/docs/symfony/
- FrankenPHP — Known issues (extensions, Composer, musl) : https://frankenphp.dev/docs/known-issues/
- Symfony Docker (setup officiel FrankenPHP) : https://github.com/dunglas/symfony-docker
