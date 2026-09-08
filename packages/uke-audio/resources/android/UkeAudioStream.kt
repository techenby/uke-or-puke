package com.ukeorpuke.audio

import kotlin.math.abs
import kotlin.math.max
import kotlin.math.sqrt

/** One analysis frame, ready to be sent to PHP as an AudioFrame event. */
data class UkeAudioFrame(
    val elapsedMs: Double,
    val level: Double,
    val midi: Int?,
    val cents: Double?,
    val noteConfidence: Double,
    val chord: String?,
    val chordConfidence: Double,
    val onsetMs: Double?,
    val strumId: Int,
    val clipped: Boolean,
)

data class UkeStreamOutput(val started: Boolean, val frame: UkeAudioFrame?)

/**
 * Android twin of resources/ios/UkeAudioStream.swift. Single-threaded state: the capture loop hands
 * every buffer to one analysis thread, so `append` is never called concurrently. Sample positions come
 * from the recorder rather than wall-clock time, so dropped buffers can't shift the beat.
 */
class UkeAudioStream(private val sampleRate: Double) {
    private val analyzer = UkeSignalAnalyzer()
    private var origin: Long? = null
    private var expectedSample: Long? = null
    private var window = FloatArray(0)
    private var lastFrameMs = -1000.0
    private var previousLevel = 0.0
    private var onsetMs: Double? = null
    private var strumId = 0
    private var pendingChord: String? = null
    private var pendingChordCount = 0

    fun append(samples: FloatArray, sampleTime: Long): UkeStreamOutput {
        val started = origin == null
        if (started) {
            origin = sampleTime
        }
        val startMs = (sampleTime - origin!!) / sampleRate * 1000
        val endMs = startMs + samples.size / sampleRate * 1000

        val expected = expectedSample
        if (expected != null && expected != sampleTime) {
            window = FloatArray(0)
            onsetMs = null
            previousLevel = 0.0
            resetChordStability()
        }
        expectedSample = sampleTime + samples.size

        var squareSum = 0.0
        for (sample in samples) {
            squareSum += (sample * sample).toDouble()
        }
        val level = sqrt(squareSum / max(1, samples.size))

        if (level > 0.006 && level > max(0.004, previousLevel * 1.8) && startMs - (onsetMs ?: -1000.0) > 250) {
            onsetMs = startMs
            strumId += 1
            // Do not let the preceding chord contaminate a new attack's recognition.
            window = FloatArray(0)
            resetChordStability()
        }
        previousLevel = level

        window = window + samples
        if (window.size > 8192) {
            window = window.copyOfRange(window.size - 8192, window.size)
        }

        if (window.size < 8192 || endMs - lastFrameMs < 100) {
            return UkeStreamOutput(started, null)
        }
        lastFrameMs = endMs

        val result = analyzer.analyze(window, sampleRate)
        val clipped = window.any { abs(it) >= 0.98f }
        val stableChord = stableChord(result, clipped)

        return UkeStreamOutput(
            started,
            UkeAudioFrame(
                elapsedMs = endMs,
                level = result.level,
                midi = result.midi,
                cents = result.cents,
                noteConfidence = result.noteConfidence,
                chord = stableChord.first,
                chordConfidence = stableChord.second,
                onsetMs = onsetMs,
                strumId = strumId,
                clipped = clipped,
            ),
        )
    }

    private fun stableChord(analysis: UkeAnalysis, clipped: Boolean): Pair<String?, Double> {
        val chord = analysis.chord
        if (clipped || chord == null || analysis.chordConfidence < 0.75) {
            resetChordStability()
            return null to 0.0
        }

        if (pendingChord == chord) {
            pendingChordCount += 1
        } else {
            pendingChord = chord
            pendingChordCount = 1
        }

        if (pendingChordCount < 2) {
            return null to 0.0
        }

        return chord to analysis.chordConfidence
    }

    private fun resetChordStability() {
        pendingChord = null
        pendingChordCount = 0
    }
}
