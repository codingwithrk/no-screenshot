<?php

namespace Codingwithrk\NoScreenshot\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired when screen recording stops.
 *
 * iOS: detected via UIScreen.capturedDidChangeNotification (UIScreen.main.isCaptured → false).
 * Android: not automatically dispatched (FLAG_SECURE prevents rather than detects recording).
 */
class ScreenRecordingStopped
{
    use Dispatchable, SerializesModels;
}
