import Foundation
import UIKit

// ---------------------------------------------------------------------------
// ScreenshotGuardState
//
// Singleton that tracks the current protection configuration and manages:
//   1. A black overlay window to block screen-recording capture.
//   2. An app-switcher overlay (willResignActive) so the OS thumbnail is black.
//   3. A UITextField isSecureTextEntry container that prevents screenshot
//      content from being captured — the OS renders the protected area as
//      blank in any screenshot taken while protection is active.
//   4. A screenshot-detection observer that fires a PHP event.
//   5. UserDefaults persistence so protection survives app restarts.
//
// iOS mechanisms:
//   - Screenshot prevention: wrap window content inside a UITextField that has
//     isSecureTextEntry = true.  iOS renders its subtree via the system DRM
//     compositing path, which is excluded from both screenshot capture and
//     screen-recording.  The secured area appears blank/white in screenshots.
//   - Screen-recording detection + mitigation: UIScreen.main.isCaptured
//     returns true whenever the screen is being recorded or mirrored.
//     When protection is active the plugin overlays a black UIWindow.
//   - App-switcher protection: observe UIApplication.willResignActiveNotification
//     and show the same overlay before the OS captures the recents thumbnail.
// ---------------------------------------------------------------------------

final class ScreenshotGuardState {

    static let shared = ScreenshotGuardState()
    private init() {}

    private let kIsGloballyProtected = "no_screenshot_is_globally_protected"

    // MARK: - Protection state

    var isGloballyProtected: Bool = false

    var isProtectionActive: Bool {
        isGloballyProtected
    }

    // MARK: - Screen-recording overlay

    private var captureObserver: NSObjectProtocol?
    private var recordingOverlayWindow: UIWindow?

    // MARK: - App-switcher protection

    private var willResignObserver: NSObjectProtocol?
    private var didBecomeActiveObserver: NSObjectProtocol?

    // MARK: - Screenshot prevention (UITextField isSecureTextEntry)

    private var secureTextField: UITextField?

    // MARK: - Screenshot detection

    var isScreenshotDetectionActive: Bool = false
    private var screenshotObserver: NSObjectProtocol?

    // MARK: - Persistence

    func saveProtectionState(_ protected: Bool) {
        isGloballyProtected = protected
        UserDefaults.standard.set(protected, forKey: kIsGloballyProtected)
    }

    func restoreProtectionState() {
        isGloballyProtected = UserDefaults.standard.bool(forKey: kIsGloballyProtected)
    }

    // MARK: - Apply protection

    /// Call after any state mutation that affects isProtectionActive.
    func apply() {
        DispatchQueue.main.async {
            if self.isProtectionActive {
                self.startCaptureObserver()
                self.syncRecordingOverlay()
                self.startBackgroundProtection()
                self.applyScreenshotPrevention()
            } else {
                self.stopCaptureObserver()
                self.stopBackgroundProtection()
                self.removeScreenshotPrevention()
            }
        }
    }

    // MARK: - Screen-recording capture observer

    private func startCaptureObserver() {
        guard captureObserver == nil else { return }

        captureObserver = NotificationCenter.default.addObserver(
            forName: UIScreen.capturedDidChangeNotification,
            object: nil,
            queue: .main
        ) { [weak self] _ in
            self?.syncRecordingOverlay()
        }
    }

    private func stopCaptureObserver() {
        if let obs = captureObserver {
            NotificationCenter.default.removeObserver(obs)
            captureObserver = nil
        }
        removeRecordingOverlay()
    }

    // MARK: - Recording overlay

    private func syncRecordingOverlay() {
        if UIScreen.main.isCaptured && isProtectionActive {
            showRecordingOverlay()
        } else {
            removeRecordingOverlay()
        }
    }

    private func showRecordingOverlay() {
        guard recordingOverlayWindow == nil else { return }
        guard let scene = activeWindowScene() else { return }

        let window = UIWindow(windowScene: scene)
        window.windowLevel = UIWindow.Level.statusBar + 1
        window.backgroundColor = .black
        window.isHidden = false

        let label = UILabel()
        label.text = "Screen recording is not allowed"
        label.textColor = .white
        label.textAlignment = .center
        label.font = .systemFont(ofSize: 18, weight: .semibold)
        label.translatesAutoresizingMaskIntoConstraints = false
        window.addSubview(label)

        NSLayoutConstraint.activate([
            label.centerXAnchor.constraint(equalTo: window.centerXAnchor),
            label.centerYAnchor.constraint(equalTo: window.centerYAnchor),
            label.leadingAnchor.constraint(greaterThanOrEqualTo: window.leadingAnchor, constant: 24),
            label.trailingAnchor.constraint(lessThanOrEqualTo: window.trailingAnchor, constant: -24),
        ])

        recordingOverlayWindow = window
    }

    private func removeRecordingOverlay() {
        recordingOverlayWindow?.isHidden = true
        recordingOverlayWindow = nil
    }

