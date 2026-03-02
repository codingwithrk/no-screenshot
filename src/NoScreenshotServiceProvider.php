<?php

namespace Codingwithrk\NoScreenshot;

use Illuminate\Support\ServiceProvider;
use Codingwithrk\NoScreenshot\Commands\CopyAssetsCommand;

class NoScreenshotServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(NoScreenshot::class, function () {
            return new NoScreenshot();
        });

        $this->app->alias(NoScreenshot::class, 'no-screenshot');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                CopyAssetsCommand::class,
            ]);
        }
    }
}
