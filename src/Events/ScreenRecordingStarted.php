<?php

namespace Codingwithrk\NoScreenshot\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired when the device begins recording the screen.
 *
 * iOS: detected via UIScreen.capturedDidChangeNotification (UIScreen.main.isCaptured → true).
 * Android: FLAG_SECURE prevents capture rather than detecting it; this event is
 *          not automatically dispatched on Android.
 *
 * Dispatch this event from your PHP code after polling getStatus():
 *
 *   $status = NoScreenshot::getStatus();
 *   if ($status->isScreenBeingRecorded) {
 *       ScreenRecordingStarted::dispatch();
 *   }
 */
class ScreenRecordingStarted
{
    use Dispatchable, SerializesModels;
}