    // MARK: - App-switcher protection

    /// Register observers so the black overlay is shown when the app enters the
    /// background (preventing the OS from capturing a real screenshot for the
    /// app switcher) and hidden again when the app returns to the foreground.
    private func startBackgroundProtection() {
        guard willResignObserver == nil else { return }

        willResignObserver = NotificationCenter.default.addObserver(
            forName: UIApplication.willResignActiveNotification,
            object: nil,
            queue: .main
        ) { [weak self] _ in
            guard let self = self, self.isProtectionActive else { return }
            self.showRecordingOverlay()
        }

        didBecomeActiveObserver = NotificationCenter.default.addObserver(
            forName: UIApplication.didBecomeActiveNotification,
            object: nil,
            queue: .main
        ) { [weak self] _ in
            guard let self = self else { return }
            // Keep overlay if screen recording is still active; otherwise hide it.
            if !UIScreen.main.isCaptured {
                self.removeRecordingOverlay()
            }
            // Window may not have been ready during apply(); retry here.
            if self.isProtectionActive && self.secureTextField == nil {
                self.applyScreenshotPrevention()
            }
        }
    }

    private func stopBackgroundProtection() {
        if let obs = willResignObserver {
            NotificationCenter.default.removeObserver(obs)
            willResignObserver = nil
        }
        if let obs = didBecomeActiveObserver {
            NotificationCenter.default.removeObserver(obs)
            didBecomeActiveObserver = nil
        }
    }

    // MARK: - Screenshot prevention (UITextField isSecureTextEntry)

    /// Move all window content into the secure rendering container of a
    /// UITextField that has isSecureTextEntry = true.
    ///
    /// iOS routes the subtree of that container through its system DRM
    /// compositing path, which is excluded from screenshot capture.  The
    /// protected area appears blank in any screenshot taken while active.
    ///
    /// Falls back gracefully if the internal container is unavailable (e.g.
    /// future iOS changes the UITextField structure).
    private func applyScreenshotPrevention() {
        guard secureTextField == nil, let window = getMainWindow() else { return }

        let textField = UITextField()
        textField.isSecureTextEntry = true
        textField.frame = window.bounds
        textField.autoresizingMask = [.flexibleWidth, .flexibleHeight]
        textField.isUserInteractionEnabled = false

        // Insert behind existing content so z-order is preserved after the move.
        window.insertSubview(textField, at: 0)
        window.layoutIfNeeded()

        // UITextField lazily creates its internal content view on layout.
        // That first subview is the secure rendering container.
        guard let secureContainer = textField.subviews.first else {
            textField.removeFromSuperview()
            return
        }

        secureContainer.frame = window.bounds
        secureContainer.autoresizingMask = [.flexibleWidth, .flexibleHeight]
        secureContainer.isUserInteractionEnabled = true

        // Move every existing window subview (except the textField itself) into
        // the secure container; frames are preserved because secureContainer
        // covers the same bounds as the window.
        let contentViews = window.subviews.filter { $0 !== textField }
        for view in contentViews {
            let savedFrame = view.frame
            secureContainer.addSubview(view)
            view.frame = savedFrame
        }

        secureTextField = textField
    }

    /// Restore window content from the secure container back to the window and
    /// remove the UITextField, leaving the hierarchy as it was before.
    private func removeScreenshotPrevention() {
        guard let textField = secureTextField,
              let secureContainer = textField.subviews.first,
              let window = textField.window else {
            secureTextField = nil
            return
        }

        for view in Array(secureContainer.subviews) {
            let savedFrame = view.frame
            window.addSubview(view)
            view.frame = savedFrame
        }

        textField.removeFromSuperview()
        secureTextField = nil
    }

    // MARK: - Screenshot detection

    /// Register a UIApplication.userDidTakeScreenshotNotification observer that
    /// dispatches a ScreenshotAttempted PHP event via LaravelBridge.
    func startScreenshotDetection() {
        guard !isScreenshotDetectionActive else { return }
        isScreenshotDetectionActive = true

        screenshotObserver = NotificationCenter.default.addObserver(
            forName: UIApplication.userDidTakeScreenshotNotification,
            object: nil,
            queue: .main
        ) { _ in
            LaravelBridge.shared.send?(
                "Codingwithrk\\NoScreenshot\\Events\\ScreenshotAttempted",
                [:]
            )
        }
    }

    /// Unregister the screenshot observer.
    func stopScreenshotDetection() {
        isScreenshotDetectionActive = false
        if let obs = screenshotObserver {
            NotificationCenter.default.removeObserver(obs)
            screenshotObserver = nil
        }
    }

    // MARK: - Helpers

    private func activeWindowScene() -> UIWindowScene? {
        UIApplication.shared.connectedScenes
            .compactMap { $0 as? UIWindowScene }
            .first(where: { $0.activationState == .foregroundActive })
            ?? UIApplication.shared.connectedScenes
                .compactMap { $0 as? UIWindowScene }
                .first
    }

