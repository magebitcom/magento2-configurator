# ADR 0001 — v2 Component interface

Status: **Proposed** (2026-06-08)

## Context

v1's component contract is:

```php
interface ComponentInterface {
    public function execute($data);   // untyped; returns nothing meaningful
    public function getAlias();
    public function getDescription();
}
interface FileComponentInterface extends ComponentInterface {}  // marker: get raw path, not parsed data
```

Problems this causes:

1. **Untyped `$data`** — every component re-validates shape ad hoc.
2. **Mode is informal.** The `Processor` calls `execute($data, $mode)` but the interface
   declares one param, so most components silently ignore `create` vs `maintain`.
3. **No result.** `execute()` returns nothing, so a failed import is logged and the run
   still **exits 0** — bad for deploy pipelines.
4. **Two source-shapes.** `FileComponentInterface` components get a string path; the rest
   get a parsed array. The marker interface exists only to disambiguate this.

## Decision (proposed)

Introduce three small types and one new signature.

### `ComponentMode` (enum)

```php
enum ComponentMode: string {
    case Create = 'create';      // create missing entities, leave existing untouched
    case Maintain = 'maintain';  // create + update existing to match config
}
```

### `ComponentContext` (per-source value object built by the Processor)

```php
final class ComponentContext {
    public function __construct(
        private readonly string $sourcePath,
        private readonly ComponentMode $mode,
        private readonly string $environment,
        private readonly bool $dryRun,
        private readonly \Closure $parser,   // path -> parsed array
    ) {}

    public function getSourcePath(): string { return $this->sourcePath; }
    public function getData(): array { return ($this->parser)($this->sourcePath); } // parsed on demand
    public function getMode(): ComponentMode { return $this->mode; }
    public function getEnvironment(): string { return $this->environment; }
    public function isDryRun(): bool { return $this->dryRun; }
}
```

This **removes `FileComponentInterface`**: data components call `getData()`, the few
file-based ones (SQL, Media, TaxRates pre-fix) call `getSourcePath()`. Parsing is lazy so
file components never pay for a parse they don't use.

### `ComponentResult` (returned by every component)

```php
final class ComponentResult {
    private int $created = 0;
    private int $updated = 0;
    private int $skipped = 0;
    private array $errors = [];   // string[]

    public function recordCreated(int $n = 1): void;
    public function recordUpdated(int $n = 1): void;
    public function recordSkipped(int $n = 1): void;
    public function addError(string $message): void;

    public function isSuccessful(): bool { return $this->errors === []; }
    public function getErrors(): array;
    public function merge(self $other): void;   // for run-level aggregation
    public function summary(): string;          // "created 5, updated 0, skipped 2, errors 1"
}
```

### New interface

```php
interface ComponentInterface {
    public function execute(ComponentContext $context): ComponentResult;
    public function getAlias(): string;
    public function getDescription(): string;
}
```

## Processor impact

`runComponent()` changes from "call and forget" to "build context, call, aggregate":

```php
$context = new ComponentContext($source, $mode, $this->getEnvironment(), $this->dryRun,
    fn (string $path) => $this->parseData($path, $sourceType));
$result = $component->execute($context);
$this->runResult->merge($result);
$this->log->logInfo(sprintf('%s: %s', $componentAlias, $result->summary()));
```

The `Processor` exposes `getRunResult()`, and `RunCommand::execute()` returns
`$result->isSuccessful() ? Cli::RETURN_SUCCESS : Cli::RETURN_FAILURE` — **fixing the
exit-0-on-failure problem**. A `--dry-run` CLI flag feeds `ComponentContext::isDryRun()`.

## Rollout (important: the signature change is big-bang)

Changing `ComponentInterface::execute()` means the module won't compile until **every**
component matches the new signature. So:

1. **One commit**: add the 3 new types, change both interfaces, update the `Processor` +
   commands, and mechanically adapt **all 31** components to the new signature with
   *minimal* body changes (read `$context->getData()`, return a `ComponentResult`). The
   module compiles and behaves as before; exit codes now work.
2. **Then incrementally**: fully modernize each component (as already done for
   `CustomerGroups`) — adopting `getMode()`/`isDryRun()` and granular result counters as
   we go. `CustomerGroups` gets re-touched to return a `ComponentResult`.

## Alternatives considered

- **Typed params `execute(array $data, ComponentMode $mode): ComponentResult`** — lighter,
  but can't represent the file-path source shape (the `array` type fights
  `FileComponentInterface`), and every future cross-cutting field (dry-run, environment,
  …) is another signature change. Rejected in favour of the extensible context.
- **Return `bool` instead of `ComponentResult`** — enough for exit codes, but loses the
  run summary and per-component reporting. The result object is cheap; keeping it.

## Open questions for sign-off

1. Context object (recommended) vs lighter typed-params?
2. `ComponentResult` granularity — counts + errors (recommended) vs just success/errors?
3. Land `--dry-run` plumbing now (flag in context, honored per-component later) or defer?
