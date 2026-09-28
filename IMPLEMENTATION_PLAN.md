# Cable Car — MVP implementation plan

> Experimental coding harness built with Symfony AI.
>
> **Cable Car** gives a language model a small, controlled set of tools to inspect, modify and validate a codebase. The goal is to understand and demonstrate the core mechanics of a coding agent without trying to reproduce Codex or Claude Code.

## 1. MVP goal

Build a minimal but genuinely usable coding harness where a user can give an instruction such as:

```text
Add a Symfony console command that displays the application version.
```

Cable Car must then be able to:

1. understand the task;
2. inspect the repository;
3. decide which tool to call;
4. read/search relevant files;
5. modify files;
6. run commands or tests;
7. observe tool results;
8. continue the loop until the task is complete or a limit is reached;
9. present a concise final summary and the files changed.

The point of the MVP is the **agent/tool loop**, not sophisticated autonomous software engineering.

## 2. Starting point

Initialize this repository from **lsoulier42/react-symfony-starter** and preserve its conventions.

Existing stack to keep:

- PHP 8.5 / Symfony 8.1;
- API Platform 4;
- Doctrine ORM + PostgreSQL;
- JWT authentication;
- React 19 + TypeScript + Vite;
- Tailwind CSS 4;
- TanStack Query;
- Docker Compose;
- PHPUnit / PHPStan / frontend lint/build tooling.

Add Symfony AI and Symfony Process where required.

The React application is the primary MVP interface, but the agent engine must be independent from HTTP so the same harness can also be exercised from a Symfony console command.

## 3. Core design principle

Keep the first version deliberately small.

Conceptually:

```text
User task
   |
   v
Agent Runner
   |
   v
LLM <---------------------------+
   |                             |
   | tool call                   |
   v                             |
Tool Registry                    |
   |                             |
   v                             |
Tool execution                   |
   |                             |
   +------ result / error -------+
   |
   v
Final answer
```

Cable Car should orchestrate this loop itself. Symfony AI handles model interaction and tool/function integration where appropriate, while Cable Car owns:

- run state;
- tool definitions;
- workspace boundaries;
- execution policy;
- iteration limits;
- persistence;
- UI/API contract.

## 4. MVP scope

### Included

- one coding-agent loop;
- one workspace/repository per run;
- model configuration through Symfony AI;
- read-only filesystem tools;
- controlled file-writing tools;
- controlled shell execution;
- iteration/tool-call limits;
- run history;
- step/tool-call inspection;
- diff/changed-files summary;
- React UI to start and inspect runs;
- Symfony CLI entry point;
- cancellation/timeouts;
- tests using a fake model and temporary repositories.

### Explicitly out of scope

Do not implement before the MVP works end-to-end:

- multi-agent orchestration;
- sub-agents;
- MCP;
- browser tools;
- web search;
- GitHub issue/PR automation;
- automatic git push;
- automatic commits;
- remote execution;
- arbitrary host filesystem access;
- container-per-run sandboxing;
- RAG/code embeddings;
- long-term memory;
- IDE integration;
- voice;
- image understanding;
- background autonomous task scheduling.

In particular, **the MVP must never push code to Git automatically**.

## 5. Provider strategy

Cable Car should not couple its agent engine to a specific LLM provider.

Create an application abstraction around Symfony AI so that the runner depends on a model interface rather than directly on a provider SDK.

Suggested concept:

```php
interface CodingModelInterface
{
    public function respond(AgentContext $context): AgentResponse;
}
```

The first provider can be whichever Symfony AI-supported model gives reliable tool calling.

Keep provider/model configuration in environment/configuration.

### Golden Gate integration

Design the model layer so Cable Car can later consume **Golden Gate** as an OpenAI-compatible gateway.

This is a post-MVP integration, not a dependency required to get Cable Car working.

Target future flow:

```text
Cable Car
    |
    v
Golden Gate
    |
    +--> OVHcloud
    +--> OpenAI
    +--> Mistral
    +--> ...
```

Cable Car should therefore avoid provider-specific assumptions in its domain/application layers.

## 6. Workspace model

A coding harness is dangerous if its filesystem scope is vague.

Introduce an explicit `Workspace` abstraction.

For the MVP, a workspace is a local directory mounted/available to the application and explicitly allowed in configuration.

Example:

```yaml
cable_car:
  workspaces:
    root: '%env(CABLE_CAR_WORKSPACES_ROOT)%'
```

Every run operates inside:

```text
<CABLE_CAR_WORKSPACES_ROOT>/<workspace>
```

Rules:

- resolve all paths to canonical absolute paths;
- reject path traversal;
- reject symlink escapes;
- never allow filesystem tools outside the workspace;
- never expose application/provider secrets to the model;
- exclude configured sensitive paths such as `.env.local`, private keys and credential files.

Do not let the model choose arbitrary host paths.

## 7. Core domain model

### AgentRun

Represents one user task.

Suggested fields:

- `id` UUID;
- user relation;
- workspace identifier/path reference;
- task;
- status;
- model;
- createdAt;
- startedAt;
- finishedAt;
- iterationCount;
- toolCallCount;
- finalMessage nullable;
- stopReason nullable;
- error nullable.

Suggested statuses:

- `pending`;
- `running`;
- `completed`;
- `failed`;
- `cancelled`;
- `limit_reached`.

### AgentStep

Persist enough information to understand what happened during a run.

Suggested fields:

- `id`;
- run relation;
- sequence;
- type;
- tool name nullable;
- normalized tool input nullable;
- normalized result summary nullable;
- success;
- duration;
- createdAt.

Possible step types:

- `model`;
- `tool_call`;
- `tool_result`;
- `final`;
- `error`.

Avoid persisting hidden chain-of-thought or asking the model to reveal it. Store observable actions and concise model messages only.

### ChangedFile

This can initially be calculated rather than persisted.

Expose:

- path;
- status: created/modified/deleted;
- diff when available.

## 8. Tool architecture

Define a small generic contract.

Example:

```php
interface CodingToolInterface
{
    public function getName(): string;

    public function getDescription(): string;

    public function getInputSchema(): array;

    public function execute(array $input, ToolContext $context): ToolResult;
}
```

Register tools through Symfony DI tags so adding a tool does not require modifying the runner.

Suggested structure:

```text
src/
  Agent/
    Runner/
      AgentRunner.php
      AgentContext.php
      AgentLimits.php
    Model/
      CodingModelInterface.php
      SymfonyAiCodingModel.php
    Tool/
      CodingToolInterface.php
      ToolRegistry.php
      ToolContext.php
      ToolResult.php
      ReadFileTool.php
      ListFilesTool.php
      SearchTool.php
      WriteFileTool.php
      ApplyPatchTool.php
      RunCommandTool.php
    Workspace/
      Workspace.php
      WorkspaceManager.php
      PathGuard.php
    DTO/
    Exception/
```

The runner must know only the tool registry, not concrete tools.

## 9. MVP tools

Start with a very small toolbox.

### `list_files`

Purpose:

- inspect directory contents;
- optionally recurse to a controlled depth.

Inputs:

- relative path;
- optional depth.

Protection:

- workspace boundary;
- ignored directories such as `.git`, `vendor`, `node_modules` by default.

### `read_file`

Purpose:

- read a text file;
- optionally read a line range.

Inputs:

- relative path;
- optional start/end lines.

Protection:

- maximum bytes/lines;
- binary-file rejection;
- sensitive-file denylist.

### `search`

Purpose:

- search text across the repository.

Prefer a predictable implementation using a local search command such as `rg` when available, wrapped behind the tool abstraction.

Inputs:

- query;
- optional path/glob;
- result limit.

Protection:

- workspace only;
- output truncation;
- timeout.