    private func getMainWindow() -> UIWindow? {
        guard let scene = activeWindowScene() else { return nil }
        return scene.windows.first(where: { $0.isKeyWindow }) ?? scene.windows.first
    }
}

// ---------------------------------------------------------------------------
// Plugin initialisation
//
// Called by NativePHP at app startup (before bridge functions are registered).
// Restores the persisted protection state and re-arms all observers so that
// the UITextField screenshot prevention and app-switcher overlay are active
// from the very first frame — not only after the PHP controller calls
// disableGlobally().
// ---------------------------------------------------------------------------

@_cdecl("NativePHPNoScreenshotInit")
public func NativePHPNoScreenshotInit() {
    ScreenshotGuardState.shared.restoreProtectionState()
    if ScreenshotGuardState.shared.isGloballyProtected {
        // apply() dispatches to main; the window may not exist yet at this
        // point.  The didBecomeActive observer registered inside apply() will
        // retry applyScreenshotPrevention() once the window is ready.
        ScreenshotGuardState.shared.apply()
    }
}

// ---------------------------------------------------------------------------
// Bridge functions
// ---------------------------------------------------------------------------

enum NoScreenshotFunctions {

    // -----------------------------------------------------------------------

    /// Enable protection for the entire app.
    ///
    /// Android: sets FLAG_SECURE (blocks all OS capture paths).
    /// iOS: activates the UITextField screenshot prevention, the
    ///      UIScreen.capturedDidChangeNotification observer, and the
    ///      willResignActive overlay so the app-switcher thumbnail is black.
    ///      Persists the choice so protection survives app restarts.
    ///
    /// Response: { success: Bool, isGloballyProtected: Bool }
    class DisableGlobal: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            ScreenshotGuardState.shared.saveProtectionState(true)
            ScreenshotGuardState.shared.apply()

            return BridgeResponse.success(data: [
                "success": true,
                "isGloballyProtected": true,
            ])
        }
    }

    // -----------------------------------------------------------------------

    /// Remove global protection. All observers and the secure container are torn down.
    ///
    /// Response: { success: Bool, isGloballyProtected: Bool }
    class EnableGlobal: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            ScreenshotGuardState.shared.saveProtectionState(false)
            ScreenshotGuardState.shared.apply()

            return BridgeResponse.success(data: [
                "success": true,
                "isGloballyProtected": false,
            ])
        }
    }

    // -----------------------------------------------------------------------

    /// Toggle global protection on/off.
    ///
    /// Response: { success: Bool, isGloballyProtected: Bool }
    class Toggle: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            let state = ScreenshotGuardState.shared
            state.saveProtectionState(!state.isGloballyProtected)
            state.apply()

            return BridgeResponse.success(data: [
                "success": true,
                "isGloballyProtected": state.isGloballyProtected,
            ])
        }
    }

    // -----------------------------------------------------------------------

    /// Return the current protection status.
    ///
    /// isScreenBeingRecorded reflects UIScreen.main.isCaptured in real time —
    /// true whenever the screen is being recorded or AirPlay-mirrored.
    ///
    /// Response: {
    ///   isGloballyProtected:         Bool,
    ///   isScreenBeingRecorded:       Bool,
    ///   isScreenshotDetectionActive: Bool
    /// }
    class GetStatus: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            let state = ScreenshotGuardState.shared

            return BridgeResponse.success(data: [
                "isGloballyProtected": state.isGloballyProtected,
                "isScreenBeingRecorded": UIScreen.main.isCaptured,
                "isScreenshotDetectionActive": state.isScreenshotDetectionActive,
            ])
        }
    }

    // -----------------------------------------------------------------------

    /// Start detecting screenshot events.
    ///
    /// Registers a UIApplication.userDidTakeScreenshotNotification observer.
    /// When the user takes a screenshot, a ScreenshotAttempted PHP event is
    /// dispatched via LaravelBridge.
    ///
    /// Supported on all iOS versions.
    ///
    /// Response: { success: Bool, supported: Bool, isScreenshotDetectionActive: Bool }
    class StartScreenshotDetection: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            DispatchQueue.main.async {
                ScreenshotGuardState.shared.startScreenshotDetection()
            }

            return BridgeResponse.success(data: [
                "success": true,
                "supported": true,
                "isScreenshotDetectionActive": true,
            ])
        }
    }

    // -----------------------------------------------------------------------

    /// Stop detecting screenshot events.
    ///
    /// Response: { success: Bool, isScreenshotDetectionActive: Bool }
    class StopScreenshotDetection: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            DispatchQueue.main.async {
                ScreenshotGuardState.shared.stopScreenshotDetection()
            }

            return BridgeResponse.success(data: [
                "success": true,
                "isScreenshotDetectionActive": false,
            ])
        }
    }
}
