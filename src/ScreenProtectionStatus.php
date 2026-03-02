<?php

namespace Codingwithrk\NoScreenshot;

readonly class ScreenProtectionStatus
{
    public function __construct(
        /** True when disableGlobally() is active. */
        public bool $isGloballyProtected,

        /**
         * True when the screen is actively being recorded or mirrored.
         *
         * Populated in real time on iOS (UIScreen.main.isCaptured).
         * Always false on Android (FLAG_SECURE prevents capture instead of detecting it).
         */
        public bool $isScreenBeingRecorded,

        /**
         * True when screenshot detection is running.
         *
         * When active, taking a screenshot fires the ScreenshotAttempted event.
         * Android requires API 34+; on older devices this is always false even
         * when startScreenshotDetection() has been called.
         */
        public bool $isScreenshotDetectionActive = false,
    ) {}
}
