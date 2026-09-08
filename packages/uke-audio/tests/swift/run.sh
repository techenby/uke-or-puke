#!/usr/bin/env bash
# Compiles and runs the plugin's Swift analysis suites with swiftc — no Xcode project needed.
# Pass --debug to build unoptimized (-Onone) to see the benchmark a Debug device build faces.
set -euo pipefail

cd "$(dirname "$0")/../.."

optimization="-O"
if [ "${1:-}" = "--debug" ]; then
    optimization="-Onone"
fi

build=$(mktemp -d)
trap 'rm -rf "$build"' EXIT

swiftc "$optimization" -o "$build/analyzer" \
    resources/ios/UkeSignalAnalyzer.swift \
    tests/swift/UkeSignalAnalyzerTests.swift

swiftc "$optimization" -o "$build/stream" \
    resources/ios/UkeSignalAnalyzer.swift \
    resources/ios/UkeAudioStream.swift \
    tests/swift/UkeAudioStreamTests.swift

"$build/analyzer"
echo
"$build/stream"
