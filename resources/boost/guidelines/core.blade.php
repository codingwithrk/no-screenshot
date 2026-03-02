## codingwithrk/no-screenshot

Prevent screenshots, block screen recording, detect screenshot events, and show a custom overlay in the app switcher — for the **entire app** or only for **specific screens**.

Inspired by Flutter's [no_screenshot](https://pub.dev/packages/no_screenshot) package.

---

### Installation

```bash
composer require codingwithrk/no-screenshot
```

---

### How It Works

| Feature | Android | iOS |
|---------|:---:|:---:|
| **Block screenshots** | ✅ `FLAG_SECURE` | — (detected only) |
| **Block screen recording** | ✅ `FLAG_SECURE` | ✅ Black overlay |
| **Detect screen recording** | — (prevented, not detected) | ✅ `UIScreen.isCaptured` |
| **Detect screenshot events** | ✅ API 34+ | ✅ All versions |
| **App switcher — color overlay** | ✅ | ✅ |
| **App switcher — blur overlay** | ✅ API 31+ / fallback | ✅ |
| **App switcher — image overlay** | ✅ | ✅ |

**Android** uses `WindowManager.LayoutParams.FLAG_SECURE` — a single OS-level flag that prevents the window from being captured by any means (screenshot button, screen recorder, ADB shell, etc.).

**iOS** cannot block the system screenshot gesture at the application level. Instead, the plugin observes `UIScreen.capturedDidChangeNotification` and overlays a black screen the moment recording starts, effectively hiding your content. Screenshot events are detected via `UIApplication.userDidTakeScreenshotNotification`.

---

### PHP Usage

#### Global protection — lock the entire app

@verbatim
<code-snippet name="Global protection" lang="php">
use Codingwithrk\NoScreenshot\Facades\NoScreenshot;

// Block screenshots and recording for the whole app
NoScreenshot::disableGlobally();

// Lift global protection (per-screen guards remain active)
NoScreenshot::enableGlobally();

// Toggle the current state
$isNowProtected = NoScreenshot::toggle();
</code-snippet>
@endverbatim

#### Per-screen protection — Livewire component lifecycle

@verbatim
<code-snippet name="Per-screen protection in Livewire" lang="php">
use Codingwithrk\NoScreenshot\Facades\NoScreenshot;

class CheckoutPage extends Component
{
    public function mount(): void
    {
        NoScreenshot::protectScreen('checkout');
    }

    public function dehydrate(): void
    {
        NoScreenshot::unprotectScreen('checkout');
    }

    public function render()
    {
        return view('livewire.checkout-page');
    }
}
</code-snippet>
@endverbatim

#### Check live status

@verbatim
<code-snippet name="Check protection status" lang="php">
use Codingwithrk\NoScreenshot\Facades\NoScreenshot;

$status = NoScreenshot::getStatus();

// $status->isGloballyProtected          — bool
// $status->protectedScreens             — string[]
// $status->isProtectionActive           — bool (global OR any screen)
// $status->isScreenBeingRecorded        — bool (real-time on iOS)
// $status->appSwitcherOverlayType       — string ('none'|'color'|'blur'|'image')
// $status->isScreenshotDetectionActive  — bool

if ($status->isScreenBeingRecorded) {
    ScreenRecordingStarted::dispatch();
}
</code-snippet>
@endverbatim

---

#### App switcher overlay — hide content in recents

Show a custom overlay instead of your real UI when the user switches apps.

@verbatim
<code-snippet name="App switcher — solid colour" lang="php">
use Codingwithrk\NoScreenshot\Facades\NoScreenshot;

// Solid colour (any CSS hex)
NoScreenshot::setAppSwitcherOverlayColor('#1a1a2e');

// Blurred snapshot of the current screen
NoScreenshot::setAppSwitcherOverlayBlur(blurRadius: 25.0);

// Custom image (base64-encoded PNG or JPEG)
$base64 = base64_encode(file_get_contents(public_path('splash.png')));
NoScreenshot::setAppSwitcherOverlayImage($base64);

// Remove overlay — show real content in recents
NoScreenshot::clearAppSwitcherOverlay();
</code-snippet>
@endverbatim

---

#### Screenshot detection — fire events when a screenshot is taken

@verbatim
<code-snippet name="Screenshot detection" lang="php">
use Codingwithrk\NoScreenshot\Facades\NoScreenshot;
use Codingwithrk\NoScreenshot\Events\ScreenshotAttempted;
use Native\Mobile\Attributes\OnNative;

// Start detecting (call once, e.g. in AppServiceProvider::boot())
NoScreenshot::startScreenshotDetection();

// Handle the event in any Livewire component
#[OnNative(ScreenshotAttempted::class)]
public function handleScreenshot(): void
{
    // Log, warn the user, or take any action
    $this->dispatch('screenshot-detected');
}

// Stop detecting
NoScreenshot::stopScreenshotDetection();
</code-snippet>
@endverbatim

