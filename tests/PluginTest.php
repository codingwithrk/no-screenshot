<?php

/**
 * Plugin validation tests for NoScreenshot.
 *
 * Run with: ./vendor/bin/pest
 */

beforeEach(function () {
    $this->pluginPath = dirname(__DIR__);
    $this->manifestPath = $this->pluginPath . '/nativephp.json';
});

describe('Plugin Manifest', function () {
    it('has a valid nativephp.json file', function () {
        expect(file_exists($this->manifestPath))->toBeTrue();

        $content = file_get_contents($this->manifestPath);
        json_decode($content, true);

        expect(json_last_error())->toBe(JSON_ERROR_NONE);
    });

    it('has required fields', function () {
        $manifest = json_decode(file_get_contents($this->manifestPath), true);

        expect($manifest)->toHaveKeys(['name', 'namespace', 'bridge_functions']);
        expect($manifest['name'])->toBe('codingwithrk/no-screenshot');
        expect($manifest['namespace'])->toBe('NoScreenshot');
    });

    it('has all six bridge functions', function () {
        $manifest = json_decode(file_get_contents($this->manifestPath), true);

        $names = array_column($manifest['bridge_functions'], 'name');

        expect($names)->toContain('NoScreenshot.DisableGlobal');
        expect($names)->toContain('NoScreenshot.EnableGlobal');
        expect($names)->toContain('NoScreenshot.Toggle');
        expect($names)->toContain('NoScreenshot.GetStatus');
        expect($names)->toContain('NoScreenshot.StartScreenshotDetection');
        expect($names)->toContain('NoScreenshot.StopScreenshotDetection');
        expect(count($names))->toBe(6);
        expect($names)->not->toContain('NoScreenshot.ProtectScreen');
        expect($names)->not->toContain('NoScreenshot.UnprotectScreen');
        expect($names)->not->toContain('NoScreenshot.SetAppSwitcherOverlay');
    });

    it('has valid bridge functions with platform references', function () {
        $manifest = json_decode(file_get_contents($this->manifestPath), true);

        expect($manifest['bridge_functions'])->toBeArray();

        foreach ($manifest['bridge_functions'] as $function) {
            expect($function)->toHaveKeys(['name']);
            // At least one platform must be declared
            expect(isset($function['android']) || isset($function['ios']))->toBeTrue();
        }
    });

    it('has three events', function () {
        $manifest = json_decode(file_get_contents($this->manifestPath), true);

        expect($manifest['events'])->toContain('Codingwithrk\\NoScreenshot\\Events\\ScreenshotAttempted');
        expect($manifest['events'])->toContain('Codingwithrk\\NoScreenshot\\Events\\ScreenRecordingStarted');
        expect($manifest['events'])->toContain('Codingwithrk\\NoScreenshot\\Events\\ScreenRecordingStopped');
    });

    it('has valid marketplace metadata', function () {
        $manifest = json_decode(file_get_contents($this->manifestPath), true);

        if (isset($manifest['keywords'])) {
            expect($manifest['keywords'])->toBeArray();
        }

        if (isset($manifest['category'])) {
            expect($manifest['category'])->toBeString();
        }

        if (isset($manifest['platforms'])) {
            expect($manifest['platforms'])->toBeArray();
            foreach ($manifest['platforms'] as $platform) {
                expect($platform)->toBeIn(['android', 'ios']);
            }
        }
    });
});

