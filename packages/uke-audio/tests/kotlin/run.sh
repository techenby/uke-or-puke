#!/usr/bin/env bash
# Compiles and runs the plugin's Kotlin analysis suites on the JVM — no emulator or Gradle needed.
# Uses kotlinc from PATH, or the copy bundled with Android Studio.
set -euo pipefail

cd "$(dirname "$0")/../.."

studio="/Applications/Android Studio.app/Contents"

if command -v kotlinc > /dev/null 2>&1; then
    kotlinc=$(command -v kotlinc)
elif [ -f "$studio/plugins/Kotlin/kotlinc/bin/kotlinc" ]; then
    kotlinc="$studio/plugins/Kotlin/kotlinc/bin/kotlinc"
else
    echo "kotlinc not found — install Kotlin, or Android Studio, to run these suites." >&2
    exit 127
fi

if [ -z "${JAVA_HOME:-}" ] && [ -d "$studio/jbr/Contents/Home" ]; then
    JAVA_HOME="$studio/jbr/Contents/Home"
    export JAVA_HOME
fi

java="${JAVA_HOME:+$JAVA_HOME/bin/}java"

build=$(mktemp -d)
trap 'rm -rf "$build"' EXIT

sh "$kotlinc" -nowarn -include-runtime -d "$build/analyzer.jar" \
    resources/android/UkeSignalAnalyzer.kt \
    tests/kotlin/UkeSignalAnalyzerTests.kt

sh "$kotlinc" -nowarn -include-runtime -d "$build/stream.jar" \
    resources/android/UkeSignalAnalyzer.kt \
    resources/android/UkeAudioStream.kt \
    tests/kotlin/UkeAudioStreamTests.kt

"$java" -jar "$build/analyzer.jar"
echo
"$java" -jar "$build/stream.jar"
