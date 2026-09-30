# Cable Car — agent engine

This document describes how the harness works internally. It complements the
[README](../README.md), which focuses on usage.

## 1. Layering

```text
Controller (HTTP) ─┐
                   ├─► AgentRunner ──► CodingModelInterface ──► Symfony AI platform ──► provider
Command (CLI)    ──┘        │
                            ├─► ToolRegistry ──► CodingToolInterface (6 tools)
                            ├─► Workspace / PathGuard (boundary + policy)
                            ├─► SanitizedProcessRunner (commands)
                            ├─► GitInspector (read-only git)
                            └─► AgentObserverInterface (console output, persistence, cancellation)
```

Rules:

- `AgentRunner` knows **only** `CodingModelRegistry`, `ToolRegistry`, `AgentLimits`,
  `SystemPromptBuilder`, `GitInspector` and observers. Adding a tool or a provider never
  changes the loop.
- Domain objects (`AgentMessage`, `ModelTurn`, `AgentToolCall`, `ToolResult`) are
  provider-agnostic. Only `SymfonyAiCodingModel` speaks Symfony AI messages/results.
- Persistence is an observer concern: the loop stays testable without a database.

## 2. The loop

`AgentRunner::run()`:

1. resolves the model (`cable_car.models.<name>`) and the limits (config or request override);
2. builds the `AgentContext` (workspace, task, model, limits, run id);
3. prepends the system prompt and the user task;
4. loops:

```text
while iterations < max_iterations:
    if cancelled by observer or context        → stop(user_cancelled)
    if elapsed > max_run_seconds               → stop(timeout)

    turn = model.respond(context)              # one provider call
    record the assistant message

    if turn has no tool calls:
        if turn has text                       → final answer, stop(completed)
        else                                   → stop(model_error)

    for each requested tool call (max per turn):
        if total tool calls ≥ max_tool_calls   → stop(tool_call_limit)
        validate the tool exists (otherwise: failure sent back to the model)
        result = tool.execute(arguments, ToolContext)
        append the result to the conversation (truncated to max_tool_output_bytes)

    (extra tool calls of the turn are rejected with an explanatory failure)
```

5. when the loop exits without a final answer → `stop(iteration_limit)`;
6. computes the changed files through `GitInspector` and emits the `AgentOutcome`.

Failures are data, not exceptions: a tool returning `ToolResult::failure()` is fed back to the
model so it can correct itself (unknown tool, missing file, refused path, failing test…).
Only unexpected errors abort a run (`unrecoverable_tool_error`) or a `ModelException` stops it
(`model_error`). `StopReason` enumerates every exit.

## 3. Tool contract

```php
interface CodingToolInterface
{
    public function getName(): string;              // snake_case, exposed to the model
    public function getDescription(): string;       // when to use it
    public function getInputSchema(): array;        // JSON schema object
    public function execute(array $input, ToolContext $context): ToolResult;
}
```

- Tools are Symfony services; `CodingToolInterface` is auto-tagged (`cable_car.tool`) and
  `ToolRegistry` collects them (lazily).
- `ToolRegistry::getAiTools()` converts them into Symfony AI `Tool` definitions
  (name/description/JSON schema). Symfony AI never executes them — Cable Car does.
- `AbstractTool` centralizes argument parsing, the truncation helper and the conversion of
  policy/input errors into `ToolResult::failure()`.

### The six tools

| Tool | Notes |
|---|---|
| `list_files` | Recursive listing (depth ≤ 3), skipped directories, entry cap. |
| `read_file` | Size cap (range reading for big files), binary detection, denied paths. |
| `search` | `rg` when available (globs exclude ignored dirs), PHP fallback otherwise; results filtered by the path policy. |
| `write_file` | Creates directories, refuses denied paths and oversized content. |
| `apply_patch` | Parses a unified diff, validates **all** files/hunks, then writes; a hunk that does not match exactly (within a search window) is refused with an explanation. |
| `run_command` | Allow-list + sub-command checks + process runner (see §5). |

## 4. Workspace boundary

`WorkspaceManager` resolves workspace names to directories under the configured root and
rejects anything that is not a plain directory (traversal, symlinks, hidden entries).
The console additionally accepts a path (`./repo`), which is a deliberate CLI-only affordance.

`PathGuard` is the single place where filesystem policy lives:

