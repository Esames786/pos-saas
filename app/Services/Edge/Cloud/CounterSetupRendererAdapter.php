<?php

namespace App\Services\Edge\Cloud;

use App\Contracts\Edge\CounterSetupRenderer;
use Illuminate\Support\Str;

/**
 * EDGE 0.9 (K7) — resolves the counter-setup renderer WITHOUT a hard dependency on stream D:
 *   1. a container binding of App\Contracts\Edge\CounterSetupRenderer, else
 *   2. App\Services\Edge\EdgeCounterSetupRenderer when that class exists (stream D) — used directly when it implements the
 *      contract, otherwise wrapped (D adds the `implements` clause at integration; the method shapes are identical), else
 *   3. null (the page disables its counter / phone buttons with a hint).
 */
final class CounterSetupRendererAdapter implements CounterSetupRenderer
{
    public const STREAM_D_CLASS = 'App\\Services\\Edge\\EdgeCounterSetupRenderer';

    private function __construct(private readonly object $inner) {}

    public static function resolve(): ?CounterSetupRenderer
    {
        if (app()->bound(CounterSetupRenderer::class)) {
            return app(CounterSetupRenderer::class);
        }
        $class = self::STREAM_D_CLASS;
        if (! class_exists($class)) {
            return null;
        }
        $instance = app($class);
        if ($instance instanceof CounterSetupRenderer) {
            return $instance;
        }
        if (method_exists($instance, 'renderWindows') && method_exists($instance, 'phoneOnboarding')) {   // renderWindowsWizard optional (pre-a5a8446d D)
            return new self($instance);
        }

        return null;
    }

    public function renderWindows(array $lanProfile, string $onlinePosUrl, string $branchLabel): string
    {
        return $this->inner->renderWindows($lanProfile, $onlinePosUrl, $branchLabel);
    }

    public function phoneOnboarding(array $lanProfile): array
    {
        return $this->inner->phoneOnboarding($lanProfile);
    }

    public function renderWindowsWizard(array $lanProfile, string $onlinePosUrl, string $branchLabel, array $context = []): string
    {
        if (! method_exists($this->inner, 'renderWindowsWizard')) {
            throw new \RuntimeException('The Offline Counter wizard is not available on this Cloud yet.');
        }

        return $this->inner->renderWindowsWizard($lanProfile, $onlinePosUrl, $branchLabel, $context);
    }

    /** The wizard's download name (stream D: wizardFileName), else Install-Offline-Counter-<branch>.ps1. */
    public static function wizardFileNameFor(CounterSetupRenderer $renderer, string $branchLabel, int $branchId): string
    {
        $target = $renderer instanceof self ? $renderer->inner : $renderer;
        if (method_exists($target, 'wizardFileName')) {
            $name = (string) $target->wizardFileName($branchLabel);
            if (preg_match('/^[A-Za-z0-9._-]{1,120}\.ps1$/', $name)) {
                return $name;
            }
        }

        return 'Install-Offline-Counter-' . (Str::slug($branchLabel) ?: 'Branch-' . $branchId) . '.ps1';
    }

    /** The renderer's own download name when it has one (stream D: windowsFileName), else Setup-<branch>-Counter.ps1. */
    public static function fileNameFor(CounterSetupRenderer $renderer, string $branchLabel, int $branchId): string
    {
        $target = $renderer instanceof self ? $renderer->inner : $renderer;
        if (method_exists($target, 'windowsFileName')) {
            $name = (string) $target->windowsFileName($branchLabel);
            if (preg_match('/^[A-Za-z0-9._-]{1,120}\.ps1$/', $name)) {
                return $name;
            }
        }

        return 'Setup-' . (Str::slug($branchLabel) ?: 'Branch-' . $branchId) . '-Counter.ps1';
    }
}
