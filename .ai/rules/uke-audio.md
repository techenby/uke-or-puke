---
paths:
  - 'packages/uke-audio/**'
---

# Uke Audio

## Run the Swift audio suites with composer test:swift
The uke-audio analyzer and stream have standalone `@main` Swift suites (no Xcode project). Run them with `composer test:swift` (add `--debug` via `packages/uke-audio/tests/swift/run.sh --debug` for an -Onone build).

Trap in those suites: the generic `value(_:in:)` helper infers `T` from the surrounding expression, so comparing against an integer literal (`value("elapsedMs", …) < 650`) infers `Int`, fails the `as? T` cast on a stored `Double`, and aborts the process with `fatalError` mid-run. Bind to an explicit `let x: Double = …` before comparing.

Their `print` output is buffered when stdout is not a TTY, so a `fatalError` swallows the preceding PASS lines — run under `script -q /dev/null` when a run dies without output.
