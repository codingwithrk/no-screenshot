<?php

namespace Codingwithrk\NoScreenshot\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired when the user takes a screenshot while the app is running.
 *
 * iOS: dispatched via UIApplication.userDidTakeScreenshotNotification detection.
 * Android: dispatched via Activity.registerScreenCaptureCallback() (API 34+).
 *
 * Note: On iOS, screenshots cannot be prevented at the OS level — only detected.
 */
class ScreenshotAttempted
{
    use Dispatchable, SerializesModels;
}
