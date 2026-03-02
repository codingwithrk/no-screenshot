package com.codingwithrk.plugins.no_screenshot

import android.app.Activity
import android.os.Build
import android.os.Handler
import android.os.Looper
import android.view.WindowManager
import androidx.fragment.app.FragmentActivity
import com.nativephp.mobile.bridge.BridgeFunction
import com.nativephp.mobile.bridge.BridgeResponse
import com.nativephp.mobile.utils.NativeActionCoordinator
import org.json.JSONObject

// ---------------------------------------------------------------------------
// ScreenshotGuardState
//
// Singleton that owns all plugin state.
// ---------------------------------------------------------------------------

internal object ScreenshotGuardState {

    @Volatile var isGloballyProtected: Boolean = false
    @Volatile var isScreenshotDetectionActive: Boolean = false
    private var screenshotCallback: Any? = null   // Activity.ScreenCaptureCallback on API 34+

    // -----------------------------------------------------------------------
    // Window flag management
    // -----------------------------------------------------------------------

    /**
     * Apply or remove FLAG_SECURE based on isGloballyProtected state.
     */
    fun applyWindowFlag(activity: FragmentActivity) {
        activity.runOnUiThread {
            if (isGloballyProtected) {
                activity.window.addFlags(WindowManager.LayoutParams.FLAG_SECURE)
            } else {
                activity.window.clearFlags(WindowManager.LayoutParams.FLAG_SECURE)
            }
        }
    }

    // -----------------------------------------------------------------------
    // Screenshot detection (API 34+)
    // -----------------------------------------------------------------------

    /**
     * Register a ScreenCaptureCallback (API 34+) that dispatches a
     * ScreenshotAttempted PHP event whenever the user takes a screenshot.
     *
     * On API < 34 the flag is set to true but no callback is registered;
     * callers should check the 'supported' field in the bridge response.
     */
    fun startScreenshotDetection(activity: FragmentActivity) {
        if (isScreenshotDetectionActive) return
        isScreenshotDetectionActive = true

        if (Build.VERSION.SDK_INT >= 34) {
            val callback = Activity.ScreenCaptureCallback {
                val payload = JSONObject()
                Handler(Looper.getMainLooper()).post {
                    NativeActionCoordinator.dispatchEvent(
                        activity,
                        "Codingwithrk\\NoScreenshot\\Events\\ScreenshotAttempted",
                        payload.toString(),
                    )
                }
            }
            activity.registerScreenCaptureCallback(activity.mainExecutor, callback)
            screenshotCallback = callback
        }
    }

    /**
     * Unregister the screenshot callback and reset the detection flag.
     */
    fun stopScreenshotDetection(activity: FragmentActivity) {
        isScreenshotDetectionActive = false

        if (Build.VERSION.SDK_INT >= 34) {
            @Suppress("UNCHECKED_CAST")
            (screenshotCallback as? Activity.ScreenCaptureCallback)?.let {
                activity.unregisterScreenCaptureCallback(it)
            }
            screenshotCallback = null
        }
    }
}

// ---------------------------------------------------------------------------
// Bridge functions
// ---------------------------------------------------------------------------

object NoScreenshotFunctions {

    // -----------------------------------------------------------------------

    /**
     * Enable screenshot and screen-recording protection for the entire app.
     *
     * Sets FLAG_SECURE on the activity window so the OS blocks all capture
     * attempts (screenshot button, screen recorder, ADB, recents thumbnail).
     *
     * Response: { success: Boolean, isGloballyProtected: Boolean }
     */
    class DisableGlobal(private val activity: FragmentActivity) : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            ScreenshotGuardState.isGloballyProtected = true
            ScreenshotGuardState.applyWindowFlag(activity)

            return BridgeResponse.success(
                mapOf("success" to true, "isGloballyProtected" to true)
            )
        }
    }

    // -----------------------------------------------------------------------

    /**
     * Remove the global protection flag. FLAG_SECURE is cleared from the window.
     *
     * Response: { success: Boolean, isGloballyProtected: Boolean }
     */
    class EnableGlobal(private val activity: FragmentActivity) : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            ScreenshotGuardState.isGloballyProtected = false
            ScreenshotGuardState.applyWindowFlag(activity)

            return BridgeResponse.success(
                mapOf("success" to true, "isGloballyProtected" to false)
            )
        }
    }

    // -----------------------------------------------------------------------

    /**
     * Toggle global protection on/off.
     *
     * Response: { success: Boolean, isGloballyProtected: Boolean }
     */
    class Toggle(private val activity: FragmentActivity) : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            val newState = !ScreenshotGuardState.isGloballyProtected
            ScreenshotGuardState.isGloballyProtected = newState
            ScreenshotGuardState.applyWindowFlag(activity)

            return BridgeResponse.success(
                mapOf("success" to true, "isGloballyProtected" to newState)
            )
        }
    }

    // -----------------------------------------------------------------------

    /**
     * Return the current protection status.
     *
     * isScreenBeingRecorded is always false on Android — FLAG_SECURE prevents
     * capture rather than detecting it.
     *
     * Response: {
     *   isGloballyProtected:         Boolean,
     *   isScreenBeingRecorded:       Boolean,   // always false on Android
     *   isScreenshotDetectionActive: Boolean
     * }
     */
    class GetStatus(private val activity: FragmentActivity) : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            return BridgeResponse.success(
                mapOf(
                    "isGloballyProtected" to ScreenshotGuardState.isGloballyProtected,
                    "isScreenBeingRecorded" to false,
                    "isScreenshotDetectionActive" to ScreenshotGuardState.isScreenshotDetectionActive,
                )
            )
        }
    }

    // -----------------------------------------------------------------------

    /**
     * Start detecting screenshot events and fire ScreenshotAttempted when one occurs.
     *
     * Uses Activity.registerScreenCaptureCallback() introduced in API 34 (Android 14).
     * On API < 34, the call succeeds but 'supported' will be false and no events fire.
     *
     * Response: { success: Boolean, supported: Boolean, isScreenshotDetectionActive: Boolean }
     */
    class StartScreenshotDetection(private val activity: FragmentActivity) : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            ScreenshotGuardState.startScreenshotDetection(activity)
            val supported = Build.VERSION.SDK_INT >= 34

            return BridgeResponse.success(
                mapOf(
                    "success" to true,
                    "supported" to supported,
                    "isScreenshotDetectionActive" to ScreenshotGuardState.isScreenshotDetectionActive,
                )
            )
        }
    }

    // -----------------------------------------------------------------------

    /**
     * Stop detecting screenshot events.
     *
     * Response: { success: Boolean, isScreenshotDetectionActive: Boolean }
     */
    class StopScreenshotDetection(private val activity: FragmentActivity) : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            ScreenshotGuardState.stopScreenshotDetection(activity)

            return BridgeResponse.success(
                mapOf(
                    "success" to true,
                    "isScreenshotDetectionActive" to false,
                )
            )
        }
    }
}