### `write_file`

Purpose:

- create or replace a text file.

Inputs:

- path;
- content.

Before writing:

- validate workspace path;
- reject sensitive files;
- enforce file-size limit.

### `apply_patch`

Purpose:

- make targeted modifications without rewriting an entire file.

This should become the preferred editing tool once stable.

Validate that the patch applies cleanly and return a useful error otherwise.

### `run_command`

Purpose:

- run project commands such as tests, linters and builds.

This is the highest-risk MVP tool and must be constrained.

Do **not** implement it as unrestricted `sh -c`.

Use Symfony Process with:

- workspace as working directory;
- argument-array execution where possible;
- timeout;
- output limit;
- sanitized environment;
- explicit command policy.

Initial allow-list can cover common development commands such as:

- `php`;
- `bin/console`;
- `composer`;
- `vendor/bin/phpunit`;
- `vendor/bin/phpstan`;
- `npm`;
- `git diff`;
- `git status`.

Explicitly block commands intended to mutate remote state, including `git push`.

## 10. Agent loop

Implement the loop explicitly so its behavior stays understandable.

Pseudo-flow:

```text
create run
build system instructions
add user task

repeat:
    enforce limits

    response = model(messages, available tools)

    if response is final:
        persist final step
        complete run
        return

    for requested tool call:
        validate tool
        execute tool
        persist observable call/result
        append result to context

until completed or stopped
```

The system instructions should tell the agent to:

- inspect before editing;
- make the smallest reasonable change;
- stay inside the workspace;
- use tools rather than invent file contents;
- validate its work when possible;
- avoid unrelated refactors;
- finish with a concise summary and validation results.

Do not implement elaborate planning/replanning machinery in the MVP.

## 11. Limits and stop conditions

Agent loops need hard boundaries.

Create `AgentLimits` configuration with values such as:

- maximum iterations;
- maximum total tool calls;
- maximum tool calls per model turn;
- maximum run duration;
- maximum command duration;
- maximum tool output size;
- maximum file read size.

Example initial defaults:

```text
max_iterations: 20
max_tool_calls: 50
max_run_seconds: 600
max_command_seconds: 120
```

Values should be configurable, not scattered constants.

Stop reasons should be explicit:

- completed;
- user cancelled;
- iteration limit;
- tool-call limit;
- timeout;
- model error;
- unrecoverable tool error.

## 12. Git integration for change visibility

Git is useful for observation, not remote automation.

For MVP:

- detect whether workspace is a Git repository;
- capture initial status;
- expose final `git status --short`;
- expose `git diff --stat`;
- expose `git diff`;
- optionally expose untracked file contents in the changed-files view.

Do not automatically:

- commit;
- create branches;
- push;
- reset;
- checkout destructive changes.

The user remains responsible for accepting/committing the result.

## 13. Symfony Console interface

Create a CLI entry point early because it is the fastest way to test the harness.

Example:

```bash
php bin/console cable-car:run ./workspace \
  "Add a Symfony command that displays the application version"
```

The command should display observable events as they happen:

```text
[agent] Inspecting project structure
[tool]  list_files .
[tool]  read_file composer.json
[tool]  search "Command" src/
[tool]  write_file src/Command/VersionCommand.php
[tool]  run_command vendor/bin/phpunit
[agent] Task completed
```

Do not print hidden reasoning. Show only model messages intended for the user, tool actions and results.

The CLI and HTTP API must call the same `AgentRunner`.

## 14. Execution model

For the first vertical slice, synchronous execution is acceptable.

Before adding the full React experience, move runs to Symfony Messenger if required to avoid HTTP request lifetime limits.

Preferred final MVP:

```text
POST run
   |
   v
AgentRun persisted
   |
   v
Messenger message
   |
   v
worker -> AgentRunner
   |
   v
AgentStep records
   |
   v
React polls run/steps
```

