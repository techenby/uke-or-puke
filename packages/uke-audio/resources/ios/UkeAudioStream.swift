import Foundation

/// Serial-queue streaming state; hardware sample times keep dropped buffers from shifting the beat.
final class UkeAudioStream {
    private let sampleRate: Double
    private let analyzer = UkeSignalAnalyzer()
    private var origin: Int64?
    private var expectedSample: Int64?
    private var window: [Float] = []
    private var lastFrameMs = -1000.0
    private var previousLevel = 0.0
    private var onsetMs: Double?
    private var strumID = 0
    private var pendingChord: String?
    private var pendingChordCount = 0

    init(sampleRate: Double) { self.sampleRate = sampleRate }

    func append(_ samples: [Float], at sampleTime: Int64) -> (started: Bool, frame: [String: Any?]?) {
        let started = origin == nil
        if started { origin = sampleTime }
        let startMs = Double(sampleTime - origin!) / sampleRate * 1000
        let endMs = startMs + Double(samples.count) / sampleRate * 1000
        if let expectedSample, expectedSample != sampleTime {
            window.removeAll(keepingCapacity: true)
            onsetMs = nil
            previousLevel = 0
            resetChordStability()
        }
        expectedSample = sampleTime + Int64(samples.count)
        let level = sqrt(samples.reduce(0.0) { $0 + Double($1 * $1) } / Double(max(1, samples.count)))
        if level > 0.006, level > max(0.004, previousLevel * 1.8), startMs - (onsetMs ?? -1000) > 250 {
            onsetMs = startMs
            strumID += 1
            // Do not let the preceding chord contaminate a new attack's recognition.
            window.removeAll(keepingCapacity: true)
            resetChordStability()
        }
        previousLevel = level
        window.append(contentsOf: samples)
        if window.count > 8192 { window.removeFirst(window.count - 8192) }
        guard window.count >= 8192, endMs - lastFrameMs >= 100 else { return (started, nil) }
        lastFrameMs = endMs
        let result = analyzer.analyze(samples: window, sampleRate: sampleRate)
        let clipped = window.contains { abs($0) >= 0.98 }
        let stableChord = stableChord(from: result, clipped: clipped)
        return (started, [
            "elapsedMs": endMs, "level": result.level,
            "midi": result.midi, "cents": result.cents,
            "noteConfidence": result.noteConfidence,
            "chord": stableChord.name, "chordConfidence": stableChord.confidence,
            "onsetMs": onsetMs, "strumId": strumID, "clipped": clipped
        ])
    }

    private func stableChord(from analysis: UkeAnalysis, clipped: Bool) -> (name: String?, confidence: Double) {
        guard !clipped,
              let chord = analysis.chord,
              analysis.chordConfidence >= 0.75 else {
            resetChordStability()
            return (nil, 0)
        }

        if pendingChord == chord {
            pendingChordCount += 1
        } else {
            pendingChord = chord
            pendingChordCount = 1
        }

        guard pendingChordCount >= 2 else {
            return (nil, 0)
        }

        return (chord, analysis.chordConfidence)
    }

    private func resetChordStability() {
        pendingChord = nil
        pendingChordCount = 0
    }
}