- rejects absolute paths, NUL bytes and `..` escapes;
- normalizes, then resolves symlinks for the deepest existing ancestor and verifies the
  result is still inside the workspace (writes to new files are validated too);
- matches the sensitive-path denylist (`fnmatch` on both the relative path and the basename);
- reports ignored directories used by the traversal tools.

Every tool obtains its guard from `PathGuardFactory` (configuration: `cable_car.workspace`).

## 5. Command execution

`CommandPolicy`:

- normalizes the model input into an argv array (preferred) or tokenizes a command string
  without any shell semantics (quotes are handled, shell metacharacters are refused);
- applies the deny-list regexes (`git push`, `git commit/reset/clean`, `sudo`, `sh -c`,
  `curl`/`wget`/`ssh`, pipes/redirections/substitutions, `../`);
- checks the executable against the allow-list;
- validates sub-commands (`git`, `composer`, `npm`/`npx`) and restricts `php` to
  `bin/console`, `bin/composer`, `vendor/bin/*` or `-l` (syntax check).

`SanitizedProcessRunner`:

- runs `/usr/bin/env -i PATH=… HOME=… LANG=… TERM=dumb CI=1 GIT_*=<safe> <argv…>`: the child
  process gets **no** inherited environment (no secrets can leak into command output);
- cwd is always the workspace root;
- polls the process, enforces the timeout (`checkTimeout()`), truncates output beyond
  `max_tool_output_bytes` and kills the process;
- `locate()` searches the configured PATH for bare executable names (never the caller's PATH).

## 6. Model layer

`CodingModelInterface` + `ModelTurn`:

```php
interface CodingModelInterface
{
    public function getName(): string;      // configuration name
    public function getLabel(): string;     // UI label
    public function getModelId(): string;   // provider model id
    public function respond(AgentContext $context): ModelTurn;
}
```

`SymfonyAiCodingModel` maps `AgentMessage[]` to Symfony AI messages
(`SystemMessage`/`UserMessage`/`AssistantMessage` with `ToolCall` parts/`ToolCallMessage`),
invokes the platform with the tool definitions and the model's provider options, and maps the
result back (`TextResult`, `ToolCallResult`, `MultiPartResult`).

`CodingModelRegistry` only exposes names, labels and model ids — the runner and the HTTP layer
never see service ids or provider details. Tests use `App\Tests\Support\FakeCodingModel`
(runner-level) and `App\Tests\Support\ScriptedPlatform` (full API-level, wired as the test
model platform in `config/services_test.yaml`).

## 7. Observers

`AgentObserverInterface` decouples the loop from its surroundings:

| Implementation | Used by | Effect |
|---|---|---|
| `NullAgentObserver` | default | nothing, never cancels. |
| `ConsoleAgentObserver` | CLI | prints model messages, tool calls/results and a final summary; polls `CancellationToken` (SIGINT/SIGTERM). |
| `PersistingAgentObserver` | worker | writes `AgentStep` rows, updates the run, polls the cancellation flag in SQL (so pending entity changes are not lost). |

Nothing hidden is ever stored: only user-facing model messages, tool inputs/results and the
final answer.

## 8. Persistence & API

`AgentRun` (uuid, user, workspace, task, model, status, stop reason, counters, final message,
error, changed files snapshot, limits, cancellation flag) and `AgentStep` (sequence, type,
tool name/input, result summary, message, success, duration).

`RunAgentMessage` is routed to the `async` transport; `RunAgentMessageHandler` loads the run,
resolves the workspace, attaches a `PersistingAgentObserver` and calls the same `AgentRunner`
as the CLI. Failures before the run starts (missing workspace) mark the run as failed without
retrying.

## 9. Testing strategy

- **Unit**: `PathGuard`, `WorkspaceManager`, each tool, `CommandPolicy`,
  `SanitizedProcessRunner`, `UnifiedDiffParser`/`PatchApplier`, `AgentLimits`, the model
  mapping (with Symfony AI's `InMemoryPlatform`).
- **Runner**: scripted fake model → tool calls → results → final answer, plus limits,
  cancellation, model errors, tool crashes, oversized outputs, malformed arguments and
  secret-leak checks.
- **Functional**: scripted platform → HTTP API → Messenger message → handler → persisted run,
  steps and changes; ownership, validation, cancellation.
- Provider-dependent smoke tests stay manual (and opt-in) to keep CI deterministic and free.
