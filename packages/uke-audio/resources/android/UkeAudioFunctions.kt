package com.ukeorpuke.audio

import android.Manifest
import android.content.pm.PackageManager
import android.media.AudioAttributes
import android.media.AudioDeviceCallback
import android.media.AudioDeviceInfo
import android.media.AudioFocusRequest
import android.media.AudioFormat
import android.media.AudioManager
import android.media.AudioRecord
import android.media.MediaRecorder
import android.os.Handler
import android.os.Looper
import android.util.Log
import androidx.activity.result.ActivityResultLauncher
import androidx.activity.result.contract.ActivityResultContracts
import androidx.core.content.ContextCompat
import androidx.fragment.app.FragmentActivity
import androidx.lifecycle.Lifecycle
import androidx.lifecycle.LifecycleEventObserver
import com.nativephp.mobile.bridge.BridgeError
import com.nativephp.mobile.bridge.BridgeFunction
import com.nativephp.mobile.utils.NativeActionCoordinator
import org.json.JSONObject
import java.util.concurrent.Executors
import java.util.concurrent.atomic.AtomicBoolean
import kotlin.math.max

/**
 * Functions for listening to the ukulele.
 * Namespace: "UkeAudio.*"
 *
 * iOS twin: resources/ios/UkeAudioFunctions.swift.
 */
object UkeAudioFunctions {

    /**
     * Start listening and streaming analysis frames to PHP.
     * Parameters:
     *   - sessionId: string - Identifies the round; every event carries it back
     * Returns:
     *   - success: boolean
     */
    class Start(private val activity: FragmentActivity) : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            val id = parameters["sessionId"] as? String
            if (id.isNullOrEmpty()) {
                throw BridgeError.InvalidParameters("A session ID is required.")
            }

            activity.runOnUiThread { UkeMicrophone.start(activity, id) }

            return mapOf("success" to true)
        }
    }

    /**
     * Stop listening and release the microphone.
     * Parameters:
     *   - sessionId: string - The session to stop; a stale ID is ignored
     * Returns:
     *   - success: boolean
     */
    class Stop(private val activity: FragmentActivity) : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            val id = parameters["sessionId"] as? String
                ?: throw BridgeError.InvalidParameters("A session ID is required.")

            activity.runOnUiThread { UkeMicrophone.stop(id) }

            return mapOf("success" to true)
        }
    }
}

/**
 * Owns the recorder on the main thread. Raw audio stays in memory on device: buffers go straight to
 * the analyzer and are never written to disk or sent anywhere.
 */
private object UkeMicrophone {
    private const val TAG = "UkeAudio"
    private const val STATUS_EVENT = "UkeOrPuke\\Audio\\Events\\AudioStatus"
    private const val FRAME_EVENT = "UkeOrPuke\\Audio\\Events\\AudioFrame"
    private const val BUFFER_FRAMES = 1_024
    private const val PREFERRED_SAMPLE_RATE = 48_000

    private val main = Handler(Looper.getMainLooper())
    private val analysis = Executors.newSingleThreadExecutor { runnable ->
        Thread(runnable, "uke.audio.analysis")
    }
    private val analysisBusy = AtomicBoolean(false)

    private var activity: FragmentActivity? = null
    private var recorder: AudioRecord? = null
    private var captureThread: Thread? = null
    private var permissionLauncher: ActivityResultLauncher<String>? = null
    private var lifecycleObserver: LifecycleEventObserver? = null
    private var deviceCallback: AudioDeviceCallback? = null
    private var focusRequest: AudioFocusRequest? = null

    @Volatile
    private var sessionId: String? = null

    @Volatile
    private var receivedAudio = false

    fun start(activity: FragmentActivity, id: String) {
        sessionId?.let { stop(it) }
        this.activity = activity
        sessionId = id
        status(id, "requesting", "Allow microphone access to hear your ukulele.")

        if (ContextCompat.checkSelfPermission(activity, Manifest.permission.RECORD_AUDIO) == PackageManager.PERMISSION_GRANTED) {
            capture(id)

            return
        }

        // Registering against the activity's result registry keeps the request self-contained — the
        // app's own Activity never has to route onRequestPermissionsResult back to the plugin.
        val launcher = activity.activityResultRegistry.register(
            "uke-audio-permission-$id",
            ActivityResultContracts.RequestPermission(),
        ) { granted ->
            permissionLauncher?.unregister()
            permissionLauncher = null

            if (sessionId != id) {
                return@register
            }

            if (granted) {
                capture(id)
            } else {
                stop(id, "denied", "Microphone access is off. Enable it in Settings, then try again.")
            }
        }
        permissionLauncher = launcher
        launcher.launch(Manifest.permission.RECORD_AUDIO)
    }

