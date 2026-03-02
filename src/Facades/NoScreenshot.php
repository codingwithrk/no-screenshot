<?php

namespace Codingwithrk\NoScreenshot\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static bool disableGlobally()
 * @method static bool enableGlobally()
 * @method static bool toggle()
 * @method static \Codingwithrk\NoScreenshot\ScreenProtectionStatus|null getStatus()
 * @method static bool startScreenshotDetection()
 * @method static bool stopScreenshotDetection()
 *
 * @see \Codingwithrk\NoScreenshot\NoScreenshot
 */
class NoScreenshot extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Codingwithrk\NoScreenshot\NoScreenshot::class;
    }
}
