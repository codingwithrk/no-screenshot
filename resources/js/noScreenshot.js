/**
 * NoScreenshot Plugin for NativePHP Mobile
 *
 * Prevent screenshots, block screen recording, detect screenshot events,
 * and show a custom overlay in the app switcher.
 *
 * @example Global protection
 * import { noScreenshot } from '@codingwithrk/no-screenshot';
 *
 * await noScreenshot.disableGlobally();   // lock the whole app
 * await noScreenshot.enableGlobally();    // unlock the whole app
 * await noScreenshot.toggle();           // flip current state
 *
 * @example Per-screen protection (Livewire / Alpine lifecycle)
 * await noScreenshot.protectScreen('checkout');
 * await noScreenshot.unprotectScreen('checkout');
 *
 * @example App switcher overlay
 * await noScreenshot.setAppSwitcherOverlayColor('#1a1a2e');
 * await noScreenshot.setAppSwitcherOverlayBlur(25);
 * await noScreenshot.setAppSwitcherOverlayImage(base64String);
 * await noScreenshot.clearAppSwitcherOverlay();
 *
 * @example Screenshot detection
 * await noScreenshot.startScreenshotDetection();
 * // ScreenshotAttempted event fires automatically when user takes a screenshot
 * await noScreenshot.stopScreenshotDetection();
 *
 * @example Check live status
 * const status = await noScreenshot.getStatus();
 * if (status.isScreenBeingRecorded) console.warn('Recording detected!');
 */

const BASE_URL = '/_native/api/call';

/**
 * Internal bridge call helper.
 * @private
 */
async function bridgeCall(method, params = {}) {
    const response = await fetch(BASE_URL, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
        },
        body: JSON.stringify({ method, params }),
    });

    const json = await response.json();

    if (json.status === 'error') {
        throw new Error(json.message ?? 'Native call failed');
    }

    const native = json.data;
    return native?.data !== undefined ? native.data : native;
}

// ---------------------------------------------------------------------------
// Global protection
// ---------------------------------------------------------------------------

/**
 * Block screenshots and screen recording for the entire app.
 *
 * Android: sets FLAG_SECURE on the activity window.
 * iOS: activates UIScreen.capturedDidChangeNotification observer and overlays
 *      a black screen when recording is detected.
 *
 * @returns {Promise<boolean>} true on success
 */
export async function disableGlobally() {
    const result = await bridgeCall('NoScreenshot.DisableGlobal');
    return result?.success ?? false;
}

/**
 * Allow screenshots and screen recording app-wide.
 * Per-screen guards added via protectScreen() remain active.
 *
 * @returns {Promise<boolean>} true on success
 */
export async function enableGlobally() {
    const result = await bridgeCall('NoScreenshot.EnableGlobal');
    return result?.success ?? false;
}

/**
 * Toggle global screenshot and screen-recording protection.
 *
 * @returns {Promise<boolean>} the new isGloballyProtected state
 */
export async function toggle() {
    const result = await bridgeCall('NoScreenshot.Toggle');
    return result?.isGloballyProtected ?? false;
}

// ---------------------------------------------------------------------------
// Per-screen protection
// ---------------------------------------------------------------------------

/**
 * Block screenshots for a specific logical screen.
 *
 * Call from your component's mount/connected lifecycle hook. The native layer
 * activates protection immediately and keeps it active until you call
 * unprotectScreen() with the same ID.
 *
 * @param {string} screenId - Developer-defined screen identifier (e.g. 'checkout', 'invoice-detail')
 * @returns {Promise<boolean>} true on success
 */
export async function protectScreen(screenId) {
    const result = await bridgeCall('NoScreenshot.ProtectScreen', { screenId });
    return result?.success ?? false;
}

/**
 * Remove screenshot protection for a specific screen.
 *
 * Call from your component's unmount/disconnected lifecycle hook.
 * If no other screens are protected and global protection is off, the guard
 * is fully lifted.
 *
 * @param {string} screenId - The same identifier passed to protectScreen()
 * @returns {Promise<boolean>} true on success
 */
export async function unprotectScreen(screenId) {
    const result = await bridgeCall('NoScreenshot.UnprotectScreen', { screenId });
    return result?.success ?? false;
}

// ---------------------------------------------------------------------------
// Status
// ---------------------------------------------------------------------------