    private fun capture(id: String) {
        val activity = this.activity ?: return

        try {
            receivedAudio = false
            val audioManager = activity.getSystemService(AudioManager::class.java)
                ?: throw IllegalStateException("No audio service is available.")
            val record = openRecorder(audioManager)
                ?: throw IllegalStateException("No microphone input is available.")
            recorder = record

            val sampleRate = record.sampleRate.toDouble()
            val stream = UkeAudioStream(sampleRate)

            requestAudioFocus(audioManager, id)
            observeLifecycle(activity, id)
            observeDevices(audioManager, id)

            record.startRecording()
            if (record.recordingState != AudioRecord.RECORDSTATE_RECORDING) {
                throw IllegalStateException("The microphone did not start recording.")
            }

            captureThread = Thread({ readLoop(id, record, stream, sampleRate) }, "uke.audio.capture").apply {
                priority = Thread.MAX_PRIORITY
                start()
            }

            main.postDelayed({
                if (sessionId == id && !receivedAudio) {
                    stop(id, "error", "No microphone audio arrived. Check the input and try again.")
                }
            }, 5_000)
        } catch (error: Exception) {
            Log.e(TAG, "Could not start listening: ${error.message}", error)
            stop(id, "error", error.message ?: "The microphone could not be started.")
        }
    }

    /**
     * Prefers the least-processed input the device offers: automatic gain control and noise
     * suppression rewrite levels and partials, which is exactly what the analysis reads.
     */
    private fun openRecorder(audioManager: AudioManager): AudioRecord? {
        val unprocessedSupported =
            audioManager.getProperty(AudioManager.PROPERTY_SUPPORT_AUDIO_SOURCE_UNPROCESSED) == "true"
        val sources = buildList {
            if (unprocessedSupported) {
                add(MediaRecorder.AudioSource.UNPROCESSED)
            }
            add(MediaRecorder.AudioSource.VOICE_RECOGNITION)
            add(MediaRecorder.AudioSource.MIC)
        }

        for (sampleRate in intArrayOf(PREFERRED_SAMPLE_RATE, 44_100)) {
            val minimumBytes = AudioRecord.getMinBufferSize(
                sampleRate,
                AudioFormat.CHANNEL_IN_MONO,
                AudioFormat.ENCODING_PCM_FLOAT,
            )
            if (minimumBytes <= 0) {
                continue
            }

            val format = AudioFormat.Builder()
                .setEncoding(AudioFormat.ENCODING_PCM_FLOAT)
                .setSampleRate(sampleRate)
                .setChannelMask(AudioFormat.CHANNEL_IN_MONO)
                .build()

            for (source in sources) {
                val record = try {
                    AudioRecord.Builder()
                        .setAudioSource(source)
                        .setAudioFormat(format)
                        .setBufferSizeInBytes(max(minimumBytes, BUFFER_FRAMES * Float.SIZE_BYTES * 4))
                        .build()
                } catch (error: Exception) {
                    Log.w(TAG, "Audio source $source at $sampleRate Hz unavailable: ${error.message}")
                    continue
                }

                if (record.state == AudioRecord.STATE_INITIALIZED) {
                    return record
                }

                record.release()
            }
        }

        return null
    }

    private fun readLoop(id: String, record: AudioRecord, stream: UkeAudioStream, sampleRate: Double) {
        val buffer = FloatArray(BUFFER_FRAMES)

        try {
            readBuffers(id, record, stream, sampleRate, buffer)
        } finally {
            // Only this thread may release the recorder — freeing it from the main thread while a
            // read is still blocked is a native crash.
            record.release()
        }
    }

    private fun readBuffers(
        id: String,
        record: AudioRecord,
        stream: UkeAudioStream,
        sampleRate: Double,
        buffer: FloatArray,
    ) {
        var samplePosition = 0L

        while (sessionId == id && !Thread.currentThread().isInterrupted) {
            val read = record.read(buffer, 0, buffer.size, AudioRecord.READ_BLOCKING)
            if (read <= 0) {
                if (sessionId == id) {
                    main.post { stop(id, "error", "The microphone stopped delivering audio.") }
                }

                break
            }

            val capturedAtMs = System.currentTimeMillis() - read / sampleRate * 1_000
            val samples = buffer.copyOf(read)
            val sampleTime = samplePosition
            samplePosition += read

            // Never make the recorder wait on analysis. A skipped buffer leaves a gap in the sample
            // positions, which the stream treats as a discontinuity and recovers from.
            if (!analysisBusy.compareAndSet(false, true)) {
                continue
            }

            analysis.execute {
                try {
                    val output = stream.append(samples, sampleTime)
                    main.post { deliver(id, output, capturedAtMs) }
                } catch (error: Exception) {
                    Log.e(TAG, "Analysis failed: ${error.message}", error)
                } finally {
                    analysisBusy.set(false)
                }
            }
        }
    }

