<?php

namespace Codingwithrk\NoScreenshot\Commands;

use Native\Mobile\Plugins\Commands\NativePluginHookCommand;

class CopyAssetsCommand extends NativePluginHookCommand
{
    protected $signature = 'nativephp:no-screenshot:copy-assets';

    protected $description = 'Copy assets for NoScreenshot plugin';

    public function handle(): int
    {
        // This plugin ships no binary assets — native code is injected directly
        // by NativePHP at compile time from resources/android/ and resources/ios/.
        return self::SUCCESS;
    }
}