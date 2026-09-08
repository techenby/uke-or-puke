package com.ukeorpuke.audio

import kotlin.math.abs
import kotlin.math.cos
import kotlin.math.hypot
import kotlin.math.log2
import kotlin.math.max
import kotlin.math.min
import kotlin.math.pow
import kotlin.math.sin
import kotlin.math.sqrt

data class UkeAnalysis(
    val level: Double,
    val frequency: Double?,
    val midi: Int?,
    val cents: Double?,
    val noteConfidence: Double,
    val chord: String?,
    val chordConfidence: Double,
)

/**
 * Android twin of resources/ios/UkeSignalAnalyzer.swift. Both files must produce the same numbers
 * for the same audio — the shared thresholds live in PHP, so a divergence here shows up as one
 * platform silently rejecting chords the other accepts.
 */
class UkeSignalAnalyzer {
    private val minimumSampleCount = 512
    private val maximumSampleCount = 8_192
    private val silenceLevel = 0.002

    fun analyze(samples: FloatArray, sampleRate: Double): UkeAnalysis {
        val finiteSamples = DoubleArray(samples.size) { index ->
            val sample = samples[index]
            if (sample.isFinite()) sample.toDouble() else 0.0
        }
        val level = rootMeanSquare(finiteSamples)

        if (!sampleRate.isFinite() || sampleRate <= 0 || finiteSamples.size < minimumSampleCount || level < silenceLevel) {
            return unknownAnalysis(level)
        }

        val selectedSamples = if (finiteSamples.size > maximumSampleCount) {
            finiteSamples.copyOfRange(finiteSamples.size - maximumSampleCount, finiteSamples.size)
        } else {
            finiteSamples
        }

        val spectrum = makeSpectrum(selectedSamples, sampleRate)
        val note = detectNote(spectrum)
        val chord = detectChord(spectrum)

        return UkeAnalysis(
            level = level,
            frequency = if (note.confidence >= 0.45) note.frequency else null,
            midi = if (note.confidence >= 0.45) note.midi else null,
            cents = if (note.confidence >= 0.45) note.cents else null,
            noteConfidence = note.confidence,
            chord = if (chord.confidence >= 0.62) chord.name else null,
            chordConfidence = chord.confidence,
        )
    }

    private fun unknownAnalysis(level: Double) = UkeAnalysis(
        level = level,
        frequency = null,
        midi = null,
        cents = null,
        noteConfidence = 0.0,
        chord = null,
        chordConfidence = 0.0,
    )

    private fun rootMeanSquare(samples: DoubleArray): Double {
        if (samples.isEmpty()) {
            return 0.0
        }

        var squareSum = 0.0
        for (sample in samples) {
            squareSum += sample * sample
        }

        return sqrt(squareSum / samples.size)
    }

    private fun makeSpectrum(samples: DoubleArray, sampleRate: Double): Spectrum {
        val sampleCount = samples.size
        val fftSize = nextPowerOfTwo(max(2_048, sampleCount * 4))
        val mean = samples.sum() / sampleCount
        val real = DoubleArray(fftSize)
        val imaginary = DoubleArray(fftSize)

        for (index in samples.indices) {
            val window = 0.5 - 0.5 * cos(2 * Math.PI * index / (sampleCount - 1))
            real[index] = (samples[index] - mean) * window
        }

        fastFourierTransform(real, imaginary)

        val magnitudes = DoubleArray(fftSize / 2) { index ->
            hypot(real[index], imaginary[index])
        }

        return Spectrum(magnitudes, sampleRate / fftSize)
    }

    /**
     * In-place iterative radix-2 transform. iOS hands this work to Accelerate; ART has no equivalent
     * system FFT, so Android keeps the hand-rolled one — see the benchmark in the Kotlin suite.
     */
    private fun fastFourierTransform(real: DoubleArray, imaginary: DoubleArray) {
        val count = real.size
        var reversedIndex = 0

        for (index in 1 until count) {
            var bit = count shr 1
            while (reversedIndex and bit != 0) {
                reversedIndex = reversedIndex xor bit
                bit = bit shr 1
            }
            reversedIndex = reversedIndex xor bit

            if (index < reversedIndex) {
                val swappedReal = real[index]
                real[index] = real[reversedIndex]
                real[reversedIndex] = swappedReal

                val swappedImaginary = imaginary[index]
                imaginary[index] = imaginary[reversedIndex]
                imaginary[reversedIndex] = swappedImaginary
            }
        }

        var length = 2
        while (length <= count) {
            val angle = -2 * Math.PI / length
            val phaseStepReal = cos(angle)
            val phaseStepImaginary = sin(angle)
            var start = 0

            while (start < count) {
                var phaseReal = 1.0
                var phaseImaginary = 0.0

                for (offset in 0 until length / 2) {
                    val evenIndex = start + offset
                    val oddIndex = evenIndex + length / 2
                    val oddReal = real[oddIndex] * phaseReal - imaginary[oddIndex] * phaseImaginary
                    val oddImaginary = real[oddIndex] * phaseImaginary + imaginary[oddIndex] * phaseReal

                    real[oddIndex] = real[evenIndex] - oddReal
                    imaginary[oddIndex] = imaginary[evenIndex] - oddImaginary
                    real[evenIndex] += oddReal
                    imaginary[evenIndex] += oddImaginary

                    val nextPhaseReal = phaseReal * phaseStepReal - phaseImaginary * phaseStepImaginary
                    phaseImaginary = phaseReal * phaseStepImaginary + phaseImaginary * phaseStepReal
                    phaseReal = nextPhaseReal
                }

                start += length
            }

            length = length shl 1
        }
    }

