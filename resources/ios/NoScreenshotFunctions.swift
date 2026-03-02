import Foundation
import UIKit

// ---------------------------------------------------------------------------
// ScreenshotGuardState
//
// Singleton that tracks the current protection configuration and manages:
//   1. A black overlay window to block screen-recording capture.
//   2. A screenshot-detection observer that fires a PHP event.
//
// iOS mechanisms:
//   - Screenshot prevention: iOS does not allow apps to block the system
//     screenshot gesture. Detection is available via
//     UIApplication.userDidTakeScreenshotNotification.
//   - Screen-recording detection + mitigation: UIScreen.main.isCaptured
//     returns true whenever the screen is being recorded or mirrored.
//     When protection is active the plugin overlays a black UIWindow.
// ---------------------------------------------------------------------------

final class ScreenshotGuardState {

    static let shared = ScreenshotGuardState()
    private init() {}

    // MARK: - Protection state

    var isGloballyProtected: Bool = false

    var isProtectionActive: Bool {
        isGloballyProtected
    }

    // MARK: - Screen-recording overlay

    private var captureObserver: NSObjectProtocol?
    private var recordingOverlayWindow: UIWindow?

    // MARK: - Screenshot detection

    var isScreenshotDetectionActive: Bool = false
    private var screenshotObserver: NSObjectProtocol?

    // MARK: - Apply protection

    /// Call after any state mutation that affects isProtectionActive.
    func apply() {
        DispatchQueue.main.async {
            if self.isProtectionActive {
                self.startCaptureObserver()
                self.syncRecordingOverlay()
            } else {
                self.stopCaptureObserver()
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
}

// ---------------------------------------------------------------------------
// Bridge functions
// ---------------------------------------------------------------------------

enum NoScreenshotFunctions {

    // -----------------------------------------------------------------------

    /// Enable protection for the entire app.
    ///
    /// Starts observing UIScreen.capturedDidChangeNotification and immediately
    /// shows the black overlay if the screen is already being recorded.
    ///
    /// Response: { success: Bool, isGloballyProtected: Bool }
    class DisableGlobal: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            ScreenshotGuardState.shared.isGloballyProtected = true
            ScreenshotGuardState.shared.apply()

            return BridgeResponse.success(data: [
                "success": true,
                "isGloballyProtected": true,
            ])
        }
    }

    // -----------------------------------------------------------------------

    /// Remove global protection. The recording overlay observer is stopped.
    ///
    /// Response: { success: Bool, isGloballyProtected: Bool }
    class EnableGlobal: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            ScreenshotGuardState.shared.isGloballyProtected = false
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
            state.isGloballyProtected = !state.isGloballyProtected
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