/**
 * Return the current protection status.
 *
 * @returns {Promise<{
 *   isGloballyProtected: boolean,
 *   protectedScreens: string[],
 *   isProtectionActive: boolean,
 *   isScreenBeingRecorded: boolean,
 *   appSwitcherOverlayType: string,
 *   isScreenshotDetectionActive: boolean
 * }>}
 */
export async function getStatus() {
    return bridgeCall('NoScreenshot.GetStatus');
}

// ---------------------------------------------------------------------------
// App switcher overlay
// ---------------------------------------------------------------------------

/**
 * Show a solid colour overlay when the app appears in the system app switcher.
 *
 * Android: overlays a coloured View over the window just before the OS captures
 *          the recents thumbnail.
 * iOS: shows a UIWindow overlay on willResignActive, hides it on didBecomeActive.
 *
 * @param {string} [color='#000000'] CSS hex colour, e.g. '#1a1a2e'
 * @returns {Promise<boolean>} true on success
 */
export async function setAppSwitcherOverlayColor(color = '#000000') {
    const result = await bridgeCall('NoScreenshot.SetAppSwitcherOverlay', { type: 'color', color });
    return result?.success ?? false;
}

/**
 * Show a blurred snapshot of the app in the system app switcher.
 *
 * Android: captures the window, applies RenderEffect blur (API 31+) or falls back
 *          to a solid overlay on older devices.
 * iOS: uses UIVisualEffectView with UIBlurEffect.
 *
 * @param {number} [blurRadius=20] Blur intensity (higher = more blur)
 * @returns {Promise<boolean>} true on success
 */
export async function setAppSwitcherOverlayBlur(blurRadius = 20) {
    const result = await bridgeCall('NoScreenshot.SetAppSwitcherOverlay', { type: 'blur', blurRadius });
    return result?.success ?? false;
}

/**
 * Show a custom image in the system app switcher.
 *
 * Pass the image as a Base64-encoded string (no data-URI prefix needed).
 *
 * @param {string} imageBase64 - Base64-encoded PNG or JPEG bytes
 * @returns {Promise<boolean>} true on success
 */
export async function setAppSwitcherOverlayImage(imageBase64) {
    const result = await bridgeCall('NoScreenshot.SetAppSwitcherOverlay', { type: 'image', imageBase64 });
    return result?.success ?? false;
}

/**
 * Remove the app switcher overlay and let the OS capture the real screen content.
 *
 * @returns {Promise<boolean>} true on success
 */
export async function clearAppSwitcherOverlay() {
    const result = await bridgeCall('NoScreenshot.SetAppSwitcherOverlay', { type: 'none' });
    return result?.success ?? false;
}

// ---------------------------------------------------------------------------
// Screenshot detection
// ---------------------------------------------------------------------------

/**
 * Start listening for screenshot events.
 *
 * When the user takes a screenshot, the ScreenshotAttempted PHP event is fired
 * and can be caught with #[OnNative(ScreenshotAttempted::class)].
 *
 * Android: requires API 34+. On older devices the call succeeds but no events
 *          are fired (check status.isScreenshotDetectionActive).
 * iOS: supported on all versions via UIApplication.userDidTakeScreenshotNotification.
 *
 * @returns {Promise<boolean>} true on success
 */
export async function startScreenshotDetection() {
    const result = await bridgeCall('NoScreenshot.StartScreenshotDetection');
    return result?.success ?? false;
}

/**
 * Stop listening for screenshot events.
 *
 * @returns {Promise<boolean>} true on success
 */
export async function stopScreenshotDetection() {
    const result = await bridgeCall('NoScreenshot.StopScreenshotDetection');
    return result?.success ?? false;
}

// ---------------------------------------------------------------------------
// Namespace export
// ---------------------------------------------------------------------------

/**
 * NoScreenshot namespace object — use this for a single named import.
 */
export const noScreenshot = {
    disableGlobally,
    enableGlobally,
    toggle,
    protectScreen,
    unprotectScreen,
    getStatus,
    setAppSwitcherOverlayColor,
    setAppSwitcherOverlayBlur,
    setAppSwitcherOverlayImage,
    clearAppSwitcherOverlay,
    startScreenshotDetection,
    stopScreenshotDetection,
};

export default noScreenshot;
