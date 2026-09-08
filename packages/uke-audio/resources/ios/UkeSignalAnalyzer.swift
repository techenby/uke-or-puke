import Foundation

struct UkeAnalysis {
    let level: Double
    let frequency: Double?
    let midi: Int?
    let cents: Double?
    let noteConfidence: Double
    let chord: String?
    let chordConfidence: Double
}

final class UkeSignalAnalyzer {
    private let minimumSampleCount = 512
    private let maximumSampleCount = 8_192
    private let silenceLevel = 0.002

    func analyze(samples: [Float], sampleRate: Double) -> UkeAnalysis {
        let finiteSamples = samples.map { $0.isFinite ? Double($0) : 0.0 }
        let level = rootMeanSquare(finiteSamples)

        guard sampleRate.isFinite,
              sampleRate > 0,
              finiteSamples.count >= minimumSampleCount,
              level >= silenceLevel else {
            return unknownAnalysis(level: level)
        }

        let selectedSamples = Array(finiteSamples.suffix(maximumSampleCount))
        let spectrum = makeSpectrum(samples: selectedSamples, sampleRate: sampleRate)
        let note = detectNote(in: spectrum)
        let chord = detectChord(in: spectrum)

        return UkeAnalysis(
            level: level,
            frequency: note.confidence >= 0.45 ? note.frequency : nil,
            midi: note.confidence >= 0.45 ? note.midi : nil,
            cents: note.confidence >= 0.45 ? note.cents : nil,
            noteConfidence: note.confidence,
            chord: chord.confidence >= 0.62 ? chord.name : nil,
            chordConfidence: chord.confidence
        )
    }

    private func unknownAnalysis(level: Double) -> UkeAnalysis {
        UkeAnalysis(
            level: level,
            frequency: nil,
            midi: nil,
            cents: nil,
            noteConfidence: 0,
            chord: nil,
            chordConfidence: 0
        )
    }

    private func rootMeanSquare(_ samples: [Double]) -> Double {
        guard !samples.isEmpty else {
            return 0
        }

        let squareSum = samples.reduce(0.0) { partialResult, sample in
            partialResult + sample * sample
        }

        return sqrt(squareSum / Double(samples.count))
    }

    private func makeSpectrum(samples: [Double], sampleRate: Double) -> Spectrum {
        let sampleCount = samples.count
        let fftSize = nextPowerOfTwo(max(2_048, sampleCount * 4))
        let mean = samples.reduce(0, +) / Double(sampleCount)
        var real = [Double](repeating: 0, count: fftSize)
        var imaginary = [Double](repeating: 0, count: fftSize)

        for index in samples.indices {
            let window = 0.5 - 0.5 * cos(2 * Double.pi * Double(index) / Double(sampleCount - 1))
            real[index] = (samples[index] - mean) * window
        }

        fastFourierTransform(real: &real, imaginary: &imaginary)

        let magnitudes = (0..<(fftSize / 2)).map { index in
            hypot(real[index], imaginary[index])
        }

        return Spectrum(
            magnitudes: magnitudes,
            binWidth: sampleRate / Double(fftSize)
        )
    }

    private func fastFourierTransform(real: inout [Double], imaginary: inout [Double]) {
        let count = real.count
        var reversedIndex = 0

        for index in 1..<count {
            var bit = count >> 1
            while reversedIndex & bit != 0 {
                reversedIndex ^= bit
                bit >>= 1
            }
            reversedIndex ^= bit

            if index < reversedIndex {
                real.swapAt(index, reversedIndex)
                imaginary.swapAt(index, reversedIndex)
            }
        }

        var length = 2
        while length <= count {
            let angle = -2 * Double.pi / Double(length)
            let phaseStepReal = cos(angle)
            let phaseStepImaginary = sin(angle)

            for start in stride(from: 0, to: count, by: length) {
                var phaseReal = 1.0
                var phaseImaginary = 0.0

                for offset in 0..<(length / 2) {
                    let evenIndex = start + offset
                    let oddIndex = evenIndex + length / 2
                    let oddReal = real[oddIndex] * phaseReal - imaginary[oddIndex] * phaseImaginary
                    let oddImaginary = real[oddIndex] * phaseImaginary + imaginary[oddIndex] * phaseReal

                    real[oddIndex] = real[evenIndex] - oddReal
                    imaginary[oddIndex] = imaginary[evenIndex] - oddImaginary
                    real[evenIndex] += oddReal
                    imaginary[evenIndex] += oddImaginary

                    let nextPhaseReal = phaseReal * phaseStepReal - phaseImaginary * phaseStepImaginary
                    phaseImaginary = phaseReal * phaseStepImaginary + phaseImaginary * phaseStepReal
                    phaseReal = nextPhaseReal
                }
            }

            length <<= 1
        }
    }

