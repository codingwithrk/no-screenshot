package com.codingwithrk.plugins.no_screenshot

import android.app.Activity
import android.app.Application
import android.content.ContentProvider
import android.content.ContentValues
import android.database.Cursor
import android.net.Uri
import android.os.Bundle

/**
 * Startup ContentProvider that applies FLAG_SECURE before any Activity is
 * created, fixing the App Switcher thumbnail leak (GitHub issue #1).
 *
 * Android initialises ContentProviders before the first Activity, so by the
 * time MainActivity.onCreate() fires the lifecycle callback is already
 * registered and FLAG_SECURE is set immediately — before the WebView or any
 * PHP controller has a chance to run.
 *
 * Registered in nativephp.json under android.providers.
 */
class NoScreenshotInitProvider : ContentProvider() {

    override fun onCreate(): Boolean {
        val app = context?.applicationContext as? Application ?: return true

        // Restore protection preference persisted by a previous session.
        ScreenshotGuardState.restoreProtectionState(app)

        // Apply FLAG_SECURE on every Activity the OS creates.
        app.registerActivityLifecycleCallbacks(object : Application.ActivityLifecycleCallbacks {
            override fun onActivityCreated(activity: Activity, savedInstanceState: Bundle?) {
                // Fires before onStart/onResume — window exists but is not yet visible.
                // Setting FLAG_SECURE here guarantees the first recents thumbnail is black.
                ScreenshotGuardState.applyWindowFlag(activity)
            }

            override fun onActivityResumed(activity: Activity) {
                // Re-apply after the activity returns to foreground in case the flag
                // was temporarily cleared by another component.
                ScreenshotGuardState.applyWindowFlag(activity)
            }

            override fun onActivityStarted(activity: Activity) {}
            override fun onActivityPaused(activity: Activity) {}
            override fun onActivityStopped(activity: Activity) {}
            override fun onActivitySaveInstanceState(activity: Activity, outState: Bundle) {}
            override fun onActivityDestroyed(activity: Activity) {}
        })

        return true
    }

    // Required stub overrides — this provider exists only for its onCreate().
    override fun query(uri: Uri, projection: Array<String>?, selection: String?, selectionArgs: Array<String>?, sortOrder: String?): Cursor? = null
    override fun getType(uri: Uri): String? = null
    override fun insert(uri: Uri, values: ContentValues?): Uri? = null
    override fun delete(uri: Uri, selection: String?, selectionArgs: Array<String>?): Int = 0
    override fun update(uri: Uri, values: ContentValues?, selection: String?, selectionArgs: Array<String>?): Int = 0
}
