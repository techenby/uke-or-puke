import Foundation
import Dispatch

// These tests deliberately use deterministic harmonic plucks so they can run with swiftc and no
// audio fixtures or third-party packages. They validate the analysis contract and rejection gates,
// but device microphones, room noise, playing technique, dead strings, and instrument resonances
// still need threshold validation with recordings from real high-G ukuleles before release.

private struct TestFailure: Error, CustomStringConvertible {
    let description: String
}

private struct DeterministicNoise {
    private var state: UInt64 = 0x1234_5678_9ABC_DEF0

    mutating func next() -> Double {
        state = state &* 6_364_136_223_846_793_005 &+ 1
        let unit = Double(state >> 11) / Double(UInt64.max >> 11)
        return unit * 2 - 1
    }
}

@main
private enum UkeSignalAnalyzerTests {
    private static let analyzer = UkeSignalAnalyzer()
    private static var passed = 0
    private static var failed = 0

    static func main() {
        run("reports RMS level and note details for standard tuning notes", testSingleNotes)
        run("recognizes C and Am across rates, levels, and detuning", testChordVariants)
        run("rejects chords without all three target pitch classes", testOtherChords)
        run("rejects silence and deterministic broadband noise", testSilenceAndNoise)
        run("does not mistake a single note for a chord", testSingleToneChordRejection)

        let benchmark = benchmarkAnalysis()
        print(String(format: "BENCHMARK: %.3f ms average per 8192-sample frame (checksum %.2f)", benchmark.milliseconds, benchmark.checksum))

        print("\n\(passed) passed, \(failed) failed")
        if failed > 0 {
            exit(1)
        }
    }

    private static func run(_ name: String, _ test: () throws -> Void) {
        do {
            try test()
            passed += 1
            print("PASS: \(name)")
        } catch {
            failed += 1
            print("FAIL: \(name) — \(error)")
        }
    }

    private static func testSingleNotes() throws {
        let notes: [(frequency: Double, midi: Int)] = [
            (261.63, 60),
            (329.63, 64),
            (392.00, 67),
            (440.00, 69),
        ]
        var minimumConfidence = 1.0

        for sampleRate in [44_100.0, 48_000.0] {
            for note in notes {
                let samples = synthesizePluck(frequencies: [note.frequency], sampleRate: sampleRate, amplitude: 0.12)
                let result = analyzer.analyze(samples: samples, sampleRate: sampleRate)

                try require(result.frequency != nil, "missing frequency for MIDI \(note.midi) at \(sampleRate) Hz")
                try require(result.midi == note.midi, "expected MIDI \(note.midi), got \(String(describing: result.midi))")
                try require(abs((result.frequency ?? 0) - note.frequency) < 1.2, "frequency error exceeded 1.2 Hz")
                try require(abs(result.cents ?? 100) < 7, "cents error exceeded 7 cents")
                minimumConfidence = min(minimumConfidence, result.noteConfidence)
                try require(abs(result.level - expectedRMS(amplitude: 0.12)) < 0.035, "unexpected RMS \(result.level)")
            }
        }

        print(String(format: "  minimum note confidence: %.3f", minimumConfidence))
        try require(minimumConfidence >= 0.80, "minimum note confidence was \(minimumConfidence), below PHP's 0.8 threshold")
    }

    private static func testChordVariants() throws {
        let variants: [(name: String, frequencies: [Double])] = [
            ("C", [392.00, 261.63, 329.63, 523.25]),
            ("Am", [440.00, 261.63, 329.63, 440.00]),
        ]
        var minimumConfidence = ["C": 1.0, "Am": 1.0]
        var minimumContext = ["C": "", "Am": ""]
        var belowPHPThresholdCount = ["C": 0, "Am": 0]

        for sampleRate in [44_100.0, 48_000.0] {
            for detuning in [-18.0, 0.0, 21.0] {
                for amplitude in [0.045, 0.14] {
                    for variant in variants {
                        let detuned = variant.frequencies.map { $0 * pow(2, detuning / 1_200) }
                        let samples = synthesizePluck(
                            frequencies: detuned,
                            sampleRate: sampleRate,
                            amplitude: amplitude,
                            stringLevels: [1.0, 0.72, 0.86, 0.58]
                        )
                        let result = analyzer.analyze(samples: samples, sampleRate: sampleRate)

                        try require(result.chord == variant.name, "expected \(variant.name) at \(sampleRate) Hz, \(detuning) cents, amplitude \(amplitude); got \(String(describing: result.chord)); confidence \(result.chordConfidence)")
                        if result.chordConfidence < (minimumConfidence[variant.name] ?? 1) {
                            minimumConfidence[variant.name] = result.chordConfidence
                            minimumContext[variant.name] = "\(Int(sampleRate)) Hz, \(detuning) cents, amplitude \(amplitude)"
                        }
                        if result.chordConfidence < 0.75 {
                            belowPHPThresholdCount[variant.name, default: 0] += 1
                        }
                    }
                }
            }
        }

        for variant in variants {
            let confidence = minimumConfidence[variant.name] ?? 0
            let belowCount = belowPHPThresholdCount[variant.name] ?? 0
            let context = minimumContext[variant.name] ?? "unknown variant"
            print(String(format: "  minimum %@ confidence: %.3f (%@); %d/12 below the PHP 0.75 chord gate", variant.name, confidence, context, belowCount))
            try require(confidence >= 0.75, "minimum \(variant.name) confidence was \(confidence), below the recommended 0.75 threshold")
        }
    }