    private fun detectNote(spectrum: Spectrum): NoteResult {
        val lowerBin = spectrum.bin(180.0)
        val upperBin = min(spectrum.bin(520.0), spectrum.magnitudes.size - 2)
        if (lowerBin >= upperBin) {
            return NoteResult.unknown
        }

        val scores = DoubleArray(upperBin + 1)
        var bestBin = lowerBin

        for (bin in lowerBin..upperBin) {
            val fundamental = spectrum.magnitudes[bin]
            val harmonic2 = spectrum.magnitude(bin * 2)
            val harmonic3 = spectrum.magnitude(bin * 3)
            val harmonic4 = spectrum.magnitude(bin * 4)
            val harmonicSupport = 0.55 * harmonic2 + 0.35 * harmonic3 + 0.2 * harmonic4
            scores[bin] = fundamental + harmonicSupport

            if (scores[bin] > scores[bestBin]) {
                bestBin = bin
            }
        }

        val band = spectrum.magnitudes.copyOfRange(lowerBin, upperBin + 1)
        val noiseFloor = max(median(band), Double.MIN_VALUE)
        val peakProminence = spectrum.magnitudes[bestBin] / noiseFloor
        val bestScore = scores[bestBin]
        var competingScore = 0.0

        val separation = spectrum.binDistance(80.0, bestBin)
        for (bin in lowerBin..upperBin) {
            if (abs(bin - bestBin) > separation) {
                competingScore = max(competingScore, scores[bin])
            }
        }

        val scoreSeparation = clamp((bestScore / max(competingScore, noiseFloor) - 1) / 1.5)
        val prominenceConfidence = clamp((peakProminence - 3) / 15)
        val confidence = clamp(0.65 * prominenceConfidence + 0.35 * scoreSeparation)

        if (confidence < 0.2) {
            return NoteResult.unknown
        }

        val refinedBin = parabolicPeakBin(bestBin, spectrum.magnitudes)
        val frequency = refinedBin * spectrum.binWidth
        val exactMidi = 69 + 12 * log2(frequency / 440)
        val midi = Math.round(exactMidi).toInt()
        val cents = (exactMidi - midi) * 100

        return NoteResult(frequency, midi, cents, confidence)
    }

    private fun detectChord(spectrum: Spectrum): ChordResult {
        val pitchStrengths = pitchClassStrengths(spectrum)
        val cScore = chordScore(intArrayOf(0, 4, 7), 7, 9, pitchStrengths)
        val amScore = chordScore(intArrayOf(9, 0, 4), 9, 7, pitchStrengths)

        val name: String
        val score: Double
        val competingScore: Double
        if (cScore >= amScore) {
            name = "C"
            score = cScore
            competingScore = amScore
        } else {
            name = "Am"
            score = amScore
            competingScore = cScore
        }

        val margin = clamp((score - competingScore) / 0.25)
        val confidence = score * (0.8 + 0.2 * margin)

        if (confidence < 0.62) {
            return ChordResult(null, confidence)
        }

        return ChordResult(name, confidence)
    }

    private fun pitchClassStrengths(spectrum: Spectrum): DoubleArray {
        val broadLowerBin = spectrum.bin(190.0)
        val broadUpperBin = min(spectrum.bin(550.0), spectrum.magnitudes.size - 1)
        val broadFloor = max(
            median(spectrum.magnitudes.copyOfRange(broadLowerBin, broadUpperBin + 1)),
            Double.MIN_VALUE,
        )
        val measurements = Array(12) { PitchMeasurement.zero }

        for (midi in 60..72) {
            val frequency = 440 * 2.0.pow((midi - 69) / 12.0)
            val centerBin = spectrum.bin(frequency)
            val peakRadius = max(2, spectrum.binDistance(42.0, centerBin))
            val floorRadius = max(peakRadius + 2, spectrum.binDistance(150.0, centerBin))
            val peak = spectrum.maximumMagnitude(centerBin - peakRadius, centerBin + peakRadius)
            val localValues = spectrum.magnitudesInRange(centerBin - floorRadius, centerBin + floorRadius)
            val localFloor = max(median(localValues), broadFloor)
            val excess = max(0.0, peak - 2.5 * localFloor)
            val prominence = peak / localFloor
            val pitchClass = midi % 12

            if (excess > measurements[pitchClass].excess) {
                measurements[pitchClass] = PitchMeasurement(excess, prominence)
            }
        }

        val maximumExcess = measurements.maxOf { it.excess }
        if (maximumExcess <= broadFloor * 2) {
            return DoubleArray(12)
        }

        return DoubleArray(12) { pitchClass ->
            val measurement = measurements[pitchClass]
            val relativeStrength = measurement.excess / maximumExcess
            val prominenceGate = clamp((measurement.prominence - 3.0) / 7.0)
            relativeStrength * prominenceGate
        }
    }

