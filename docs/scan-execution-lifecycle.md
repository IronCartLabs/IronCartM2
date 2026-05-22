# Scan-execution lifecycle (the three-fork pin)

Tracking issue: [#150](https://github.com/IronCartLabs/IronCartM2/issues/150).
Author: agent:dev sitting 2026-05-22. Status: **decision (B) — document the
intentional fork**, do not collapse.

## TL;DR

`Model\ScanEngineRunner::runAndReport()` is the shared scan engine for
**three** intentionally-forked execution sites. Each site has a different
combination of:

| Caller | `scan_run` row? | Uploads to ironcart.dev? | Visible in admin grid? |
| --- | --- | --- | --- |
| `Console\Command\ScanCommand::execute()` (CLI) | No | Only with `--upload` | No |
| `Cron\UploadScan::execute()` (cron, opt-in) | No | Yes | No |
| `Model\ScanRunConsumer::runScan()` (DB-queue, admin "Run scan now"; entry via `::process()`) | Yes | No | Yes |

There is **no shared orchestrator** above `ScanEngineRunner::runAndReport()`.
The engine itself wraps `CheckRegistry::runAll()` +
`ReportBuilder::build()` + a `ProductMetadataInterface` lookup (extracted
in [#156](https://github.com/IronCartLabs/IronCartM2/pull/156) once the
pattern hit the CLAUDE.md "3+ uses" bar). What stays forked is the
*lifecycle around* the engine call — `scan_run` row write, upload, admin
grid mark — not the engine internals.

The fork is by design (see *Why not collapse?* below), but it is
asymmetrical enough to trip a future contributor — the comment block on
`ScanEngineRunner::runAndReport()` and the
`Test/Unit/Report/ScanExecutionForkTest` together pin the three sites so
a fourth caller cannot land silently.

## Decision: (B) document, don't collapse

The two options from the issue body were:

- **(A) Collapse.** Make the cron handler enqueue through
  `ScanRunPublisher`; teach `ScanRunConsumer` to upload when the trigger
  is `cron`; `UploadScan` becomes a 10-line wrapper. Every scan
  execution produces a `scan_run` row and uploads are an outcome on
  that row.
- **(B) Document.** Keep the three sites separate, write a comment
  block on `ScanEngineRunner::runAndReport()` listing the callers and
  their lifecycle contracts, add a regression test that pins the three
  sites, and write this document.

We picked **(B)** for the same reasons IronCartWeb's `finding-states.ts`
and `delta.ts` were kept parallel — load-bearing differences that an
abstraction has to handle anyway, and not enough concrete consumers
(three is the bar, but each is *deeply* different) to pay back the
extraction cost.

### Why not collapse?

1. **CLI must not need the message-queue infrastructure.** A merchant
   running `bin/magento ironcart:scan --format=json` on a fresh install
   to triage a hot CVE cannot wait for the `cron consumers:start`
   worker to be alive. Collapsing into `ScanRunPublisher` makes the CLI
   silently dependent on the worker thread.
2. **Cron's purpose IS to upload.** The whole reason `Cron\UploadScan`
   exists (per #64) is to push findings outbound to ironcart.dev on a
   schedule. Persisting a `scan_run` row and then *also* uploading
   would double-write — admin grid AND upstream — for the operator who
   already configured continuous monitoring. The current asymmetry is
   "you get exactly one surface, the one you opted in to".
3. **Consumer's purpose is the admin grid.** The DB-queue path exists
   because the "Run Scan Now" button needs a row to render against —
   the grid is the entire user experience for that lifecycle.
4. **Opt-in network boundary.** The v3+ invariant (tracking epic
   [IronCartWeb#884](https://github.com/IronCartLabs/IronCartWeb/issues/884))
   is that the merchant module makes **no outbound HTTP** unless the
   operator explicitly enables continuous monitoring. Routing all
   scans through a single orchestrator that *could* upload pulls the
   outbound-HTTP surface into the admin "Run Scan Now" path, which is
   a regression of that invariant.
5. **No third consumer needs the unified shape.** A future cross-store
   aggregator (`monitored_store_id` joining many `scan_store`s on the
   paid Recon plan) is speculative v5+ work, and would live in
   IronCartWeb anyway — IronCartM2 ships findings, IronCartWeb does
   the aggregation. Until a concrete IronCartM2-side third consumer
   materialises, three parallel uses don't clear the CLAUDE.md "3+
   uses before extracting" bar — they are three uses of the same
   engine with three different lifecycle contracts.

The shape pattern mirrors what the IronCartWeb vault calls out in
`reference_finding_state_machine_dual_impl.md`: shared algorithm, fully
forked lifecycles, intentional drift, no abstraction.

## Per-caller state machines

### 1. `Console\Command\ScanCommand` (CLI)

```
   start
     |
     v
  parse args (--format, --upload, --output, --include-usernames, ...)
     |
     v
  ScanEngineRunner::runAndReport()  <----- shared engine
     |
     v
  ReportRenderer::render($result->report)  -->  STDOUT / file
     |
     v
  --upload ? UploadRunner::run($result->findings) : skip
     |
     v
  exit code (0 = ok, non-zero = categorical error)
```

- No `ironcart_scan_run` row.
- No admin-grid visibility.
- `--upload` is operator-explicit per run; the CLI never silently
  uploads.

### 2. `Cron\UploadScan` (cron, opt-in)

```
  cron tick (default 0 3 * * *)
     |
     v
  is `ironcart_scan/cron/enabled` set?  -- no --> return immediately
     | yes
     v
  ScanEngineRunner::runAndReport()  <----- shared engine
     |    (cron consumes only $result->findings; $result->report
     |     is built but unused)
     v
  UploadRunner::run($result->findings)  -->  ironcart.dev (mandatory)
     |
     v
  handleOutcome():
    OK         -> log success + (free-tier) push admin upgrade nag
    402        -> log + throw (cron_schedule row marked `error`)
    other err  -> log + throw (cron_schedule row marked `error`)
```

- No `ironcart_scan_run` row.
- Success surface: `var/log/ironcart_scan.log`.
- Failure surface: `cron_schedule` row + operator's standard cron-failure
  alerting.

### 3. `Model\ScanRunConsumer` (DB-queue, admin "Run Scan Now")

```
  admin click "Run Scan Now"
     |
     v
  Controller\Adminhtml\Scans\Run::execute()
     |
     v
  ScanRunPublisher::publish()
     |    INSERT INTO ironcart_scan_run (status='queued', triggered_by='admin:<id>')
     |    PUBLISH ironcart.scan.run { scan_run_id: N }
     |
     v
  (DB queue dispatches asynchronously)
     |
     v
  ScanRunConsumer::process()
     |
     |  load scan_run row by id (drop message if missing -> abort)
     |
     |  acquire drain lock (#155, republish + ack if held -> abort)
     |
     v
  ScanRunConsumer::runScan()  (private, called from process())
     |
     |  status = 'running'
     |
     v
  ScanEngineRunner::runAndReport()  <----- shared engine
     |
     v
  status = 'succeeded' or 'failed', finished_at = now()
  ScanRunTerminalState::assertConsistent(...)  (defense in depth)
     |
     v
  ironcart_scan_finding rows persisted from $result->findings;
  admin grid renders the run
```

- One `ironcart_scan_run` row per click.
- No upload to ironcart.dev (intentional — operator can pair this with
  the cron path if they want both).
- Terminal-state invariant pinned by `ScanRunTerminalState` (#76).

## When to add a fourth caller

If a future feature needs a *new* invocation of
`Model\ScanEngineRunner::runAndReport()` — e.g. an admin webhook handler
that re-scans on inbound trigger, or a sub-store-specific scan that runs
on a different schedule — the contributor MUST:

1. Add the caller to the comment block on
   `ScanEngineRunner::runAndReport()`.
2. Add the call site (`File::method`) to
   `Test/Unit/Report/ScanExecutionForkTest::EXPECTED_CALL_SITES`.
3. Document the lifecycle (does it persist a `scan_run` row? does it
   upload? where does the operator see it succeed/fail?) in a new
   section of this file.
4. Decide whether the new caller invalidates decision (B) — i.e. is
   the "fork count is small and asymmetric" rationale still true with
   four sites? If not, file an issue to revisit the collapse option.

If the fourth caller lands without updating (1) and (2), the fork test
fails the build with a pointer to this document.

## Related

- IronCartWeb memory note (algorithm-shape twin):
  `IronCartWeb/.claude/memory/reference_finding_state_machine_dual_impl.md`.
- IronCartM2-side memory note (this fork):
  `IronCartWeb/.claude/memory/reference_m2_scan_execution_forks.md`.
- Tracking epic: [IronCartLabs/IronCartWeb#884](https://github.com/IronCartLabs/IronCartWeb/issues/884).
- Adjacent issues: #143 (cron drain), #57 (upload pipeline), #100
  (consumer stall detection), #76 (terminal-state invariant), #156
  (`ScanEngineRunner` extraction once the pattern hit 3 uses).
