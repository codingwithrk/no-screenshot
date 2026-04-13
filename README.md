# NoScreenshot plugin for NativePHP Mobile

A NativePHP Mobile plugin that prevents screenshots, blocks screen recording and global protection in App-switch state in your mobile app.

Mainly useful for apps that handle sensitive data, such as `financial`, `healthcare`, or `enterprise applications`, where protecting user `privacy and data security` is paramount.

---

## Platform Support

| Feature                | Android |             iOS              |
|------------------------|:-------:|:----------------------------:|
| Block screenshots      |    ✅    | ⚠️ Detected, not preventable |
| Block screen recording |    ✅    |       ✅ Black overlay        |
| Detect live recording  |    —    |              ✅               |
| Global protection      |    ✅    |              ✅               |

### How It Works

**Android** uses `WindowManager.LayoutParams.FLAG_SECURE` — an OS-level window flag that prevents the system from capturing the screen by any means: the screenshot button, the built-in screen recorder, ADB shell, and third-party capture apps all receive a blank or blocked frame.

**iOS** cannot prevent the system screenshot gesture at the application level. Instead the plugin:

- Observes `UIScreen.capturedDidChangeNotification`
- When recording starts and protection is active, immediately overlays a full-screen black `UIWindow` (above the status bar) that hides all WebView content
- Reports `isScreenBeingRecorded` in real time via `UIScreen.main.isCaptured`

---

## Requirements

|                  | Minimum                       |
|------------------|-------------------------------|
| PHP              | 8.2                           |
| NativePHP Mobile | 3.x                           |
| Android          | API 21 (Android 5.0 Lollipop) |
| iOS              | 13.0                          |

> `FLAG_SECURE` is available from Android API 1. API 21 is the minimum set to match NativePHP Mobile's own requirements. The iOS 13 minimum is required because the recording overlay uses `UIWindowScene`, introduced in iOS 13.

---

## Installation

```bash
composer require codingwithrk/no-screenshot

php artisan native:plugin:register codingwithrk/no-screenshot
```

The service provider and `NoScreenshot` facade alias are auto-discovered by Laravel — no manual registration needed.

---

## Quick Start

```php
use Codingwithrk\NoScreenshot\Facades\NoScreenshot;

// Protect the entire app
NoScreenshot::disableGlobally();

// Lift global protection
NoScreenshot::enableGlobally();
```

---

## PHP API

All methods are available via the `NoScreenshot` facade or by resolving `Codingwithrk\NoScreenshot\NoScreenshot` from the container.

### `disableGlobally(): bool`

Activates protection for the entire app.

- **Android** — adds `FLAG_SECURE` to the activity window; all capture attempts receive a blank frame.
- **iOS** — registers the `UIScreen.capturedDidChangeNotification` observer; if recording is already in progress, the black overlay appears immediately.

Returns `true` on success, `false` if running outside NativePHP.

```php
NoScreenshot::disableGlobally();
```

---

### `enableGlobally(): bool`

Removes global protection.

```php
NoScreenshot::enableGlobally();
```

---

### `toggle(): bool`

Toggles global protection on/off. Returns the new `isGloballyProtected` state.

```php
$isNowProtected = NoScreenshot::toggle();
```

---

### `getStatus(): ?ScreenProtectionStatus`

Returns the current protection state as a typed DTO, or `null` outside NativePHP.

```php
$status = NoScreenshot::getStatus();

$status->isGloballyProtected;         // bool — true after disableGlobally()
$status->isScreenBeingRecorded;       // bool — iOS: live UIScreen.main.isCaptured; Android: always false
$status->isScreenshotDetectionActive; // bool — true when screenshot detection is running
```

---

## Events

Three events cover the full lifecycle of capture activity. Subscribe to them in your Livewire components or event listeners.

| Event                    | Dispatched when                                             |
|--------------------------|-------------------------------------------------------------|
| `ScreenshotAttempted`    | A screenshot was taken (detected, cannot be blocked on iOS) |
| `ScreenRecordingStarted` | `isScreenBeingRecorded` transitions `false → true`          |
| `ScreenRecordingStopped` | `isScreenBeingRecorded` transitions `true → false`          |

