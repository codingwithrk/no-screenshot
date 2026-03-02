<?php

namespace Codingwithrk\NoScreenshot;

class NoScreenshot
{
    /**
     * Block screenshots and screen recording for the entire app.
     *
     * Android: sets FLAG_SECURE on the activity window.
     * iOS: registers a capture observer and overlays a black screen when recording is detected.
     */
    public function disableGlobally(): bool
    {
        if (! function_exists('nativephp_call')) {
            return false;
        }

        $result = nativephp_call('NoScreenshot.DisableGlobal', '{}');
        $decoded = json_decode($result, true);

        return $decoded['data']['success'] ?? false;
    }

    /**
     * Allow screenshots and screen recording app-wide.
     */
    public function enableGlobally(): bool
    {
        if (! function_exists('nativephp_call')) {
            return false;
        }

        $result = nativephp_call('NoScreenshot.EnableGlobal', '{}');
        $decoded = json_decode($result, true);

        return $decoded['data']['success'] ?? false;
    }

    /**
     * Toggle global screenshot and screen-recording protection on/off.
     * Returns the new isGloballyProtected state.
     */
    public function toggle(): bool
    {
        if (! function_exists('nativephp_call')) {
            return false;
        }

        $result = nativephp_call('NoScreenshot.Toggle', '{}');
        $decoded = json_decode($result, true);

        return $decoded['data']['isGloballyProtected'] ?? false;
    }

    /**
     * Return the current protection status.
     *
     * On iOS, isScreenBeingRecorded reflects UIScreen.main.isCaptured in real time.
     * On Android, FLAG_SECURE prevents capture rather than detecting it, so
     * isScreenBeingRecorded is always false.
     */
    public function getStatus(): ?ScreenProtectionStatus
    {
        if (! function_exists('nativephp_call')) {
            return null;
        }

        $result = nativephp_call('NoScreenshot.GetStatus', '{}');
        $decoded = json_decode($result, true);

        if (! isset($decoded['data'])) {
            return null;
        }

        $data = $decoded['data'];

        return new ScreenProtectionStatus(
            isGloballyProtected: $data['isGloballyProtected'] ?? false,
            isScreenBeingRecorded: $data['isScreenBeingRecorded'] ?? false,
            isScreenshotDetectionActive: $data['isScreenshotDetectionActive'] ?? false,
        );
    }

    // -------------------------------------------------------------------------
    // Screenshot Detection
    // -------------------------------------------------------------------------

    /**
     * Start listening for screenshot events.
     *
     * When a screenshot is taken, the ScreenshotAttempted event is fired automatically.
     *
     * Android: uses Activity.registerScreenCaptureCallback() (API 34+).
     *          On API < 34, this method succeeds but detection is not available
     *          (check the returned 'supported' value via getStatus()).
     * iOS: uses UIApplication.userDidTakeScreenshotNotification (all versions).
     *
     * @return bool  true when the call succeeded.
     */
    public function startScreenshotDetection(): bool
    {
        if (! function_exists('nativephp_call')) {
            return false;
        }

        $result = nativephp_call('NoScreenshot.StartScreenshotDetection', '{}');
        $decoded = json_decode($result, true);

        return $decoded['data']['success'] ?? false;
    }

    /**
     * Stop listening for screenshot events.
     *
     * @return bool  true when the call succeeded.
     */
    public function stopScreenshotDetection(): bool
    {
        if (! function_exists('nativephp_call')) {
            return false;
        }

        $result = nativephp_call('NoScreenshot.StopScreenshotDetection', '{}');
        $decoded = json_decode($result, true);

        return $decoded['data']['success'] ?? false;
    }

}
