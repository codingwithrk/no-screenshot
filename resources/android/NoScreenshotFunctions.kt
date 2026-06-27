package com.codingwithrk.plugins.no_screenshot

import android.app.Activity
import android.content.Context
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
// Singleton that owns all plugin state and persists it across app launches
// via SharedPreferences so FLAG_SECURE can be applied at Activity.onCreate()
// before the WebView loads (see NoScreenshotInitProvider).
// ---------------------------------------------------------------------------

internal object ScreenshotGuardState {

    private const val PREFS_NAME = "no_screenshot_prefs"
    private const val KEY_IS_PROTECTED = "is_globally_protected"

    @Volatile var isGloballyProtected: Boolean = false
    @Volatile var isScreenshotDetectionActive: Boolean = false
    private var screenshotCallback: Any? = null

    // -----------------------------------------------------------------------
    // Persistence
    // -----------------------------------------------------------------------

    fun saveProtectionState(context: Context, protected: Boolean) {
        isGloballyProtected = protected
        context.getSharedPreferences(PREFS_NAME, Context.MODE_PRIVATE)
            .edit()
            .putBoolean(KEY_IS_PROTECTED, protected)
            .apply()
    }

    fun restoreProtectionState(context: Context): Boolean {
        val stored = context.getSharedPreferences(PREFS_NAME, Context.MODE_PRIVATE)
            .getBoolean(KEY_IS_PROTECTED, false)
        isGloballyProtected = stored
        return stored
    }

    // -----------------------------------------------------------------------
    // Window flag management
    // -----------------------------------------------------------------------

    /**
     * Apply or remove FLAG_SECURE based on isGloballyProtected.
     * Accepts the base Activity type so it can be called from both bridge
     * functions (FragmentActivity) and lifecycle callbacks (Activity).
     */
    fun applyWindowFlag(activity: Activity) {
        activity.runOnUiThread {
            if (isGloballyProtected) {
                activity.window?.addFlags(WindowManager.LayoutParams.FLAG_SECURE)
            } else {
                activity.window?.clearFlags(WindowManager.LayoutParams.FLAG_SECURE)
            }
        }
    }

    // -----------------------------------------------------------------------
    // Screenshot detection (API 34+)
    // -----------------------------------------------------------------------

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
     * Sets FLAG_SECURE on the activity window and persists the choice so the
     * flag is re-applied by NoScreenshotInitProvider on the next launch before
     * the WebView loads (fixing the App Switcher thumbnail leak).
     *
     * Response: { success: Boolean, isGloballyProtected: Boolean }
     */
    class DisableGlobal(private val activity: FragmentActivity) : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            ScreenshotGuardState.saveProtectionState(activity, true)
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
            ScreenshotGuardState.saveProtectionState(activity, false)
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
            ScreenshotGuardState.saveProtectionState(activity, newState)
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