The starter already includes Symfony Messenger, so reuse it rather than introducing another queue.

Polling is enough for MVP. WebSockets/SSE can come later.

## 15. Administration/API surface

Reuse the starter's JWT-authenticated API.

Suggested endpoints:

```text
GET    /api/agent/workspaces
POST   /api/agent/runs
GET    /api/agent/runs
GET    /api/agent/runs/{id}
POST   /api/agent/runs/{id}/cancel
GET    /api/agent/runs/{id}/steps
GET    /api/agent/runs/{id}/changes
```

Example run creation:

```json
{
  "workspace": "my-project",
  "task": "Add a health-check endpoint and tests.",
  "model": "default"
}
```

Do not accept arbitrary absolute workspace paths from the HTTP API.

## 16. React UI

Keep the UI deliberately functional.

### New run

Provide:

- workspace selector;
- task textarea;
- model selector if more than one model is configured;
- Run button.

### Run detail

Show:

- task;
- status;
- elapsed time;
- model;
- iteration/tool-call counters;
- chronological activity stream;
- current/final response;
- Cancel action while running.

Tool events should be visually distinct and collapsible.

Examples:

```text
READ   composer.json
SEARCH "UserRepository"
EDIT   src/Repository/UserRepository.php
RUN    vendor/bin/phpunit
       exit 0 · 42 tests passed
```

### Changes

Show:

- changed file list;
- status;
- diff.

A simple readable diff is sufficient. Do not build a full IDE.

### Run history

Show previous runs with:

- task excerpt;
- workspace;
- status;
- model;
- date;
- duration.

## 17. Safety model

The MVP is a developer tool that can modify code and execute processes. Safety boundaries are therefore part of the core implementation, not polish.

Required:

- explicit workspace root;
- canonical path validation;
- symlink escape protection;
- sensitive-file denylist;
- command allow-list/policy;
- sanitized process environment;
- process timeout;
- output truncation;
- file-size limits;
- iteration/tool-call limits;
- no remote Git mutations;
- no arbitrary network/browser tool;
- cancellation support;
- audit trail of tool calls.

The model must never receive provider secrets, JWT signing keys, database credentials or unrelated environment variables.

## 18. Phase 0 — Import the starter

Populate `cable-car` from `react-symfony-starter`.

Tasks:

- copy starter while keeping Cable Car's Git history;
- update package/project branding;
- update README;
- verify Docker boot;
- verify migrations;
- verify existing backend tests;
- verify frontend build/lint.

**Acceptance criterion:** Cable Car boots locally with starter authentication working.

## 19. Phase 1 — Smallest possible agent loop

Implement:

- Symfony AI model integration;
- `CodingModelInterface`;
- `ToolRegistry`;
- `list_files`;
- `read_file`;
- `search`;
- synchronous `AgentRunner`;
- CLI command.

No writes yet.

Test with tasks such as:

```text
Find where JWT authentication is configured and explain it.
```

**Acceptance criterion:** the model autonomously selects tools, inspects a repository and returns a grounded answer.

## 20. Phase 2 — Code modification

Add:

- `write_file`;
- `apply_patch`;
- changed-file detection;
- system instructions for minimal edits.

Test with a small deterministic task.

**Acceptance criterion:** Cable Car can inspect a repository, make a targeted edit and report the diff.

## 21. Phase 3 — Validation commands

Add controlled `run_command`.

Support the starter's main quality commands first.

The agent should be able to:

1. modify code;
2. run a relevant test/linter;
3. inspect failure output;
4. fix the code;
5. rerun validation;
6. finish.

This is the point where Cable Car becomes a real coding harness rather than a file-editing chatbot.

## 22. Phase 4 — Persistence and async runs

Add:

- `AgentRun`;
- `AgentStep`;
- Doctrine migrations;
- Messenger execution;
- cancellation;
- persisted limits/status/stop reasons.

Use a fake model implementation for deterministic runner tests.

