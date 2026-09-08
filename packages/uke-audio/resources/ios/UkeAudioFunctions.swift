import AVFoundation
import UIKit

enum UkeAudioFunctions {
    class Start: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            guard let id = parameters["sessionId"] as? String, !id.isEmpty else {
                return BridgeResponse.error(code: "INVALID_SESSION", message: "A session ID is required.")
            }
            DispatchQueue.main.async { UkeMicrophone.shared.start(id: id) }
            return BridgeResponse.success(data: ["success": true])
        }
    }

    class Stop: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            guard let id = parameters["sessionId"] as? String else {
                return BridgeResponse.error(code: "INVALID_SESSION", message: "A session ID is required.")
            }
            DispatchQueue.main.async { UkeMicrophone.shared.stop(id: id) }
            return BridgeResponse.success(data: ["success": true])
        }
    }
}

/// Owns the audio session on the main queue. Raw audio stays in memory on device.
private final class UkeMicrophone {
    static let shared = UkeMicrophone()
    private var sessionID: String?
    private var engine: AVAudioEngine?
    private var tapInstalled = false
    private var receivedAudio = false
    private var observers: [NSObjectProtocol] = []
    private let queue = DispatchQueue(label: "uke.audio.analysis", qos: .userInitiated)

    func start(id: String) {
        if let previous = sessionID { stop(id: previous) }
        sessionID = id
        status(id, "requesting", "Allow microphone access to hear your ukulele.")
        AVAudioApplication.requestRecordPermission { allowed in
            DispatchQueue.main.async {
                guard self.sessionID == id else { return }
                guard allowed else {
                    self.stop(id: id, reason: "denied", message: "Microphone access is off. Enable it in Settings, then try again.")
                    return
                }
                self.capture(id: id)
            }
        }
    }

    private func capture(id: String) {
        do {
            receivedAudio = false
            let session = AVAudioSession.sharedInstance()
            try session.setCategory(.record, mode: .measurement)
            try session.setPreferredSampleRate(48000)
            try session.setPreferredIOBufferDuration(0.02)
            try session.setActive(true)
            let engine = AVAudioEngine()
            self.engine = engine
            let input = engine.inputNode
            let format = input.outputFormat(forBus: 0)
            guard format.sampleRate > 0, format.channelCount > 0 else {
                throw NSError(domain: "UkeAudio", code: 1, userInfo: [NSLocalizedDescriptionKey: "No microphone input is available."])
            }
            let stream = UkeAudioStream(sampleRate: format.sampleRate)
            let available = DispatchSemaphore(value: 1)
            input.installTap(onBus: 0, bufferSize: 1024, format: format) { buffer, time in
                // Bound outstanding work; the audio callback never waits for analysis or PHP.
                guard available.wait(timeout: .now()) == .success else { return }
                guard let channel = buffer.floatChannelData?[0], time.isSampleTimeValid, time.isHostTimeValid else {
                    available.signal()
                    return
                }
                let samples = Array(UnsafeBufferPointer(start: channel, count: Int(buffer.frameLength)))
                let sampleTime = time.sampleTime
                let hostSeconds = AVAudioTime.seconds(forHostTime: time.hostTime)
                let epochMs = Date().timeIntervalSince1970 * 1000
                    - (AVAudioTime.seconds(forHostTime: mach_absolute_time()) - hostSeconds) * 1000
                self.queue.async {
                    defer { available.signal() }
                    let output = stream.append(samples, at: sampleTime)
                    DispatchQueue.main.async {
                        guard self.sessionID == id else { return }
                        if output.started {
                            self.receivedAudio = true
                            self.status(id, "listening", "Listening to your ukulele", startedAtMs: epochMs)
                        }
                        if var frame = output.frame {
                            frame["sessionId"] = id
                            LaravelBridge.shared.send?("UkeOrPuke\\Audio\\Events\\AudioFrame", frame)
                        }
                    }
                }
            }
            tapInstalled = true
            let center = NotificationCenter.default
            observers = [
                center.addObserver(forName: UIApplication.didEnterBackgroundNotification, object: nil, queue: .main) { _ in
                    self.stop(id: id, reason: "interrupted", message: "Microphone paused while the app was away.")
                },
                center.addObserver(forName: AVAudioSession.interruptionNotification, object: session, queue: .main) { notification in
                    if (notification.userInfo?[AVAudioSessionInterruptionTypeKey] as? UInt) == AVAudioSession.InterruptionType.began.rawValue {
                        self.stop(id: id, reason: "interrupted", message: "Audio was interrupted. Resume when you are ready.")
                    }
                },
                center.addObserver(forName: AVAudioSession.routeChangeNotification, object: session, queue: .main) { notification in
                    let reason = notification.userInfo?[AVAudioSessionRouteChangeReasonKey] as? UInt
                    if reason == AVAudioSession.RouteChangeReason.oldDeviceUnavailable.rawValue || reason == AVAudioSession.RouteChangeReason.newDeviceAvailable.rawValue {
                        self.stop(id: id, reason: "interrupted", message: "Microphone changed. Start listening again.")
                    }
                },
                center.addObserver(forName: AVAudioSession.mediaServicesWereResetNotification, object: session, queue: .main) { _ in
                    self.stop(id: id, reason: "interrupted", message: "Audio restarted. Start listening again.")
                }
            ]
            engine.prepare()
            try engine.start()
            DispatchQueue.main.asyncAfter(deadline: .now() + 5) {
                if self.sessionID == id && !self.receivedAudio {
                    self.stop(id: id, reason: "error", message: "No microphone audio arrived. Check the input and try again.")
                }
            }
        } catch {
            stop(id: id, reason: "error", message: error.localizedDescription)
        }
    }

    func stop(id: String, reason: String = "stopped", message: String = "Microphone off") {
        guard sessionID == id else { return }
        sessionID = nil
        observers.forEach { NotificationCenter.default.removeObserver($0) }
        observers.removeAll()
        engine?.stop()
        if tapInstalled { engine?.inputNode.removeTap(onBus: 0) }
        tapInstalled = false
        engine = nil
        try? AVAudioSession.sharedInstance().setActive(false, options: .notifyOthersOnDeactivation)
        status(id, reason, message)
    }

    private func status(_ id: String, _ status: String, _ message: String, startedAtMs: Double = 0) {
        LaravelBridge.shared.send?("UkeOrPuke\\Audio\\Events\\AudioStatus", [
            "sessionId": id, "status": status, "message": message, "startedAtMs": startedAtMs
        ])
    }
}