> **Android note:** `FLAG_SECURE` prevents capture rather than detecting it, so events are not dispatched automatically. Dispatch them manually from your polling logic if needed.

### Listening with `#[OnNative]`

```php
use Native\Mobile\Attributes\OnNative;
use Codingwithrk\NoScreenshot\Events\ScreenshotAttempted;
use Codingwithrk\NoScreenshot\Events\ScreenRecordingStarted;
use Codingwithrk\NoScreenshot\Events\ScreenRecordingStopped;

class MyLivewireComponent extends Component
{
    #[OnNative(ScreenshotAttempted::class)]
    public function onScreenshotAttempted(): void
    {
        // Log the attempt, notify the user, etc.
        logger()->warning('Screenshot attempted');
    }

    #[OnNative(ScreenRecordingStarted::class)]
    public function onRecordingStarted(): void
    {
        $this->dispatch('recording-started'); // trigger frontend update
    }

    #[OnNative(ScreenRecordingStopped::class)]
    public function onRecordingStopped(): void
    {
        $this->dispatch('recording-stopped');
    }
}
```

### Manual Dispatch from a Controller

```php
use Codingwithrk\NoScreenshot\Facades\NoScreenshot;
use Codingwithrk\NoScreenshot\Events\ScreenRecordingStarted;
use Codingwithrk\NoScreenshot\Events\ScreenRecordingStopped;
use Codingwithrk\NoScreenshot\Events\ScreenshotAttempted;

// In a controller action polled by the frontend:
$status = NoScreenshot::getStatus();

match (true) {
    $status->isScreenBeingRecorded => ScreenRecordingStarted::dispatch(),
    default                        => ScreenRecordingStopped::dispatch(),
};

ScreenshotAttempted::dispatch();
```

---

## ScreenProtectionStatus Reference

| Property                      | Type   | Android        | iOS                             |
|-------------------------------|--------|----------------|---------------------------------|
| `isGloballyProtected`         | `bool` | ✅              | ✅                               |
| `isScreenBeingRecorded`       | `bool` | Always `false` | Live `UIScreen.main.isCaptured` |
| `isScreenshotDetectionActive` | `bool` | API 34+ only   | ✅ All versions                  |

---

## Platform Notes

### Android — `min_sdk_version: 21`

- `FLAG_SECURE` has been available since API 1, but NativePHP Mobile itself targets API 21+.
- The flag covers the **entire activity window** — all capture attempts (screenshot button, built-in recorder, ADB, third-party apps) receive a blank frame.
- The flag also hides the app thumbnail in the **Recents / App Switcher**.
- Screenshot *detection* via `registerScreenCaptureCallback` requires API 34+. On older devices, `startScreenshotDetection()` returns `supported: false` and no events fire.

### iOS — `min_version: 13.0`

- **iOS 13** is the minimum because the recording overlay uses `UIWindowScene`, which was introduced in iOS 13.
- `UIScreen.main.isCaptured` (available iOS 11+) is `true` during screen recording **and** AirPlay mirroring — the overlay appears in both cases.
- **Screenshots cannot be prevented** at the application level. The image is saved to Photos before the notification fires. The plugin can detect attempts via `UIApplication.userDidTakeScreenshotNotification` and dispatch `ScreenshotAttempted`, but the file is already saved by then.
- The black overlay is a `UIWindow` at `UIWindow.Level.statusBar + 1`, placed above all app content including the NativePHP WebView.

---

## Testing

### Device / simulator testing

```bash
# Install in your NativePHP app (uses path repository)
composer require codingwithrk/no-screenshot

# Run on Android
php artisan native:run android

# Run on iOS
php artisan native:run ios
```

Then trigger `NoScreenshot::disableGlobally()` from a controller or Livewire component and:

**Android** — press the screenshot button. The OS shows "Can't take screenshot due to security policy."

**iOS** — start a screen recording from Control Center. The black overlay should appear immediately and disappear when recording stops.

---

## Support

For questions or issues, email [connect@codingwithrk.com](mailto:connect@codingwithrk.com)

## License

The MIT License (MIT). Please see [License File](LICENSE) for more information.