describe('Native Code', function () {
    it('has Android Kotlin file', function () {
        $kotlinFile = $this->pluginPath . '/resources/android/NoScreenshotFunctions.kt';

        expect(file_exists($kotlinFile))->toBeTrue();

        $content = file_get_contents($kotlinFile);
        expect($content)->toContain('package com.codingwithrk.plugins.no_screenshot');
        expect($content)->toContain('object NoScreenshotFunctions');
        expect($content)->toContain('BridgeFunction');
    });

    it('has iOS Swift file', function () {
        $swiftFile = $this->pluginPath . '/resources/ios/NoScreenshotFunctions.swift';

        expect(file_exists($swiftFile))->toBeTrue();

        $content = file_get_contents($swiftFile);
        expect($content)->toContain('enum NoScreenshotFunctions');
        expect($content)->toContain('BridgeFunction');
    });

    it('Kotlin implements ScreenshotGuardState singleton without app switcher code', function () {
        $content = file_get_contents($this->pluginPath . '/resources/android/NoScreenshotFunctions.kt');

        expect($content)->toContain('object ScreenshotGuardState');
        expect($content)->toContain('FLAG_SECURE');
        expect($content)->toContain('saveProtectionState');
        expect($content)->toContain('restoreProtectionState');
        expect($content)->not->toContain('AppSwitcherOverlayConfig');
        expect($content)->not->toContain('SetAppSwitcherOverlay');
        expect($content)->not->toContain('appSwitcherOverlay');
    });

    it('has Android ContentProvider init file that applies FLAG_SECURE at onCreate', function () {
        $file = $this->pluginPath . '/resources/android/NoScreenshotInitProvider.kt';
        expect(file_exists($file))->toBeTrue();

        $content = file_get_contents($file);
        expect($content)->toContain('package com.codingwithrk.plugins.no_screenshot');
        expect($content)->toContain('class NoScreenshotInitProvider');
        expect($content)->toContain('ContentProvider');
        expect($content)->toContain('registerActivityLifecycleCallbacks');
        expect($content)->toContain('FLAG_SECURE');
    });

    it('manifest registers NoScreenshotInitProvider as an Android ContentProvider', function () {
        $manifest = json_decode(file_get_contents($this->manifestPath), true);

        $providers = $manifest['android']['providers'] ?? [];
        expect($providers)->toBeArray();
        expect(count($providers))->toBeGreaterThanOrEqual(1);

        $names = array_column($providers, 'name');
        expect($names)->toContain('.NoScreenshotInitProvider');
    });

    it('Swift implements UIScreen capture observer without app switcher code', function () {
        $content = file_get_contents($this->pluginPath . '/resources/ios/NoScreenshotFunctions.swift');

        expect($content)->toContain('UIScreen.capturedDidChangeNotification');
        expect($content)->toContain('UIScreen.main.isCaptured');
        expect($content)->toContain('willResignActiveNotification');
        expect($content)->toContain('isSecureTextEntry');
        expect($content)->toContain('NativePHPNoScreenshotInit');
        expect($content)->not->toContain('AppSwitcherOverlayConfig');
        expect($content)->not->toContain('SetAppSwitcherOverlay');
        expect($content)->not->toContain('appSwitcherOverlay');
    });

    it('manifest declares iOS init_function', function () {
        $manifest = json_decode(file_get_contents($this->manifestPath), true);

        expect($manifest['ios']['init_function'] ?? null)->toBe('NativePHPNoScreenshotInit');
    });

    it('has matching bridge function classes in native code', function () {
        $manifest = json_decode(file_get_contents($this->manifestPath), true);

        $kotlinContent = file_get_contents($this->pluginPath . '/resources/android/NoScreenshotFunctions.kt');
        $swiftContent = file_get_contents($this->pluginPath . '/resources/ios/NoScreenshotFunctions.swift');

        foreach ($manifest['bridge_functions'] as $function) {
            if (isset($function['android'])) {
                $parts = explode('.', $function['android']);
                $className = end($parts);
                expect($kotlinContent)->toContain("class {$className}");
            }

            if (isset($function['ios'])) {
                $parts = explode('.', $function['ios']);
                $className = end($parts);
                expect($swiftContent)->toContain("class {$className}");
            }
        }
    });
});

