import Foundation

private struct StreamTestFailure: Error, CustomStringConvertible {
    let description: String
}

@main
private enum UkeAudioStreamTests {
    private static let sampleRate = 48_000.0
    private static let bufferSize = 1_024
    private static var passed = 0
    private static var failed = 0

    static func main() {
        run("starts exactly once on the first buffer", testFirstBufferStartsOnce)
        run("uses hardware sample time for elapsed and onset timing", testHardwareTiming)
        run("stabilizes fresh C and Am while ignoring the ringing tail", testFreshStrumsAndRingingTail)
        run("emits analysis frames at approximately 10 Hz", testFrameRate)
        run("resets the analysis window and stability after a discontinuity", testDiscontinuity)
        run("reports clipping and rejects clipped or silent chords", testClippingAndSilence)

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

    private static func testFirstBufferStartsOnce() throws {
        let stream = UkeAudioStream(sampleRate: sampleRate)
        let first = stream.append([Float](repeating: 0, count: bufferSize), at: 75_000)
        let second = stream.append([Float](repeating: 0, count: bufferSize), at: 76_024)

        try require(first.started, "first buffer did not start the stream")
        try require(!second.started, "second buffer started the stream again")
    }

    private static func testHardwareTiming() throws {
        let stream = UkeAudioStream(sampleRate: sampleRate)
        let origin: Int64 = 2_000_000
        _ = stream.append([Float](repeating: 0, count: bufferSize), at: origin)
        let chord = synthesizeChord(
            frequencies: [392.00, 261.63, 329.63, 523.25],
            sampleCount: 8_192,
            amplitude: 0.14
        )
        let frames = appendInChunks(chord, to: stream, startingAt: origin + Int64(bufferSize))
        let frame = try requireLastFrame(frames)

        try requireNear(value("elapsedMs", in: frame), 192.0, tolerance: 0.01, message: "elapsed time")
        try requireNear(value("onsetMs", in: frame), 1_024 / 48.0, tolerance: 0.01, message: "onset time")
        try require(value("strumId", in: frame) == 1, "expected one timed onset")
    }

    private static func testFreshStrumsAndRingingTail() throws {
        let stream = UkeAudioStream(sampleRate: sampleRate)
        var nextSample: Int64 = 400_000

        let cAttack = synthesizeChord(
            frequencies: [392.00, 261.63, 329.63, 523.25],
            sampleCount: 13 * bufferSize,
            amplitude: 0.14
        )
        let cFrames = appendInChunks(cAttack, to: stream, startingAt: nextSample)
        nextSample += Int64(cAttack.count)

        try require(cFrames.count == 2, "expected two C analysis frames, got \(cFrames.count)")
        try require(chord(in: cFrames[0]) == nil, "C was exposed before two agreeing frames")
        try require(chord(in: cFrames[1]) == "C", "C was not exposed on the second agreeing frame")
        try require(value("chordConfidence", in: cFrames[1]) >= 0.75, "stable C confidence was below 0.75")
        try require(value("elapsedMs", in: cFrames[1]) - value("onsetMs", in: cFrames[1]) < 650, "C took at least 650 ms to stabilize")

        let cTail = synthesizeChord(
            frequencies: [392.00, 261.63, 329.63, 523.25],
            sampleCount: 5 * bufferSize,
            amplitude: 0.004,
            includeAttack: false
        )
        let tailFrames = appendInChunks(cTail, to: stream, startingAt: nextSample)
        nextSample += Int64(cTail.count)

        for frame in tailFrames {
            try require(value("strumId", in: frame) == 1, "ringing C tail created another onset")
            try require(chord(in: frame) != "Am", "ringing C tail was labeled Am")
        }

        let amAttackStart = nextSample
        let amAttack = synthesizeChord(
            frequencies: [440.00, 261.63, 329.63, 440.00],
            sampleCount: 13 * bufferSize,
            amplitude: 0.14
        )
        let amFrames = appendInChunks(amAttack, to: stream, startingAt: amAttackStart)

        try require(amFrames.count >= 2, "expected two Am analysis frames, got \(amFrames.count)")
        try require(chord(in: amFrames[0]) == nil, "Am was exposed before two agreeing frames")
        let acceptedAm = try requireFrame(named: "Am", in: amFrames)
        try require(value("strumId", in: acceptedAm) == 2, "fresh Am did not create the second onset")
        try requireNear(
            value("onsetMs", in: acceptedAm),
            Double(amAttackStart - 400_000) / sampleRate * 1_000,
            tolerance: 0.01,
            message: "fresh Am onset"
        )
        try require(value("elapsedMs", in: acceptedAm) - value("onsetMs", in: acceptedAm) < 650, "Am took at least 650 ms to stabilize")
    }

    private static func testFrameRate() throws {
        let stream = UkeAudioStream(sampleRate: sampleRate)
        let samples = synthesizeChord(
            frequencies: [392.00, 261.63, 329.63, 523.25],
            sampleCount: 48_000,
            amplitude: 0.10,
            includeAttack: false
        )
        let frames = appendInChunks(samples, to: stream, startingAt: 900_000, chunkSize: 960)
        let elapsedTimes: [Double] = frames.map { value("elapsedMs", in: $0) }

        try require(frames.count == 9, "expected 9 frames in the first second, got \(frames.count)")
        for pair in zip(elapsedTimes, elapsedTimes.dropFirst()) {
            try requireNear(pair.1 - pair.0, 100, tolerance: 0.01, message: "frame interval")
        }
    }

    private static func testDiscontinuity() throws {
        let stream = UkeAudioStream(sampleRate: sampleRate)
        let origin: Int64 = 1_500_000
        let partialC = synthesizeChord(
            frequencies: [392.00, 261.63, 329.63, 523.25],
            sampleCount: 7 * bufferSize,
            amplitude: 0.14
        )
        let beforeGap = appendInChunks(partialC, to: stream, startingAt: origin)
        try require(beforeGap.isEmpty, "partial pre-gap window emitted a frame")

        let amStart = origin + Int64(9 * bufferSize)
        let amAttack = synthesizeChord(
            frequencies: [440.00, 261.63, 329.63, 440.00],
            sampleCount: 13 * bufferSize,
            amplitude: 0.14
        )
        let afterGap = appendInChunks(amAttack, to: stream, startingAt: amStart)

        try require(afterGap.count == 2, "gap did not require a fresh analysis window")
        try require(chord(in: afterGap[0]) == nil, "gap did not reset chord stability")
        let acceptedAm = try requireFrame(named: "Am", in: afterGap)
        try requireNear(value("onsetMs", in: acceptedAm), 192, tolerance: 0.01, message: "post-gap onset")
        try requireNear(value("elapsedMs", in: acceptedAm), 1_408 / 3.0, tolerance: 0.01, message: "post-gap elapsed time")
    }

    private static func testClippingAndSilence() throws {
        let clippedStream = UkeAudioStream(sampleRate: sampleRate)
        var clippedSamples = synthesizeChord(
            frequencies: [392.00, 261.63, 329.63, 523.25],
            sampleCount: 8_192,
            amplitude: 0.14
        )
        clippedSamples[4_096] = 1
        let clippedFrame = try requireLastFrame(appendInChunks(clippedSamples, to: clippedStream, startingAt: 50_000))

        try require(value("clipped", in: clippedFrame), "clipped window was not flagged")
        try require(chord(in: clippedFrame) == nil, "clipped window exposed a chord")
        try require(value("chordConfidence", in: clippedFrame) == 0, "clipped window exposed chord confidence")

        let silentStream = UkeAudioStream(sampleRate: sampleRate)
        let silence = [Float](repeating: 0, count: 8_192)
        let silentFrame = try requireLastFrame(appendInChunks(silence, to: silentStream, startingAt: 80_000))

        try require(!value("clipped", in: silentFrame), "silence was marked clipped")
        try requireNear(value("level", in: silentFrame), 0, tolerance: 0, message: "silence level")
        try require(chord(in: silentFrame) == nil, "silence exposed a chord")
        try require(value("chordConfidence", in: silentFrame) == 0, "silence exposed chord confidence")
    }

    private static func appendInChunks(
        _ samples: [Float],
        to stream: UkeAudioStream,
        startingAt sampleTime: Int64,
        chunkSize: Int = bufferSize
    ) -> [[String: Any?]] {
        var frames: [[String: Any?]] = []
        var offset = 0

        while offset < samples.count {
            let end = min(offset + chunkSize, samples.count)
            let output = stream.append(Array(samples[offset..<end]), at: sampleTime + Int64(offset))
            if let frame = output.frame {
                frames.append(frame)
            }
            offset = end
        }

        return frames
    }

    private static func synthesizeChord(
        frequencies: [Double],
        sampleCount: Int,
        amplitude: Double,
        includeAttack: Bool = true
    ) -> [Float] {
        (0..<sampleCount).map { index in
            let time = Double(index) / sampleRate
            let attack = includeAttack ? min(1, time / 0.004) : 1
            let decay = includeAttack ? exp(-2.7 * time) : 1
            var value = 0.0

            for (stringIndex, frequency) in frequencies.enumerated() {
                let phase = Double(stringIndex) * 0.61
                value += sin(2 * Double.pi * frequency * time + phase)
                    + 0.42 * sin(2 * Double.pi * frequency * 2 * time + phase * 1.7)
                    + 0.20 * sin(2 * Double.pi * frequency * 3 * time + phase * 0.8)
                    + 0.10 * sin(2 * Double.pi * frequency * 4 * time + phase * 1.3)
            }

            return Float(amplitude * attack * decay * value / Double(frequencies.count))
        }
    }

    private static func value<T>(_ key: String, in frame: [String: Any?]) -> T {
        guard let stored = frame[key] ?? nil, let typed = stored as? T else {
            fatalError("Missing or invalid \(key) in frame")
        }

        return typed
    }

    private static func chord(in frame: [String: Any?]) -> String? {
        (frame["chord"] ?? nil) as? String
    }

    private static func requireLastFrame(_ frames: [[String: Any?]]) throws -> [String: Any?] {
        guard let frame = frames.last else {
            throw StreamTestFailure(description: "expected an analysis frame")
        }

        return frame
    }

    private static func requireFrame(named chord: String, in frames: [[String: Any?]]) throws -> [String: Any?] {
        guard let frame = frames.first(where: { self.chord(in: $0) == chord }) else {
            throw StreamTestFailure(description: "no frame exposed stable \(chord)")
        }

        return frame
    }

    private static func requireNear(
        _ actual: Double,
        _ expected: Double,
        tolerance: Double,
        message: String
    ) throws {
        try require(abs(actual - expected) <= tolerance, "\(message): expected \(expected), got \(actual)")
    }

    private static func require(_ condition: @autoclosure () -> Bool, _ message: String) throws {
        guard condition() else {
            throw StreamTestFailure(description: message)
        }
    }
}