    private func detectNote(in spectrum: Spectrum) -> NoteResult {
        let lowerBin = spectrum.bin(for: 180)
        let upperBin = min(spectrum.bin(for: 520), spectrum.magnitudes.count - 2)
        guard lowerBin < upperBin else {
            return .unknown
        }

        var scores = [Double](repeating: 0, count: upperBin + 1)
        var bestBin = lowerBin

        for bin in lowerBin...upperBin {
            let fundamental = spectrum.magnitudes[bin]
            let harmonic2 = spectrum.magnitude(atBin: bin * 2)
            let harmonic3 = spectrum.magnitude(atBin: bin * 3)
            let harmonic4 = spectrum.magnitude(atBin: bin * 4)
            let harmonicSupport = 0.55 * harmonic2 + 0.35 * harmonic3 + 0.2 * harmonic4
            scores[bin] = fundamental + harmonicSupport

            if scores[bin] > scores[bestBin] {
                bestBin = bin
            }
        }

        let band = Array(spectrum.magnitudes[lowerBin...upperBin])
        let noiseFloor = max(median(band), Double.leastNonzeroMagnitude)
        let peakProminence = spectrum.magnitudes[bestBin] / noiseFloor
        let bestScore = scores[bestBin]
        var competingScore = 0.0

        for bin in lowerBin...upperBin where abs(bin - bestBin) > spectrum.binDistance(forCents: 80, atBin: bestBin) {
            competingScore = max(competingScore, scores[bin])
        }

        let scoreSeparation = clamp((bestScore / max(competingScore, noiseFloor) - 1) / 1.5)
        let prominenceConfidence = clamp((peakProminence - 3) / 15)
        let confidence = clamp(0.65 * prominenceConfidence + 0.35 * scoreSeparation)

        guard confidence >= 0.2 else {
            return .unknown
        }

        let refinedBin = parabolicPeakBin(around: bestBin, magnitudes: spectrum.magnitudes)
        let frequency = refinedBin * spectrum.binWidth
        let exactMidi = 69 + 12 * log2(frequency / 440)
        let midi = Int(exactMidi.rounded())
        let cents = (exactMidi - Double(midi)) * 100

        return NoteResult(frequency: frequency, midi: midi, cents: cents, confidence: confidence)
    }

    private func detectChord(in spectrum: Spectrum) -> ChordResult {
        let pitchStrengths = pitchClassStrengths(in: spectrum)
        let cScore = chordScore(expected: [0, 4, 7], discriminator: 7, competitor: 9, strengths: pitchStrengths)
        let amScore = chordScore(expected: [9, 0, 4], discriminator: 9, competitor: 7, strengths: pitchStrengths)

        let candidate: (name: String, score: Double, competingScore: Double)
        if cScore >= amScore {
            candidate = ("C", cScore, amScore)
        } else {
            candidate = ("Am", amScore, cScore)
        }

        let margin = clamp((candidate.score - candidate.competingScore) / 0.25)
        let confidence = candidate.score * (0.8 + 0.2 * margin)

        guard confidence >= 0.62 else {
            return ChordResult(name: nil, confidence: confidence)
        }

        return ChordResult(name: candidate.name, confidence: confidence)
    }

    private func pitchClassStrengths(in spectrum: Spectrum) -> [Double] {
        let broadLowerBin = spectrum.bin(for: 190)
        let broadUpperBin = min(spectrum.bin(for: 550), spectrum.magnitudes.count - 1)
        let broadFloor = max(median(Array(spectrum.magnitudes[broadLowerBin...broadUpperBin])), Double.leastNonzeroMagnitude)
        var measurements = [PitchMeasurement](repeating: .zero, count: 12)

        for midi in 60...72 {
            let frequency = 440 * pow(2, Double(midi - 69) / 12)
            let centerBin = spectrum.bin(for: frequency)
            let peakRadius = max(2, spectrum.binDistance(forCents: 42, atBin: centerBin))
            let floorRadius = max(peakRadius + 2, spectrum.binDistance(forCents: 150, atBin: centerBin))
            let peak = spectrum.maximumMagnitude(from: centerBin - peakRadius, through: centerBin + peakRadius)
            let localValues = spectrum.magnitudesInRange(from: centerBin - floorRadius, through: centerBin + floorRadius)
            let localFloor = max(median(localValues), broadFloor)
            let excess = max(0, peak - 2.5 * localFloor)
            let prominence = peak / localFloor
            let pitchClass = midi % 12

            if excess > measurements[pitchClass].excess {
                measurements[pitchClass] = PitchMeasurement(excess: excess, prominence: prominence)
            }
        }

        let maximumExcess = measurements.map(\.excess).max() ?? 0
        guard maximumExcess > broadFloor * 2 else {
            return [Double](repeating: 0, count: 12)
        }

        return measurements.map { measurement in
            let relativeStrength = measurement.excess / maximumExcess
            let prominenceGate = clamp((measurement.prominence - 3.0) / 7.0)
            return relativeStrength * prominenceGate
        }
    }

