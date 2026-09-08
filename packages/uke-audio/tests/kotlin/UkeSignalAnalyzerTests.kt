package com.ukeorpuke.audio

import kotlin.math.abs
import kotlin.math.exp
import kotlin.math.min
import kotlin.math.pow
import kotlin.math.sin
import kotlin.math.sqrt

// The Kotlin twin of tests/swift/UkeSignalAnalyzerTests.swift, running the same deterministic plucks
// through the Android analyzer. The printed confidences must match the Swift suite's — that agreement
// is what says both platforms will accept and reject the same playing.

private class TestFailure(val description: String) : Exception(description)

private class DeterministicNoise {
    private var state = 0x1234_5678_9ABC_DEF0uL.toLong()

    fun next(): Double {
        state = state * 6_364_136_223_846_793_005L + 1
        val unit = (state ushr 11).toDouble() / (ULong.MAX_VALUE shr 11).toDouble()
        return unit * 2 - 1
    }
}

private val analyzer = UkeSignalAnalyzer()
private var passed = 0
private var failed = 0

fun main() {
    run("reports RMS level and note details for standard tuning notes", ::testSingleNotes)
    run("recognizes C and Am across rates, levels, and detuning", ::testChordVariants)
    run("rejects chords without all three target pitch classes", ::testOtherChords)
    run("rejects silence and deterministic broadband noise", ::testSilenceAndNoise)
    run("does not mistake a single note for a chord", ::testSingleToneChordRejection)

    val benchmark = benchmarkAnalysis()
    println("BENCHMARK: %.3f ms average per 8192-sample frame (checksum %.2f)".format(benchmark.first, benchmark.second))

    println("\n$passed passed, $failed failed")
    if (failed > 0) {
        kotlin.system.exitProcess(1)
    }
}

private fun run(name: String, test: () -> Unit) {
    try {
        test()
        passed += 1
        println("PASS: $name")
    } catch (error: Exception) {
        failed += 1
        println("FAIL: $name — ${error.message}")
    }
}

private fun testSingleNotes() {
    val notes = listOf(261.63 to 60, 329.63 to 64, 392.00 to 67, 440.00 to 69)
    var minimumConfidence = 1.0

    for (sampleRate in listOf(44_100.0, 48_000.0)) {
        for ((frequency, midi) in notes) {
            val samples = synthesizePluck(listOf(frequency), sampleRate, 0.12)
            val result = analyzer.analyze(samples, sampleRate)

            require(result.frequency != null, "missing frequency for MIDI $midi at $sampleRate Hz")
            require(result.midi == midi, "expected MIDI $midi, got ${result.midi}")
            require(abs((result.frequency ?: 0.0) - frequency) < 1.2, "frequency error exceeded 1.2 Hz")
            require(abs(result.cents ?: 100.0) < 7, "cents error exceeded 7 cents")
            minimumConfidence = min(minimumConfidence, result.noteConfidence)
            require(abs(result.level - 0.12 / sqrt(2.0)) < 0.035, "unexpected RMS ${result.level}")
        }
    }

    println("  minimum note confidence: %.3f".format(minimumConfidence))
    require(minimumConfidence >= 0.80, "minimum note confidence was $minimumConfidence, below PHP's 0.8 threshold")
}

private fun testChordVariants() {
    val variants = listOf(
        "C" to listOf(392.00, 261.63, 329.63, 523.25),
        "Am" to listOf(440.00, 261.63, 329.63, 440.00),
    )
    val minimumConfidence = mutableMapOf("C" to 1.0, "Am" to 1.0)
    val minimumContext = mutableMapOf("C" to "", "Am" to "")
    val belowPHPThresholdCount = mutableMapOf("C" to 0, "Am" to 0)

    for (sampleRate in listOf(44_100.0, 48_000.0)) {
        for (detuning in listOf(-18.0, 0.0, 21.0)) {
            for (amplitude in listOf(0.045, 0.14)) {
                for ((name, frequencies) in variants) {
                    val detuned = frequencies.map { it * 2.0.pow(detuning / 1_200) }
                    val samples = synthesizePluck(detuned, sampleRate, amplitude, listOf(1.0, 0.72, 0.86, 0.58))
                    val result = analyzer.analyze(samples, sampleRate)

                    require(
                        result.chord == name,
                        "expected $name at $sampleRate Hz, $detuning cents, amplitude $amplitude; got ${result.chord}; confidence ${result.chordConfidence}",
                    )
                    if (result.chordConfidence < (minimumConfidence[name] ?: 1.0)) {
                        minimumConfidence[name] = result.chordConfidence
                        minimumContext[name] = "${sampleRate.toInt()} Hz, $detuning cents, amplitude $amplitude"
                    }
                    if (result.chordConfidence < 0.75) {
                        belowPHPThresholdCount[name] = (belowPHPThresholdCount[name] ?: 0) + 1
                    }
                }
            }
        }
    }

    for ((name, _) in variants) {
        val confidence = minimumConfidence[name] ?: 0.0
        val belowCount = belowPHPThresholdCount[name] ?: 0
        val context = minimumContext[name] ?: "unknown variant"
        println("  minimum %s confidence: %.3f (%s); %d/12 below the PHP 0.75 chord gate".format(name, confidence, context, belowCount))
        require(confidence >= 0.75, "minimum $name confidence was $confidence, below the recommended 0.75 threshold")
    }
}