    private fun deliver(id: String, output: UkeStreamOutput, capturedAtMs: Double) {
        if (sessionId != id) {
            return
        }

        if (output.started) {
            receivedAudio = true
            status(id, "listening", "Listening to your ukulele", capturedAtMs)
        }

        val frame = output.frame ?: return
        val payload = JSONObject().apply {
            put("sessionId", id)
            put("elapsedMs", frame.elapsedMs)
            put("level", frame.level)
            frame.midi?.let { put("midi", it) }
            frame.cents?.let { put("cents", it) }
            put("noteConfidence", frame.noteConfidence)
            frame.chord?.let { put("chord", it) }
            put("chordConfidence", frame.chordConfidence)
            frame.onsetMs?.let { put("onsetMs", it) }
            put("strumId", frame.strumId)
            put("clipped", frame.clipped)
        }

        dispatch(FRAME_EVENT, payload)
    }

    fun stop(id: String, reason: String = "stopped", message: String = "Microphone off") {
        if (sessionId != id) {
            return
        }
        sessionId = null

        permissionLauncher?.unregister()
        permissionLauncher = null

        val thread = captureThread
        thread?.interrupt()
        captureThread = null

        recorder?.let { record ->
            try {
                if (record.recordingState == AudioRecord.RECORDSTATE_RECORDING) {
                    record.stop()
                }
            } catch (error: IllegalStateException) {
                Log.w(TAG, "Recorder was already stopped: ${error.message}")
            }

            // Without a reading thread to hand it to, release it here.
            if (thread == null) {
                record.release()
            }
        }
        recorder = null

        val activity = this.activity
        if (activity != null) {
            lifecycleObserver?.let { activity.lifecycle.removeObserver(it) }

            val audioManager = activity.getSystemService(AudioManager::class.java)
            deviceCallback?.let { audioManager?.unregisterAudioDeviceCallback(it) }
            focusRequest?.let { audioManager?.abandonAudioFocusRequest(it) }
        }
        lifecycleObserver = null
        deviceCallback = null
        focusRequest = null

        status(id, reason, message)
        this.activity = null
    }

    private fun requestAudioFocus(audioManager: AudioManager, id: String) {
        val request = AudioFocusRequest.Builder(AudioManager.AUDIOFOCUS_GAIN_TRANSIENT_EXCLUSIVE)
            .setAudioAttributes(
                AudioAttributes.Builder()
                    .setUsage(AudioAttributes.USAGE_MEDIA)
                    .setContentType(AudioAttributes.CONTENT_TYPE_MUSIC)
                    .build(),
            )
            .setOnAudioFocusChangeListener { change ->
                if (change == AudioManager.AUDIOFOCUS_LOSS || change == AudioManager.AUDIOFOCUS_LOSS_TRANSIENT) {
                    stop(id, "interrupted", "Audio was interrupted. Resume when you are ready.")
                }
            }
            .build()
        focusRequest = request
        audioManager.requestAudioFocus(request)
    }

    private fun observeLifecycle(activity: FragmentActivity, id: String) {
        val observer = LifecycleEventObserver { _, event ->
            if (event == Lifecycle.Event.ON_STOP) {
                stop(id, "interrupted", "Microphone paused while the app was away.")
            }
        }
        lifecycleObserver = observer
        activity.lifecycle.addObserver(observer)
    }

    private fun observeDevices(audioManager: AudioManager, id: String) {
        val callback = object : AudioDeviceCallback() {
            override fun onAudioDevicesAdded(addedDevices: Array<out AudioDeviceInfo>?) {
                if (addedDevices?.any { it.isSource } == true) {
                    stop(id, "interrupted", "Microphone changed. Start listening again.")
                }
            }

            override fun onAudioDevicesRemoved(removedDevices: Array<out AudioDeviceInfo>?) {
                if (removedDevices?.any { it.isSource } == true) {
                    stop(id, "interrupted", "Microphone changed. Start listening again.")
                }
            }
        }
        deviceCallback = callback
        audioManager.registerAudioDeviceCallback(callback, main)
    }

    private fun status(id: String, status: String, message: String, startedAtMs: Double = 0.0) {
        dispatch(
            STATUS_EVENT,
            JSONObject().apply {
                put("sessionId", id)
                put("status", status)
                put("message", message)
                put("startedAtMs", startedAtMs)
            },
        )
    }

    private fun dispatch(event: String, payload: JSONObject) {
        val activity = this.activity ?: return

        try {
            NativeActionCoordinator.dispatchEvent(activity, event, payload.toString())
        } catch (error: Exception) {
            Log.e(TAG, "Could not dispatch $event: ${error.message}", error)
        }
    }
}