    private fun chordScore(
        expected: IntArray,
        discriminator: Int,
        competitor: Int,
        strengths: DoubleArray,
    ): Double {
        val expectedStrengths = expected.map { strengths[it] }
        if (!expectedStrengths.all { it >= 0.16 }) {
            return 0.0
        }

        val discriminatorStrength = strengths[discriminator]
        val competitorStrength = strengths[competitor]
        if (discriminatorStrength < 0.20 || discriminatorStrength < competitorStrength * 1.35 + 0.04) {
            return 0.0
        }

        val outsideStrengths = strengths.filterIndexed { pitchClass, _ -> !expected.contains(pitchClass) }
        val strongOutsideCount = outsideStrengths.count { it >= 0.50 }
        if (strongOutsideCount > 1) {
            return 0.0
        }

        val minimumExpected = expectedStrengths.min()
        val meanExpected = expectedStrengths.sum() / expected.size
        val cleanliness = clamp(1 - (outsideStrengths.maxOrNull() ?: 0.0) * 0.7)
        val discriminatorMargin = clamp((discriminatorStrength - competitorStrength - 0.04) / 0.5)

        return clamp(
            0.42 * minimumExpected +
                0.30 * meanExpected +
                0.16 * cleanliness +
                0.12 * discriminatorMargin,
        )
    }

    private fun parabolicPeakBin(index: Int, magnitudes: DoubleArray): Double {
        if (index <= 0 || index >= magnitudes.size - 1) {
            return index.toDouble()
        }

        val left = magnitudes[index - 1]
        val center = magnitudes[index]
        val right = magnitudes[index + 1]
        val denominator = left - 2 * center + right
        if (abs(denominator) <= Double.MIN_VALUE) {
            return index.toDouble()
        }

        return index + 0.5 * (left - right) / denominator
    }

    private fun median(values: DoubleArray): Double {
        if (values.isEmpty()) {
            return 0.0
        }

        val sorted = values.sortedArray()
        val middle = sorted.size / 2
        if (sorted.size % 2 == 0) {
            return (sorted[middle - 1] + sorted[middle]) / 2
        }

        return sorted[middle]
    }

    private fun nextPowerOfTwo(value: Int): Int {
        var result = 1
        while (result < value) {
            result = result shl 1
        }

        return result
    }

    private fun clamp(value: Double) = min(1.0, max(0.0, value))
}

private class Spectrum(val magnitudes: DoubleArray, val binWidth: Double) {
    fun bin(frequency: Double): Int =
        min(magnitudes.size - 1, max(0, Math.round(frequency / binWidth).toInt()))

    fun magnitude(bin: Int): Double =
        if (bin in magnitudes.indices) magnitudes[bin] else 0.0

    fun binDistance(cents: Double, bin: Int): Int {
        val frequency = bin * binWidth
        val upperFrequency = frequency * 2.0.pow(cents / 1_200)
        return max(1, Math.ceil((upperFrequency - frequency) / binWidth).toInt())
    }

    fun maximumMagnitude(lowerBound: Int, upperBound: Int): Double =
        magnitudesInRange(lowerBound, upperBound).maxOrNull() ?: 0.0

    fun magnitudesInRange(lowerBound: Int, upperBound: Int): DoubleArray {
        val safeLowerBound = max(0, lowerBound)
        val safeUpperBound = min(magnitudes.size - 1, upperBound)
        if (safeLowerBound > safeUpperBound) {
            return DoubleArray(0)
        }

        return magnitudes.copyOfRange(safeLowerBound, safeUpperBound + 1)
    }
}

private class NoteResult(
    val frequency: Double?,
    val midi: Int?,
    val cents: Double?,
    val confidence: Double,
) {
    companion object {
        val unknown = NoteResult(null, null, null, 0.0)
    }
}

private class ChordResult(val name: String?, val confidence: Double)

private class PitchMeasurement(val excess: Double, val prominence: Double) {
    companion object {
        val zero = PitchMeasurement(0.0, 0.0)
    }
}