private fun testOtherChords() {
    val otherChords = listOf(
        "F" to listOf(349.23, 440.00, 261.63, 349.23),
        "G" to listOf(392.00, 493.88, 293.66, 392.00),
        "D" to listOf(293.66, 369.99, 440.00, 293.66),
    )

    for ((name, frequencies) in otherChords) {
        val samples = synthesizePluck(frequencies, 48_000.0, 0.12)
        val result = analyzer.analyze(samples, 48_000.0)

        require(result.chord == null, "$name was falsely accepted as ${result.chord}")
    }
}

private fun testSilenceAndNoise() {
    val silence = analyzer.analyze(FloatArray(8_192), 48_000.0)
    require(silence.level == 0.0, "silence RMS was nonzero")
    require(silence.frequency == null && silence.chord == null, "silence produced a note or chord")
    require(silence.noteConfidence == 0.0 && silence.chordConfidence == 0.0, "silence produced confidence")

    val generator = DeterministicNoise()
    val noise = FloatArray(8_192) { (generator.next() * 0.10).toFloat() }
    val noisyResult = analyzer.analyze(noise, 48_000.0)
    require(noisyResult.frequency == null, "broadband noise produced frequency ${noisyResult.frequency}")
    require(noisyResult.chord == null, "broadband noise produced chord ${noisyResult.chord}")
}

private fun testSingleToneChordRejection() {
    for (frequency in listOf(261.63, 329.63, 392.00, 440.00)) {
        val samples = synthesizePluck(listOf(frequency), 44_100.0, 0.18)
        val result = analyzer.analyze(samples, 44_100.0)

        require(result.chord == null, "single tone $frequency Hz was accepted as ${result.chord}")
    }
}

private fun benchmarkAnalysis(): Pair<Double, Double> {
    val frames = listOf(
        synthesizePluck(listOf(392.00, 261.63, 329.63, 523.25), 48_000.0, 0.10, listOf(1.0, 0.72, 0.86, 0.58)),
        synthesizePluck(listOf(440.00, 261.63, 329.63, 440.00), 48_000.0, 0.10, listOf(1.0, 0.72, 0.86, 0.58)),
    )

    // ART and the JVM both need the hot path jitted before the timing means anything.
    for (iteration in 0 until 200) {
        analyzer.analyze(frames[iteration % frames.size], 48_000.0)
    }

    val iterationCount = 100
    var checksum = 0.0
    val start = System.nanoTime()
    for (iteration in 0 until iterationCount) {
        val result = analyzer.analyze(frames[iteration % frames.size], 48_000.0)
        checksum += result.level + result.noteConfidence + result.chordConfidence
    }
    val elapsedNanoseconds = System.nanoTime() - start

    return (elapsedNanoseconds.toDouble() / 1_000_000 / iterationCount) to checksum
}

private fun synthesizePluck(
    frequencies: List<Double>,
    sampleRate: Double,
    amplitude: Double,
    stringLevels: List<Double> = emptyList(),
): FloatArray {
    val sampleCount = 8_192

    return FloatArray(sampleCount) { index ->
        val time = index / sampleRate
        val decay = exp(-2.7 * time)
        val attack = min(1.0, time / 0.004)
        var value = 0.0

        for ((stringIndex, frequency) in frequencies.withIndex()) {
            val level = stringLevels.getOrElse(stringIndex) { 1.0 }
            val phase = stringIndex * 0.61
            value += level * (
                sin(2 * Math.PI * frequency * time + phase) +
                    0.42 * sin(2 * Math.PI * frequency * 2 * time + phase * 1.7) +
                    0.20 * sin(2 * Math.PI * frequency * 3 * time + phase * 0.8) +
                    0.10 * sin(2 * Math.PI * frequency * 4 * time + phase * 1.3)
                )
        }

        val normalization = maxOf(1.0, frequencies.size.toDouble())
        (amplitude * attack * decay * value / normalization).toFloat()
    }
}

private fun require(condition: Boolean, message: String) {
    if (!condition) {
        throw TestFailure(message)
    }
}