**Platform notes:**
- **iOS** — detection works on all versions.
- **Android** — requires API 34 (Android 14). On older devices, `startScreenshotDetection()` succeeds but no events fire. Check `getStatus()->isScreenshotDetectionActive`.

---

### Available Methods

| Method | Returns | Description |
|--------|---------|-------------|
| `NoScreenshot::disableGlobally()` | `bool` | Protect the entire app |
| `NoScreenshot::enableGlobally()` | `bool` | Remove global protection (per-screen guards stay) |
| `NoScreenshot::toggle()` | `bool` | Toggle global protection; returns new state |
| `NoScreenshot::protectScreen(string $id)` | `bool` | Register a screen by ID |
| `NoScreenshot::unprotectScreen(string $id)` | `bool` | Unregister a screen by ID |
| `NoScreenshot::getStatus()` | `ScreenProtectionStatus` | Return full status DTO |
| `NoScreenshot::setAppSwitcherOverlayColor(string $color)` | `bool` | Solid colour overlay in recents |
| `NoScreenshot::setAppSwitcherOverlayBlur(float $blurRadius)` | `bool` | Blurred overlay in recents |
| `NoScreenshot::setAppSwitcherOverlayImage(string $base64)` | `bool` | Custom image overlay in recents |
| `NoScreenshot::clearAppSwitcherOverlay()` | `bool` | Remove recents overlay |
| `NoScreenshot::startScreenshotDetection()` | `bool` | Start screenshot event detection |
| `NoScreenshot::stopScreenshotDetection()` | `bool` | Stop screenshot event detection |

---

### Events

| Event | When |
|-------|------|
| `ScreenshotAttempted` | User takes a screenshot (requires `startScreenshotDetection()`) |
| `ScreenRecordingStarted` | Recording begins — `isScreenBeingRecorded` → true (iOS) |
| `ScreenRecordingStopped` | Recording ends — `isScreenBeingRecorded` → false (iOS) |

@verbatim
<code-snippet name="Listening for events" lang="php">
use Native\Mobile\Attributes\OnNative;
use Codingwithrk\NoScreenshot\Events\ScreenshotAttempted;
use Codingwithrk\NoScreenshot\Events\ScreenRecordingStarted;
use Codingwithrk\NoScreenshot\Events\ScreenRecordingStopped;

#[OnNative(ScreenshotAttempted::class)]
public function onScreenshot(): void
{
    $this->dispatch('notify', message: 'Screenshot detected!');
}

#[OnNative(ScreenRecordingStarted::class)]
public function onRecordingStarted(): void
{
    // e.g. blur sensitive content
}

#[OnNative(ScreenRecordingStopped::class)]
public function onRecordingStopped(): void
{
    // Restore normal view
}
</code-snippet>
@endverbatim

---

### JavaScript Usage

@verbatim
<code-snippet name="JavaScript — global" lang="javascript">
import { noScreenshot } from '@codingwithrk/no-screenshot';

await noScreenshot.disableGlobally();
await noScreenshot.enableGlobally();

const isProtected = await noScreenshot.toggle();
</code-snippet>
@endverbatim

@verbatim
<code-snippet name="JavaScript — per-screen (Alpine.js)" lang="javascript">
import { noScreenshot } from '@codingwithrk/no-screenshot';

Alpine.data('sensitiveView', () => ({
    async init() {
        await noScreenshot.protectScreen('payment');
    },
    async destroy() {
        await noScreenshot.unprotectScreen('payment');
    },
}));
</code-snippet>
@endverbatim

@verbatim
<code-snippet name="JavaScript — app switcher overlay" lang="javascript">
import { noScreenshot } from '@codingwithrk/no-screenshot';

// Solid colour
await noScreenshot.setAppSwitcherOverlayColor('#1a1a2e');

// Blur (adjust radius as needed)
await noScreenshot.setAppSwitcherOverlayBlur(25);

// Custom image (base64)
await noScreenshot.setAppSwitcherOverlayImage(base64String);

// Remove overlay
await noScreenshot.clearAppSwitcherOverlay();
</code-snippet>
@endverbatim

@verbatim
<code-snippet name="JavaScript — screenshot detection" lang="javascript">
import { noScreenshot } from '@codingwithrk/no-screenshot';

await noScreenshot.startScreenshotDetection();

// ScreenshotAttempted PHP event fires automatically on screenshot.
// Stop when no longer needed:
await noScreenshot.stopScreenshotDetection();
</code-snippet>
@endverbatim

@verbatim
<code-snippet name="JavaScript — detect recording (iOS)" lang="javascript">
import { noScreenshot } from '@codingwithrk/no-screenshot';

// Poll every 2 s to react to iOS recording state changes
setInterval(async () => {
    const status = await noScreenshot.getStatus();
    if (status.isScreenBeingRecorded) {
        console.warn('Screen recording detected!');
    }
}, 2000);
</code-snippet>
@endverbatim