    private static func testOtherChords() throws {
        let otherChords: [(name: String, frequencies: [Double])] = [
            ("F", [349.23, 440.00, 261.63, 349.23]),
            ("G", [392.00, 493.88, 293.66, 392.00]),
            ("D", [293.66, 369.99, 440.00, 293.66]),
        ]

        for chord in otherChords {
            let samples = synthesizePluck(frequencies: chord.frequencies, sampleRate: 48_000, amplitude: 0.12)
            let result = analyzer.analyze(samples: samples, sampleRate: 48_000)

            try require(result.chord == nil, "\(chord.name) was falsely accepted as \(String(describing: result.chord))")
        }
    }

    private static func testSilenceAndNoise() throws {
        let silence = analyzer.analyze(samples: [Float](repeating: 0, count: 8_192), sampleRate: 48_000)
        try require(silence.level == 0, "silence RMS was nonzero")
        try require(silence.frequency == nil && silence.chord == nil, "silence produced a note or chord")
        try require(silence.noteConfidence == 0 && silence.chordConfidence == 0, "silence produced confidence")

        var generator = DeterministicNoise()
        let noise = (0..<8_192).map { _ in Float(generator.next() * 0.10) }
        let noisyResult = analyzer.analyze(samples: noise, sampleRate: 48_000)
        try require(noisyResult.frequency == nil, "broadband noise produced frequency \(String(describing: noisyResult.frequency))")
        try require(noisyResult.chord == nil, "broadband noise produced chord \(String(describing: noisyResult.chord))")
    }

    private static func testSingleToneChordRejection() throws {
        for frequency in [261.63, 329.63, 392.00, 440.00] {
            let samples = synthesizePluck(frequencies: [frequency], sampleRate: 44_100, amplitude: 0.18)
            let result = analyzer.analyze(samples: samples, sampleRate: 44_100)

            try require(result.chord == nil, "single tone \(frequency) Hz was accepted as \(String(describing: result.chord))")
        }
    }

    private static func benchmarkAnalysis() -> (milliseconds: Double, checksum: Double) {
        let frames = [
            synthesizePluck(
                frequencies: [392.00, 261.63, 329.63, 523.25],
                sampleRate: 48_000,
                amplitude: 0.10,
                stringLevels: [1.0, 0.72, 0.86, 0.58]
            ),
            synthesizePluck(
                frequencies: [440.00, 261.63, 329.63, 440.00],
                sampleRate: 48_000,
                amplitude: 0.10,
                stringLevels: [1.0, 0.72, 0.86, 0.58]
            ),
        ]

        for iteration in 0..<8 {
            _ = analyzer.analyze(samples: frames[iteration % frames.count], sampleRate: 48_000)
        }

        let iterationCount = 100
        var checksum = 0.0
        let start = DispatchTime.now().uptimeNanoseconds
        for iteration in 0..<iterationCount {
            let result = analyzer.analyze(samples: frames[iteration % frames.count], sampleRate: 48_000)
            checksum += result.level + result.noteConfidence + result.chordConfidence
        }
        let elapsedNanoseconds = DispatchTime.now().uptimeNanoseconds - start
        let milliseconds = Double(elapsedNanoseconds) / 1_000_000 / Double(iterationCount)

        return (milliseconds, checksum)
    }

    private static func synthesizePluck(
        frequencies: [Double],
        sampleRate: Double,
        amplitude: Double,
        stringLevels: [Double] = []
    ) -> [Float] {
        let sampleCount = 8_192

        return (0..<sampleCount).map { index in
            let time = Double(index) / sampleRate
            let decay = exp(-2.7 * time)
            let attack = min(1, time / 0.004)
            var value = 0.0

            for (stringIndex, frequency) in frequencies.enumerated() {
                let level = stringLevels.indices.contains(stringIndex) ? stringLevels[stringIndex] : 1
                let phase = Double(stringIndex) * 0.61
                value += level * (
                    sin(2 * Double.pi * frequency * time + phase)
                        + 0.42 * sin(2 * Double.pi * frequency * 2 * time + phase * 1.7)
                        + 0.20 * sin(2 * Double.pi * frequency * 3 * time + phase * 0.8)
                        + 0.10 * sin(2 * Double.pi * frequency * 4 * time + phase * 1.3)
                )
            }

            let normalization = max(1, Double(frequencies.count))
            return Float(amplitude * attack * decay * value / normalization)
        }
    }

    private static func expectedRMS(amplitude: Double) -> Double {
        amplitude / sqrt(2)
    }

    private static func require(_ condition: @autoclosure () -> Bool, _ message: String) throws {
        guard condition() else {
            throw TestFailure(description: message)
        }
    }
}
