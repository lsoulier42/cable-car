<h1 align="center">Cable Car</h1>

<p align="center">
  <a href="https://www.php.net/releases/8.5/en.php"><img alt="PHP 8.5" src="https://img.shields.io/badge/PHP-8.5-777BB4?style=flat-square&logo=php&logoColor=white" /></a>
  <a href="https://symfony.com/releases/8.1"><img alt="Symfony 8.1" src="https://img.shields.io/badge/Symfony-8.1-000000?style=flat-square&logo=symfony&logoColor=white" /></a>
  <a href="https://github.com/symfony/ai"><img alt="Symfony AI" src="https://img.shields.io/badge/Symfony%20AI-0.14-6c8cff?style=flat-square" /></a>
  <a href="https://frankenphp.dev"><img alt="FrankenPHP" src="https://img.shields.io/badge/FrankenPHP-1.12-0B5C6B?style=flat-square" /></a>
  <a href="https://react.dev"><img alt="React 19" src="https://img.shields.io/badge/React-19-61DAFB?style=flat-square&logo=react&logoColor=white" /></a>
  <a href="https://www.postgresql.org/"><img alt="PostgreSQL 18" src="https://img.shields.io/badge/PostgreSQL-18-336791?style=flat-square&logo=postgresql&logoColor=white" /></a>
  <a href="LICENSE"><img alt="License: MIT" src="https://img.shields.io/badge/License-MIT-2ea44f?style=flat-square" /></a>
</p>

<p align="center">
  An experimental <strong>coding agent harness</strong>: a small, controlled set of tools
  (inspect, edit, validate), an explicit agent loop, and a hard safety boundary around the
  repository — built with <strong>Symfony AI</strong> on a Symfony 8.1 + React 19 stack.
</p>

---

## Table of contents