    private func chordScore(
        expected: [Int],
        discriminator: Int,
        competitor: Int,
        strengths: [Double]
    ) -> Double {
        let expectedStrengths = expected.map { strengths[$0] }
        guard expectedStrengths.allSatisfy({ $0 >= 0.16 }) else {
            return 0
        }

        let discriminatorStrength = strengths[discriminator]
        let competitorStrength = strengths[competitor]
        guard discriminatorStrength >= 0.20,
              discriminatorStrength >= competitorStrength * 1.35 + 0.04 else {
            return 0
        }

        let outsideStrengths = strengths.enumerated()
            .filter { !expected.contains($0.offset) }
            .map(\.element)
        let strongOutsideCount = outsideStrengths.filter { $0 >= 0.50 }.count
        guard strongOutsideCount <= 1 else {
            return 0
        }

        let minimumExpected = expectedStrengths.min() ?? 0
        let meanExpected = expectedStrengths.reduce(0, +) / Double(expected.count)
        let cleanliness = clamp(1 - (outsideStrengths.max() ?? 0) * 0.7)
        let discriminatorMargin = clamp((discriminatorStrength - competitorStrength - 0.04) / 0.5)

        return clamp(
            0.42 * minimumExpected
                + 0.30 * meanExpected
                + 0.16 * cleanliness
                + 0.12 * discriminatorMargin
        )
    }

    private func parabolicPeakBin(around index: Int, magnitudes: [Double]) -> Double {
        guard index > 0, index < magnitudes.count - 1 else {
            return Double(index)
        }

        let left = magnitudes[index - 1]
        let center = magnitudes[index]
        let right = magnitudes[index + 1]
        let denominator = left - 2 * center + right
        guard abs(denominator) > Double.leastNonzeroMagnitude else {
            return Double(index)
        }

        return Double(index) + 0.5 * (left - right) / denominator
    }

    private func median(_ values: [Double]) -> Double {
        guard !values.isEmpty else {
            return 0
        }

        let sorted = values.sorted()
        let middle = sorted.count / 2
        if sorted.count.isMultiple(of: 2) {
            return (sorted[middle - 1] + sorted[middle]) / 2
        }

        return sorted[middle]
    }

    private func nextPowerOfTwo(_ value: Int) -> Int {
        var result = 1
        while result < value {
            result <<= 1
        }

        return result
    }

    private func clamp(_ value: Double) -> Double {
        min(1, max(0, value))
    }
}

private struct Spectrum {
    let magnitudes: [Double]
    let binWidth: Double

    func bin(for frequency: Double) -> Int {
        min(magnitudes.count - 1, max(0, Int((frequency / binWidth).rounded())))
    }

    func magnitude(atBin bin: Int) -> Double {
        guard magnitudes.indices.contains(bin) else {
            return 0
        }

        return magnitudes[bin]
    }

    func binDistance(forCents cents: Double, atBin bin: Int) -> Int {
        let frequency = Double(bin) * binWidth
        let upperFrequency = frequency * pow(2, cents / 1_200)
        return max(1, Int(((upperFrequency - frequency) / binWidth).rounded(.up)))
    }

    func maximumMagnitude(from lowerBound: Int, through upperBound: Int) -> Double {
        magnitudesInRange(from: lowerBound, through: upperBound).max() ?? 0
    }

    func magnitudesInRange(from lowerBound: Int, through upperBound: Int) -> [Double] {
        let safeLowerBound = max(0, lowerBound)
        let safeUpperBound = min(magnitudes.count - 1, upperBound)
        guard safeLowerBound <= safeUpperBound else {
            return []
        }

        return Array(magnitudes[safeLowerBound...safeUpperBound])
    }
}

private struct NoteResult {
    let frequency: Double?
    let midi: Int?
    let cents: Double?
    let confidence: Double

    static let unknown = NoteResult(frequency: nil, midi: nil, cents: nil, confidence: 0)
}

private struct ChordResult {
    let name: String?
    let confidence: Double
}

private struct PitchMeasurement {
    let excess: Double
    let prominence: Double

    static let zero = PitchMeasurement(excess: 0, prominence: 0)
}
