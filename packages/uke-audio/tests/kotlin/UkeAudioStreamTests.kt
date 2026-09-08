package com.ukeorpuke.audio

import kotlin.math.abs
import kotlin.math.exp
import kotlin.math.min
import kotlin.math.sin

// The Kotlin twin of tests/swift/UkeAudioStreamTests.swift: same buffers, same onset and stability
// expectations, so both platforms agree on what counts as a strum and when a chord is settled.

private class StreamTestFailure(val description: String) : Exception(description)

private const val SAMPLE_RATE = 48_000.0
private const val BUFFER_SIZE = 1_024
private var passed = 0
private var failed = 0

fun main() {
    run("starts exactly once on the first buffer", ::testFirstBufferStartsOnce)
    run("uses recorder sample positions for elapsed and onset timing", ::testHardwareTiming)
    run("stabilizes fresh C and Am while ignoring the ringing tail", ::testFreshStrumsAndRingingTail)
    run("scores a repeated strum of the same chord as a fresh attack", ::testRepeatedStrumsOfTheSameChord)
    run("emits analysis frames at approximately 10 Hz", ::testFrameRate)
    run("resets the analysis window and stability after a discontinuity", ::testDiscontinuity)
    run("reports clipping and rejects clipped or silent chords", ::testClippingAndSilence)

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

private fun testFirstBufferStartsOnce() {
    val stream = UkeAudioStream(SAMPLE_RATE)
    val first = stream.append(FloatArray(BUFFER_SIZE), 75_000)
    val second = stream.append(FloatArray(BUFFER_SIZE), 76_024)

    require(first.started, "first buffer did not start the stream")
    require(!second.started, "second buffer started the stream again")
}

private fun testHardwareTiming() {
    val stream = UkeAudioStream(SAMPLE_RATE)
    val origin = 2_000_000L
    stream.append(FloatArray(BUFFER_SIZE), origin)
    val chord = synthesizeChord(listOf(392.00, 261.63, 329.63, 523.25), 8_192, 0.14)
    val frames = appendInChunks(chord, stream, origin + BUFFER_SIZE)
    val frame = requireLastFrame(frames)

    requireNear(frame.elapsedMs, 192.0, 0.01, "elapsed time")
    requireNear(frame.onsetMs ?: -1.0, 1_024 / 48.0, 0.01, "onset time")
    require(frame.strumId == 1, "expected one timed onset")
}

private fun testFreshStrumsAndRingingTail() {
    val stream = UkeAudioStream(SAMPLE_RATE)
    val cFrequencies = listOf(392.00, 261.63, 329.63, 523.25)
    var nextSample = 400_000L

    val cAttack = synthesizeChord(cFrequencies, 13 * BUFFER_SIZE, 0.14)
    val cFrames = appendInChunks(cAttack, stream, nextSample)
    nextSample += cAttack.size

    require(cFrames.size == 2, "expected two C analysis frames, got ${cFrames.size}")
    require(cFrames[0].chord == null, "C was exposed before two agreeing frames")
    require(cFrames[1].chord == "C", "C was not exposed on the second agreeing frame")
    require(cFrames[1].chordConfidence >= 0.75, "stable C confidence was below 0.75")
    require(cFrames[1].elapsedMs - (cFrames[1].onsetMs ?: 0.0) < 650, "C took at least 650 ms to stabilize")

    val cTail = synthesizeChord(cFrequencies, 5 * BUFFER_SIZE, 0.004, includeAttack = false)
    val tailFrames = appendInChunks(cTail, stream, nextSample)
    nextSample += cTail.size

    for (frame in tailFrames) {
        require(frame.strumId == 1, "ringing C tail created another onset")
        require(frame.chord != "Am", "ringing C tail was labeled Am")
    }

    val amAttackStart = nextSample
    val amAttack = synthesizeChord(listOf(440.00, 261.63, 329.63, 440.00), 13 * BUFFER_SIZE, 0.14)
    val amFrames = appendInChunks(amAttack, stream, amAttackStart)

    require(amFrames.size >= 2, "expected two Am analysis frames, got ${amFrames.size}")
    require(amFrames[0].chord == null, "Am was exposed before two agreeing frames")
    val acceptedAm = requireFrame("Am", amFrames)
    require(acceptedAm.strumId == 2, "fresh Am did not create the second onset")
    requireNear(acceptedAm.onsetMs ?: -1.0, (amAttackStart - 400_000) / SAMPLE_RATE * 1_000, 0.01, "fresh Am onset")
    require(acceptedAm.elapsedMs - (acceptedAm.onsetMs ?: 0.0) < 650, "Am took at least 650 ms to stabilize")
}

private fun testRepeatedStrumsOfTheSameChord() {
    val stream = UkeAudioStream(SAMPLE_RATE)
    val cFrequencies = listOf(392.00, 261.63, 329.63, 523.25)
    var nextSample = 600_000L

    val firstAttack = synthesizeChord(cFrequencies, 13 * BUFFER_SIZE, 0.14)
    val firstFrames = appendInChunks(firstAttack, stream, nextSample)
    nextSample += firstAttack.size

    require(requireFrame("C", firstFrames).strumId == 1, "first strum was not the first onset")

    val decay = synthesizeChord(cFrequencies, 5 * BUFFER_SIZE, 0.004, includeAttack = false)
    appendInChunks(decay, stream, nextSample)
    nextSample += decay.size

    val secondAttackStart = nextSample
    val secondAttack = synthesizeChord(cFrequencies, 13 * BUFFER_SIZE, 0.14)
    val secondFrames = appendInChunks(secondAttack, stream, secondAttackStart)

    require(secondFrames[0].chord == null, "repeated C was exposed before two agreeing frames")
    val secondAccepted = requireFrame("C", secondFrames)
    require(secondAccepted.strumId == 2, "repeating the same chord did not create a second onset")
    requireNear(
        secondAccepted.onsetMs ?: -1.0,
        (secondAttackStart - 600_000) / SAMPLE_RATE * 1_000,
        0.01,
        "repeated strum onset",
    )
    require(secondAccepted.elapsedMs - (secondAccepted.onsetMs ?: 0.0) < 650, "repeated C took at least 650 ms to stabilize")
}

private fun testFrameRate() {
    val stream = UkeAudioStream(SAMPLE_RATE)
    val samples = synthesizeChord(listOf(392.00, 261.63, 329.63, 523.25), 48_000, 0.10, includeAttack = false)
    val frames = appendInChunks(samples, stream, 900_000, chunkSize = 960)
    val elapsedTimes = frames.map { it.elapsedMs }

    require(frames.size == 9, "expected 9 frames in the first second, got ${frames.size}")
    for (index in 0 until elapsedTimes.size - 1) {
        requireNear(elapsedTimes[index + 1] - elapsedTimes[index], 100.0, 0.01, "frame interval")
    }
}

private fun testDiscontinuity() {
    val stream = UkeAudioStream(SAMPLE_RATE)
    val origin = 1_500_000L
    val partialC = synthesizeChord(listOf(392.00, 261.63, 329.63, 523.25), 7 * BUFFER_SIZE, 0.14)
    val beforeGap = appendInChunks(partialC, stream, origin)
    require(beforeGap.isEmpty(), "partial pre-gap window emitted a frame")

    val amStart = origin + 9 * BUFFER_SIZE
    val amAttack = synthesizeChord(listOf(440.00, 261.63, 329.63, 440.00), 13 * BUFFER_SIZE, 0.14)
    val afterGap = appendInChunks(amAttack, stream, amStart)

    require(afterGap.size == 2, "gap did not require a fresh analysis window")
    require(afterGap[0].chord == null, "gap did not reset chord stability")
    val acceptedAm = requireFrame("Am", afterGap)
    requireNear(acceptedAm.onsetMs ?: -1.0, 192.0, 0.01, "post-gap onset")
    requireNear(acceptedAm.elapsedMs, 1_408 / 3.0, 0.01, "post-gap elapsed time")
}

private fun testClippingAndSilence() {
    val clippedStream = UkeAudioStream(SAMPLE_RATE)
    val clippedSamples = synthesizeChord(listOf(392.00, 261.63, 329.63, 523.25), 8_192, 0.14)
    clippedSamples[4_096] = 1f
    val clippedFrame = requireLastFrame(appendInChunks(clippedSamples, clippedStream, 50_000))

    require(clippedFrame.clipped, "clipped window was not flagged")
    require(clippedFrame.chord == null, "clipped window exposed a chord")
    require(clippedFrame.chordConfidence == 0.0, "clipped window exposed chord confidence")

    val silentStream = UkeAudioStream(SAMPLE_RATE)
    val silentFrame = requireLastFrame(appendInChunks(FloatArray(8_192), silentStream, 80_000))

    require(!silentFrame.clipped, "silence was marked clipped")
    requireNear(silentFrame.level, 0.0, 0.0, "silence level")
    require(silentFrame.chord == null, "silence exposed a chord")
    require(silentFrame.chordConfidence == 0.0, "silence exposed chord confidence")
}

private fun appendInChunks(
    samples: FloatArray,
    stream: UkeAudioStream,
    startingAt: Long,
    chunkSize: Int = BUFFER_SIZE,
): List<UkeAudioFrame> {
    val frames = mutableListOf<UkeAudioFrame>()
    var offset = 0

    while (offset < samples.size) {
        val end = min(offset + chunkSize, samples.size)
        val output = stream.append(samples.copyOfRange(offset, end), startingAt + offset)
        output.frame?.let { frames.add(it) }
        offset = end
    }

    return frames
}

private fun synthesizeChord(
    frequencies: List<Double>,
    sampleCount: Int,
    amplitude: Double,
    includeAttack: Boolean = true,
): FloatArray = FloatArray(sampleCount) { index ->
    val time = index / SAMPLE_RATE
    val attack = if (includeAttack) min(1.0, time / 0.004) else 1.0
    val decay = if (includeAttack) exp(-2.7 * time) else 1.0
    var value = 0.0

    for ((stringIndex, frequency) in frequencies.withIndex()) {
        val phase = stringIndex * 0.61
        value += sin(2 * Math.PI * frequency * time + phase) +
            0.42 * sin(2 * Math.PI * frequency * 2 * time + phase * 1.7) +
            0.20 * sin(2 * Math.PI * frequency * 3 * time + phase * 0.8) +
            0.10 * sin(2 * Math.PI * frequency * 4 * time + phase * 1.3)
    }

    (amplitude * attack * decay * value / frequencies.size).toFloat()
}

private fun requireLastFrame(frames: List<UkeAudioFrame>): UkeAudioFrame =
    frames.lastOrNull() ?: throw StreamTestFailure("expected an analysis frame")

private fun requireFrame(chord: String, frames: List<UkeAudioFrame>): UkeAudioFrame =
    frames.firstOrNull { it.chord == chord } ?: throw StreamTestFailure("no frame exposed stable $chord")

private fun requireNear(actual: Double, expected: Double, tolerance: Double, message: String) {
    require(abs(actual - expected) <= tolerance, "$message: expected $expected, got $actual")
}

private fun require(condition: Boolean, message: String) {
    if (!condition) {
        throw StreamTestFailure(message)
    }
}