- [What it is](#-what-it-is)
- [What it does (and does not)](#-what-it-does-and-does-not)
- [How it works](#-how-it-works)
- [Quick start](#-quick-start)
- [Running a task](#-running-a-task)
- [Configuration](#-configuration)
- [Agent toolkit](#-agent-toolkit)
- [Safety model](#-safety-model)
- [Limits & stop conditions](#-limits--stop-conditions)
- [HTTP API](#-http-api)
- [Persistence & worker](#-persistence--worker)
- [Frontend](#-frontend)
- [Testing](#-testing)
- [Code quality](#-code-quality)
- [Docker](#-docker)
- [Project structure](#-project-structure)
- [Roadmap](#-roadmap)
- [License](#-license)

---

## 🚂 What it is

Cable Car gives a language model a **small, deliberate set of tools** to inspect, modify and
validate a codebase, and runs the resulting loop itself:

```text
User task
   │
   ▼
Agent Runner ──────────────► Symfony AI platform ──────────────► model
   ▲                                                              │
   │                                              tool calls      │
   │                                                              ▼
   └──── tool results ◄──────── Tool registry ◄──── workspace, path guard,
                                                  command policy, limits
```

The point of the project is the **agent/tool loop** — not a reimplementation of Codex or
Claude Code. Symfony AI owns the model integration; Cable Car owns the loop, the tools, the
workspace boundary, the execution policy, the limits, the persistence and the UI contract.

Highlights:

- **One runner, two front doors**: the same `AgentRunner` is used by the Symfony console
  command and by the HTTP API (through a Messenger worker).
- **Workspace-scoped filesystem**: every path is resolved to a canonical absolute path inside
  an explicit workspace; traversal, symlink escapes and sensitive files are refused.
- **No shell, ever**: commands run as argument arrays with a sanitized environment, an
  allow-list of executables and a hard timeout.
- **Observable by design**: tool calls, results and model messages are persisted as steps —
  never hidden reasoning.
- **Git for visibility, never for automation**: Cable Car reports `status` and `diff`, and
  never commits, branches or pushes.

---

## ✅ What it does (and does not)

**Included in the MVP**

- one coding-agent loop, one workspace per run;
- read tools: `list_files`, `read_file`, `search`;
- write tools: `write_file`, `apply_patch` (unified diff);
- controlled execution: `run_command` (allow-listed, no shell, sanitized env, timeout);
- configurable iteration / tool-call / time / output limits with explicit stop reasons;
- durable run history (`agent_run`, `agent_step`) + Messenger worker + cancellation;
- changed-file detection and diffs (Git repositories);
- React UI: new run, live activity stream, final answer, changes, history;
- console entry point (`cable-car:run`) sharing the exact same runner;
- tests with a scripted model and temporary workspaces — no provider calls.

**Deliberately out of scope (post-MVP)**

- multi-agent orchestration, sub-agents, MCP, browser tools, web search;
- GitHub/Jira automation, automatic commits or pushes;
- container-per-run sandboxing, remote execution;
- RAG/code embeddings, long-term memory, IDE plugins, voice, images;
- background scheduling.

> Cable Car **never** pushes code to Git, and never commits without an explicit user action.

---

## 🧠 How it works

1. **Run creation** — the UI (or the console) creates an `AgentRun` with a workspace name, a
   task, and a model name.
2. **Dispatch** — the API persists the run and dispatches a `RunAgentMessage`; the worker
   consumes it (the console command calls the runner directly).
3. **Loop** — the runner builds the system instructions, sends the task to the model with the
   tool definitions, executes the tool calls it asks for, feeds the results back and repeats
   until the model answers without tool calls, or a limit is hit.
4. **Observation** — every model message, tool call, tool result and the final answer become
   `AgentStep` rows; the UI polls the run and renders the timeline.
5. **Changes** — the workspace is inspected with Git to list changed files and their diffs.
   The user reviews and commits; the agent never does.

The interface between Cable Car and the model layer is a single contract:

```php
interface CodingModelInterface
{
    public function respond(AgentContext $context): ModelTurn;
}
```

`SymfonyAiCodingModel` implements it on top of Symfony AI platforms, so the runner never
depends on a provider SDK. The provider is configuration (`cable_car.models`), which is also
how a future **Golden Gate** (OpenAI-compatible gateway) integration plugs in.

Read [`docs/agent.md`](docs/agent.md) for the detailed engine documentation.

---

## 🚀 Quick start

```bash
git clone git@github.com:lsoulier42/cable-car.git
cd cable-car

# 1. Build images, install backend + frontend dependencies, start, generate JWT keys
make install

# 2. Create the database schema and a couple of users
make migrate
make fixtures          # admin@example.com / password, user1..3@example.com / password

# 3. Put a repository inside the workspaces directory
mkdir -p var/workspaces
git clone <some-repository> var/workspaces/my-project
```

- SPA (Vite dev server): <http://localhost:5174>
- API + Swagger UI: <http://localhost:8082/api/docs>
- Mailpit: <http://localhost:1182>

Sign in with `admin@example.com` / `password`, then open **Runs → Nouvelle tâche**.

### Choosing a model

The default provider is a **local Ollama** server (no API key needed):

```bash
ollama pull qwen3:4b          # any tool-calling capable model works
```

`OLLAMA_ENDPOINT` defaults to `http://localhost:11434`. When the application runs in Docker
and Ollama runs on the host, set the following in `.env.local`:

```dotenv
OLLAMA_ENDPOINT=http://host.docker.internal:11434
```

Any OpenAI-compatible endpoint can be used instead: configure
`ai.platform.generic.golden_gate` (`GOLDEN_GATE_BASE_URL`, `GOLDEN_GATE_API_KEY`) and add a
model entry pointing at it. No provider-specific code is needed.

---

## ▶️ Running a task

### Console (fastest way to try the harness)

```bash
# A workspace name (directory under CABLE_CAR_WORKSPACES_ROOT)…
php bin/console cable-car:run my-project "Add a Symfony command that displays the application version"

# …or an explicit path (console only)
php bin/console cable-car:run ./var/workspaces/my-project "Fix the failing test in tests/Api/LoginTest.php"

# Pick another model / list them
php bin/console cable-car:run my-project "…" --model=golden_gate
php bin/console cable-car:run --list-models
```

Output is an observable activity stream — no hidden reasoning:

```text
▸ workspace demo · model default (qwen3:4b)
▸ task Add a console command that displays the application version.

[tool]  list_files path="." depth=1
        → composer.json (+3 lignes) (3 ms)
[agent] I will look at the project structure and the console entry point.
[tool]  read_file path="src/Kernel.php"
        → src/Kernel.php (lines 1-13 of 13): (+13 lignes) (1 ms)
[tool]  write_file path="src/Command/VersionCommand.php"
        → Created src/Command/VersionCommand.php (23 lines, 601 B). (2 ms)
[tool]  run_command argv=["php","-l","src/Command/VersionCommand.php"]
        → $ php -l src/Command/VersionCommand.php … (52 ms)

✔ Tâche terminée · 5 itérations · 5 appels d'outils · 42.1 s

Réponse finale
Added src/Command/VersionCommand.php …

Fichiers modifiés
  A src/Command/VersionCommand.php
```

### Web UI

- **Nouveau run** (`/agent/runs/new`) — workspace, model, task, examples.
- **Run detail** (`/agent/runs/{id}`) — live status, activity stream (tool events are
  collapsible), final answer, changed files with diffs, cancel button.
- **History** (`/agent/runs`) — task excerpt, workspace, status, model, date, duration.

The detail page polls while a run is `pending` or `running` (1.5 s), then stops.

---

## ⚙️ Configuration

The harness is configured under the `cable_car` key
([`config/packages/cable_car.yaml`](config/packages/cable_car.yaml)); environment variables
live in [`.env`](.env) (override with `.env.local`).

| Variable | Default | Description |
|---|---|---|
| `CABLE_CAR_WORKSPACES_ROOT` | `var/workspaces` | Directory containing the allowed workspaces. |
| `CABLE_CAR_MODEL` | `default` | Model used when a run does not choose one. |
| `CABLE_CAR_MAX_RUN_SECONDS` | `600` | Maximum duration of a run (raise it for slow local models). |
| `CABLE_CAR_OLLAMA_MODEL` | `qwen3:4b` | Model served by Ollama (must support tools). |
| `OLLAMA_ENDPOINT` | `http://localhost:11434` | Ollama endpoint. |
| `GOLDEN_GATE_BASE_URL` / `GOLDEN_GATE_API_KEY` | — | OpenAI-compatible gateway (post-MVP). |
| `APP_PORT` | `8082` | Host port of the FrankenPHP API/SPA. |
| `FRONTEND_PORT` | `5174` | Host port of the Vite dev server. |
| `DATABASE_HOST_PORT` | `5732` | Host port mapped to PostgreSQL. |
| `MAILER_SMTP_PORT` / `MAILER_HTTP_PORT` | `1127` / `1182` | Host ports of Mailpit. |

Model entries support extra provider options (forwarded as-is to the platform):

```yaml
cable_car:
    models:
        default:
            label: 'Ollama · %env(CABLE_CAR_OLLAMA_MODEL)%'
            platform: 'ai.platform.ollama'
            model: '%env(CABLE_CAR_OLLAMA_MODEL)%'
            options:
                think: false        # reasoning models need this to call tools reliably
                num_predict: 2048
```

Limits, executed commands and the path policy are all configurable in the same file —
see [Safety model](#-safety-model) and [Limits](#-limits--stop-conditions).

---

## 🧰 Agent toolkit

| Tool | Purpose | Protection |
|---|---|---|
| `list_files` | Explore the project structure (depth ≤ 3). | Workspace boundary, ignored directories (`.git`, `vendor`, `node_modules`, `var/cache`, …), entry cap. |
| `read_file` | Read a text file, optionally a line range. | Sensitive-file denylist, size cap, binary rejection. |
| `search` | Regex search across the repository (ripgrep, PHP fallback). | Denied/skipped paths filtered out, result cap, timeout. |
| `write_file` | Create or fully replace a file. | Sensitive-file denylist, size cap. |
| `apply_patch` | Apply a unified diff (preferred edit path). | All hunks validated before anything is written. |
| `run_command` | Run tests, linters, static analysis, builds, read-only git. | Allow-list, argument-array execution, no shell, sanitized env, timeout, output cap, no remote mutations. |

Tools are Symfony services: implement `CodingToolInterface` and the registry picks the tool up
(interface auto-tagging) — the runner never changes.

---

## 🔒 Safety model

A coding agent that can edit files and run processes needs explicit boundaries. They are part
of the core implementation:

- **Explicit workspace root** — configured, never chosen by the model; the HTTP API only
  accepts workspace *names*.
- **Canonical path validation** — `..`, absolute paths and NUL bytes are refused; symlinks are
  resolved and escapes rejected (`PathGuard`).
- **Sensitive-file denylist** — `.env*`, `*.pem`, `*.key`, `id_rsa*`, `.ssh/**`, `auth.json`,
  `config/secrets/**`, … can neither be read nor written.
- **Command allow-list** — `php`, `bin/console`, `composer`, `vendor/bin/phpunit|phpstan|phpcs`,
  `node`, `npm`, `git`; sub-commands are checked too (`git push`, `git commit`, `git reset`,
  `composer config`, unknown `npx` packages… are refused).
- **No shell** — commands are executed as argument arrays through `/usr/bin/env -i`, so
  pipes, redirections, substitutions and `sh -c` are impossible.
- **Sanitized environment** — only `PATH`, `HOME`, `LANG`, `TERM`, `CI` and git-safety
  variables reach a child process: no application secret, no provider key, no database
  credentials.
- **Output and time caps** — every process has a timeout and its output is truncated; every
  tool result is capped before being sent to the model.
- **Limits and stop reasons** — the loop cannot run forever (see below).
- **Cancellation** — the UI/API can cancel a run; the worker polls the flag between steps.
- **Audit trail** — every tool call and result is persisted as a step.
- **No remote Git mutations** — the agent produces changes; accepting them is your job.

---

## ⏱ Limits & stop conditions

Defaults (configurable under `cable_car.limits`):

| Limit | Default |
|---|---|
| `max_iterations` (model turns) | 20 |
| `max_tool_calls` (per run) | 50 |
| `max_tool_calls_per_turn` | 4 |
| `max_run_seconds` | 600 |
| `max_command_seconds` | 120 |
| `max_tool_output_bytes` | 32 KB |
| `max_file_read_bytes` | 128 KB |
| `max_file_write_bytes` | 256 KB |
| `max_list_entries` | 500 |
| `max_search_results` | 100 |

Explicit stop reasons are persisted on the run: `completed`, `user_cancelled`,
`iteration_limit`, `tool_call_limit`, `timeout`, `model_error`, `unrecoverable_tool_error`.
The UI status maps them to `completed`, `cancelled`, `limit_reached` or `failed`.

---

## 🌐 HTTP API

All endpoints require a JWT (`POST /api/login`); runs are private to their creator
(administrators can inspect every run).

| Method | Path | Description |
|---|---|---|
| `GET` | `/api/agent/workspaces` | Allowed workspaces (names only). |
| `GET` | `/api/agent/models` | Configured models + default. |
| `POST` | `/api/agent/runs` | Create a run: `{workspace, task, model?}` → 201. |
| `GET` | `/api/agent/runs` | Run history for the current user (`?limit=`). |
| `GET` | `/api/agent/runs/{uuid}` | Run detail **with its steps**. |
| `POST` | `/api/agent/runs/{uuid}/cancel` | Request cancellation (409 when finished). |
| `GET` | `/api/agent/runs/{uuid}/steps` | Activity stream. |
| `GET` | `/api/agent/runs/{uuid}/changes` | Changed files with diffs. |

```bash
TOKEN=$(curl -s localhost:8082/api/login -H 'Content-Type: application/json' \
  -d '{"email":"admin@example.com","password":"password"}' | jq -r .token)

curl -s localhost:8082/api/agent/runs \
  -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
  -d '{"workspace":"my-project","task":"Add a health-check endpoint and tests."}' | jq
```

The console command and the HTTP API both call the same `AgentRunner`; only the observation
layer differs (console output vs. persisted steps).

---

## 🗃 Persistence & worker

| Table | Content |
|---|---|
| `agent_run` | Task, workspace, model, status, stop reason, counters, final message, error, changed files, limits, cancellation flag. |
| `agent_step` | Observable steps: `model`, `tool_call`, `tool_result`, `final`, `error` (tool name/input, truncated result, duration). |

`POST /api/agent/runs` only creates the run and dispatches `RunAgentMessage` on the `async`
transport (Doctrine), consumed by the `worker` container. A run therefore survives the
originating HTTP request, and its history can be retrieved afterwards.

---

## 🖥 Frontend

React 19 + TypeScript + Vite + Tailwind CSS 4 + TanStack Query, following the starter's
design system (strict dark theme, French UI).

| Screen | Route | Content |
|---|---|---|
| Runs | `/agent/runs` | History: task excerpt, workspace, status, model, date, duration. |
| New run | `/agent/runs/new` | Workspace + model selectors, task textarea, examples. |
| Run detail | `/agent/runs/:id` | Status, counters, activity stream (collapsible tool events), final answer, changed files + diffs, cancel. |
| Login / Register / Profile / Users | `/login`, … | Starter authentication and profile screens. |

```bash
cd frontend
npm install
npm run dev      # http://localhost:5174
npm run lint
npm run build
```

---

## 🧪 Testing

```bash
make test        # PHPUnit in the container (or: php bin/phpunit)
```

- **Unit tests** cover `PathGuard`, workspace resolution, every tool, the command policy, the
  unified-diff engine, the process runner, limits/stop conditions and the model mapping.
- **Runner tests** use a scripted fake model (`tests/Support/FakeCodingModel.php`) to drive the
  complete loop without any provider call, including failures, cancellation and limits.
- **Functional tests** cover the HTTP API end to end with a scripted Symfony AI platform
  (`tests/Support/ScriptedPlatform.php`): create a run, execute it, retrieve steps and changes,
  cancellation, ownership and workspace validation.
- Hardening cases (traversal, symlink escape, denylist, blocked commands, timeouts, oversized
  outputs, tool crashes, secret leakage) are part of the suite.

Real-model smoke tests are opt-in: the default test model is the scripted platform, so CI never
spends tokens.

---

## 🔍 Code quality

| Tool | Command | Configuration |
|---|---|---|
| Static analysis (PHP) | `vendor/bin/phpstan analyse` | `phpstan.dist.neon` — level 6. |
| Code style (PHP) | `vendor/bin/phpcs` | `phpcs.xml.dist` — PSR-12. |
| Tests | `php bin/phpunit` | `phpunit.xml.dist` — PHPUnit 12. |
| Frontend lint | `npm run lint` (in `frontend/`) | ESLint. |
| Frontend types/build | `npm run build` (in `frontend/`) | `tsc --noEmit` + `vite build`. |

---

## 🐳 Docker

| Service | Image / build | Role |
|---|---|---|
| `database` | `postgres:18.2-alpine` | PostgreSQL 18 (host port `5732`). |
| `php` | build `docker/frankenphp` | FrankenPHP (Caddy + PHP 8.5): API + SPA, host port `8082`. |
| `worker` | same build | `messenger:consume async`: executes agent runs. |
| `frontend` | `node:24-alpine` (dev) | Vite dev server (host port `5174`). |
| `mailer` | `axllent/mailpit` | SMTP sink + web UI (`1127` / `1182`). |

Notes:

- The agent executes commands **inside the `php`/`worker` containers**, with the workspace
  mounted at the same path: install the tools a project needs (PHP, Composer, Node…) there.
- To reach a host Ollama from a container: set
  `OLLAMA_ENDPOINT=http://host.docker.internal:11434` (the compose file declares
  `host.docker.internal`), or add the service to `docker-compose.yaml`.
- `make agent ARGS='my-project "Add a health-check endpoint"'` runs the CLI inside the container.

Useful recipes: `make start|stop|logs|worker-logs|migrate|fixtures|test|phpstan|cs|frontend-build|destroy`.

---

## 🗂 Project structure

```
src/
├── Agent/
│   ├── DTO/            # AgentMessage, ModelTurn, AgentToolCall, ChangedFile
│   ├── Exception/      # domain failures (path, policy, limits, model…)
│   ├── Git/            # GitInspector: status / diff / changed files (read-only)
│   ├── Http/           # AgentRunAccess: ownership checks
│   ├── Messenger/      # RunAgentMessage + handler (worker entry point)
│   ├── Model/          # CodingModelInterface, SymfonyAiCodingModel, registry
│   ├── Patch/          # unified diff parser + applier
│   ├── Process/        # SanitizedProcessRunner (no shell, sanitized env, caps)
│   ├── Runner/         # AgentRunner (the loop), context, limits, observers
│   ├── Tool/           # CodingToolInterface, registry + the six tools
│   └── Workspace/      # Workspace, WorkspaceManager, PathGuard(+Factory)
├── Command/            # cable-car:run
├── Controller/Agent/   # workspaces, runs, steps, changes, cancel
├── DependencyInjection/ # cable_car extension + configuration tree
├── Dto/                # validated inputs + payloads
├── Entity/             # AgentRun, AgentStep (+ status/step enums), User
└── Repository/         # AgentRunRepository, AgentStepRepository, UserRepository

tests/
├── Support/            # FakeCodingModel, ScriptedPlatform, StubTool, TempWorkspace
├── Unit/Agent/…        # PathGuard, tools, patch engine, process, runner, model
└── Functional/Agent/   # end-to-end API + worker + persistence
```

---

## 🗺 Roadmap

After the MVP is fully validated: Golden Gate as the default model gateway, SSE/streaming
activity, richer patch editing, repository instruction files, per-project command policies,
container-per-run sandboxing, Git worktrees, checkpoint/revert, optional commit creation with
explicit approval, GitHub issue-to-patch, MCP tools, code indexing, cost accounting, sub-agents.

Architectural rule to preserve: **Symfony AI owns model integration; Cable Car owns the coding
loop, the tools, the workspace and the execution policy.**

---

## 📄 License

Distributed under the **MIT** license — see the [LICENSE](LICENSE) file.