**Acceptance criterion:** a run survives the initiating HTTP request and its observable history can be retrieved afterward.

## 23. Phase 5 — React interface

Implement:

- workspace list;
- new-run form;
- run activity timeline;
- run status;
- cancellation;
- final answer;
- changed-files/diff view;
- run history.

Do not redesign the whole starter. Make the harness interaction the focus.

## 24. Phase 6 — Hardening

Before calling the MVP complete:

- test path traversal;
- test symlink escapes;
- test sensitive-file reads;
- test blocked commands;
- test command timeout;
- test run timeout;
- test iteration/tool-call limits;
- test cancellation;
- test model/provider failures;
- test malformed tool arguments;
- test oversized outputs;
- verify no application secrets enter model context/logs.

## 25. Testing strategy

### Unit tests

Cover:

- `PathGuard`;
- workspace resolution;
- tool registry;
- each tool;
- command policy;
- limit counters;
- stop conditions;
- context construction.

### Agent runner tests

Create a deterministic fake `CodingModelInterface` that emits scripted tool calls.

Example scripted sequence:

```text
model -> list_files
model -> read_file
model -> write_file
model -> run_command
model -> final
```

This lets the complete loop be tested without spending tokens or relying on provider behavior.

### Functional tests

Cover:

- creating a run;
- retrieving steps;
- cancellation;
- unauthorized access;
- workspace validation;
- changed-file endpoint.

### Optional smoke tests

Real-model tests must be opt-in and excluded from normal CI to avoid cost and nondeterminism.

## 26. Suggested implementation order

Work in vertical slices.

### Slice A — read-only agent

- import starter;
- Symfony AI;
- model abstraction;
- tool contract/registry;
- workspace guard;
- list/read/search;
- CLI runner.

### Slice B — coding agent

- write/apply patch;
- changed-file detection;
- controlled command execution;
- validation/fix loop.

### Slice C — durable harness

- AgentRun/AgentStep;
- limits;
- Messenger;
- cancellation;
- API.

### Slice D — UI

- start run;
- activity stream;
- run history;
- diff viewer.

### Slice E — hardening

- security tests;
- failure handling;
- docs;
- example tasks;
- clean-install verification.

## 27. MVP definition of done

Cable Car MVP is done when all of the following are demonstrable:

1. project starts from a clean clone using the starter's Docker workflow;
2. an authenticated user can select an allowed workspace and submit a coding task;
3. the agent can autonomously inspect files using tools;
4. the agent can search the repository;
5. the agent can create/modify files;
6. the agent can run an allowed validation command;
7. a failed validation can be fed back to the model for a corrective iteration;
8. every filesystem operation remains inside the workspace;
9. dangerous/unapproved commands are rejected;
10. runs have configurable iteration, tool-call and time limits;
11. runs can be cancelled;
12. observable tool calls/results are persisted;
13. the UI shows progress, final response and changed files/diff;
14. the same runner works from Symfony Console;
15. tests exercise the loop with a fake model without external API calls;
16. no hidden chain-of-thought is stored or displayed;
17. no secrets are sent to the model through environment leakage;
18. Cable Car never automatically commits or pushes user code.

## 28. Post-MVP candidates

Only after the definition of done:

- Golden Gate as the default model gateway;
- streaming/SSE activity updates;
- richer patch editing;
- repository instruction files;
- per-project command policies;
- Docker/container sandbox per run;
- Git worktrees for isolated runs;
- checkpoint/revert;
- optional commit creation with explicit user approval;
- GitHub issue-to-patch workflow;
- PR creation with explicit user approval;
- MCP tools;
- code indexing/RAG;
- context compaction;
- model routing;
- token/cost accounting;
- sub-agents;
- parallel tool calls;
- approval gates for sensitive tools;
- reusable agent profiles.

The architectural rule should remain: **Symfony AI owns model integration; Cable Car owns the coding loop, tools, workspace and execution policy.**
