---
paths:
  - 'packages/uke-audio/**'
---

# Uke Audio

## Run the Swift audio suites with composer test:swift
The uke-audio analyzer and stream have standalone `@main` Swift suites (no Xcode project). Run them with `composer test:swift` (add `--debug` via `packages/uke-audio/tests/swift/run.sh --debug` for an -Onone build).

Trap in those suites: the generic `value(_:in:)` helper infers `T` from the surrounding expression, so comparing against an integer literal (`value("elapsedMs", …) < 650`) infers `Int`, fails the `as? T` cast on a stored `Double`, and aborts the process with `fatalError` mid-run. Bind to an explicit `let x: Double = …` before comparing.

Their `print` output is buffered when stdout is not a TTY, so a `fatalError` swallows the preceding PASS lines — run under `script -q /dev/null` when a run dies without output.

## Keep the Swift and Kotlin analyzers in step
`resources/ios/UkeSignalAnalyzer.swift` and `resources/android/UkeSignalAnalyzer.kt` (plus the two `UkeAudioStream` files) are twins: same algorithm, same numbers. The accept/reject thresholds live in PHP (`Arcade`/`Soundcheck` gate chords at 0.75, notes at 0.8), so a drift between them means one platform silently rejects playing the other accepts. Change both, then run both suites and compare the printed confidences — they must match (currently note 0.900, C 0.843, Am 0.764, benchmark checksum 151.74).

iOS runs the FFT on Accelerate (`vDSP_fft_zripD`, one cached setup); ART has no system FFT, so Android keeps the hand-rolled radix-2 one. That asymmetry is deliberate — don't "fix" it by reverting iOS.

Run the Kotlin suites with `composer test:kotlin` (kotlinc from PATH or Android Studio's bundled copy; JVM only, no emulator).