describe('PHP Classes', function () {
    it('has service provider', function () {
        $file = $this->pluginPath . '/src/NoScreenshotServiceProvider.php';
        expect(file_exists($file))->toBeTrue();

        $content = file_get_contents($file);
        expect($content)->toContain('namespace Codingwithrk\NoScreenshot');
        expect($content)->toContain('class NoScreenshotServiceProvider');
    });

    it('has facade', function () {
        $file = $this->pluginPath . '/src/Facades/NoScreenshot.php';
        expect(file_exists($file))->toBeTrue();

        $content = file_get_contents($file);
        expect($content)->toContain('namespace Codingwithrk\NoScreenshot\Facades');
        expect($content)->toContain('class NoScreenshot extends Facade');
    });

    it('has main implementation class with global-only methods', function () {
        $file = $this->pluginPath . '/src/NoScreenshot.php';
        expect(file_exists($file))->toBeTrue();

        $content = file_get_contents($file);
        expect($content)->toContain('namespace Codingwithrk\NoScreenshot');
        expect($content)->toContain('class NoScreenshot');
        expect($content)->toContain('function disableGlobally');
        expect($content)->toContain('function enableGlobally');
        expect($content)->toContain('function toggle');
        expect($content)->toContain('function getStatus');
        expect($content)->toContain('function startScreenshotDetection');
        expect($content)->toContain('function stopScreenshotDetection');
        expect($content)->not->toContain('function protectScreen');
        expect($content)->not->toContain('function unprotectScreen');
    });

    it('has ScreenProtectionStatus DTO', function () {
        $file = $this->pluginPath . '/src/ScreenProtectionStatus.php';
        expect(file_exists($file))->toBeTrue();

        $content = file_get_contents($file);
        expect($content)->toContain('readonly class ScreenProtectionStatus');
        expect($content)->toContain('isGloballyProtected');
        expect($content)->toContain('isScreenBeingRecorded');
        expect($content)->toContain('isScreenshotDetectionActive');
        expect($content)->not->toContain('protectedScreens');
        expect($content)->not->toContain('appSwitcherOverlayType');
    });

    it('has all three event classes', function () {
        $events = [
            'ScreenshotAttempted',
            'ScreenRecordingStarted',
            'ScreenRecordingStopped',
        ];

        foreach ($events as $event) {
            $file = $this->pluginPath . "/src/Events/{$event}.php";
            expect(file_exists($file))->toBeTrue();

            $content = file_get_contents($file);
            expect($content)->toContain("class {$event}");
            expect($content)->toContain('use Dispatchable');
        }
    });
});

describe('Composer Configuration', function () {
    it('has valid composer.json', function () {
        $composerPath = $this->pluginPath . '/composer.json';
        expect(file_exists($composerPath))->toBeTrue();

        $composer = json_decode(file_get_contents($composerPath), true);

        expect(json_last_error())->toBe(JSON_ERROR_NONE);
        expect($composer['type'])->toBe('nativephp-plugin');
        expect($composer['extra']['nativephp']['manifest'])->toBe('nativephp.json');
    });
});

describe('Lifecycle Hooks', function () {
    it('has valid hooks configuration', function () {
        $manifest = json_decode(file_get_contents($this->manifestPath), true);

        if (isset($manifest['hooks'])) {
            expect($manifest['hooks'])->toBeArray();

            $validHooks = ['pre_compile', 'post_compile', 'copy_assets', 'post_build'];
            foreach (array_keys($manifest['hooks']) as $hook) {
                expect($hook)->toBeIn($validHooks);
            }
        }
    });

    it('has copy_assets hook command', function () {
        $manifest = json_decode(file_get_contents($this->manifestPath), true);

        expect($manifest['hooks']['copy_assets'] ?? null)->not->toBeNull();

        $commandFile = $this->pluginPath . '/src/Commands/CopyAssetsCommand.php';
        expect(file_exists($commandFile))->toBeTrue();
    });

    it('copy_assets command extends NativePluginHookCommand', function () {
        $commandFile = $this->pluginPath . '/src/Commands/CopyAssetsCommand.php';
        $content = file_get_contents($commandFile);

        expect($content)->toContain('extends NativePluginHookCommand');
        expect($content)->toContain('use Native\Mobile\Plugins\Commands\NativePluginHookCommand');
    });

    it('copy_assets command has correct signature', function () {
        $manifest = json_decode(file_get_contents($this->manifestPath), true);
        $expectedSignature = $manifest['hooks']['copy_assets'];

        $commandFile = $this->pluginPath . '/src/Commands/CopyAssetsCommand.php';
        $content = file_get_contents($commandFile);

        expect($content)->toContain('$signature = \'' . $expectedSignature . '\'');
    });

    it('copy_assets command has a handle method that returns SUCCESS', function () {
        $commandFile = $this->pluginPath . '/src/Commands/CopyAssetsCommand.php';
        $content = file_get_contents($commandFile);

        expect($content)->toContain('public function handle()');
        expect($content)->toContain('self::SUCCESS');
    });

    it('has valid assets configuration', function () {
        $manifest = json_decode(file_get_contents($this->manifestPath), true);

        if (isset($manifest['assets'])) {
            expect($manifest['assets'])->toBeArray();

            if (isset($manifest['assets']['android'])) {
                expect($manifest['assets']['android'])->toBeArray();
            }

            if (isset($manifest['assets']['ios'])) {
                expect($manifest['assets']['ios'])->toBeArray();
            }
        }
    });
});
