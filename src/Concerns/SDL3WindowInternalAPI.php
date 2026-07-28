<?php

namespace DeptOfScrapyardRobotics\Displays\SDL3\Concerns;

use DeptOfScrapyardRobotics\Displays\SDL3\SDL3DisplayException;
use Fabricate\Contracts\NutsAndBolts\BootScaffolding;
use Fabricate\NutsAndBolts\Concerns\Splices16Bits;
use Microscrap\Bindings\SDL3\DataObjects\SDLRenderer;
use Microscrap\Bindings\SDL3\DataObjects\SDLTexture;
use Microscrap\Bindings\SDL3\DataObjects\SDLWindow;
use Microscrap\Bindings\SDL3\Enums\EventType;
use Microscrap\Bindings\SDL3\Enums\InitFlag;
use Microscrap\Bindings\SDL3\Enums\PixelFormat;
use Microscrap\Bindings\SDL3\Enums\ScaleMode;
use Microscrap\Bindings\SDL3\Enums\TextureAccess;
use Microscrap\Bindings\SDL3\Error;
use Microscrap\Bindings\SDL3\Events;
use Microscrap\Bindings\SDL3\Init;
use Microscrap\Bindings\SDL3\Render;
use Microscrap\Bindings\SDL3\Video;

trait SDL3WindowInternalAPI
{
    use BootScaffolding;
    use Splices16Bits;

    protected ?SDLWindow $native_window = null;

    protected ?SDLRenderer $renderer = null;

    protected ?SDLTexture $texture = null;

    protected bool $close_requested = false;

    /**
     * Open an SDL3 window + renderer, then show/raise it so macOS paints a
     * real surface (dock icon alone is not enough without the event pump).
     *
     * @throws SDL3DisplayException
     */
    protected function _boot(): void
    {
        Init::init(InitFlag::SDL_INIT_VIDEO);

        $scale = max(1, $this->scale_factor);
        $physical_width = $this->width * $scale;
        $physical_height = $this->height * $scale;

        $result = Video::createWindowAndRenderer(
            $this->title,
            $physical_width,
            $physical_height,
        );

        $window = $result['window'] ?? null;
        $renderer = $result['renderer'] ?? null;

        if (is_null($window) || is_null($renderer)) {
            if (! is_null($renderer)) {
                Render::destroyRenderer($renderer);
            }

            if (! is_null($window)) {
                Video::destroyWindow($window);
            }

            throw SDL3DisplayException::sdlFailure(
                'createWindowAndRenderer',
                Error::getError(),
            );
        }

        $this->native_window = $window;
        $this->renderer = $renderer;

        $texture = Render::createTexture(
            $renderer,
            PixelFormat::SDL_PIXELFORMAT_RGBA32(),
            TextureAccess::SDL_TEXTUREACCESS_STREAMING,
            $this->width,
            $this->height,
        );

        if (is_null($texture)) {
            $this->close();

            throw SDL3DisplayException::sdlFailure(
                'createTexture',
                Error::getError(),
            );
        }

        $this->texture = $texture;
        Render::setTextureScaleMode($texture, ScaleMode::SDL_SCALEMODE_NEAREST);
        Render::updateTexture(
            $texture,
            str_repeat(pack('C4', 0, 0, 0, 255), $this->width * $this->height),
            $this->width * 4,
        );

        $this->presentTexture();
        $this->show();
        $this->raise();
        $this->pumpEvents();
    }

    public function nativeWindow(): ?SDLWindow
    {
        return $this->native_window;
    }

    public function renderer(): ?SDLRenderer
    {
        return $this->renderer;
    }

    public function texture(): ?SDLTexture
    {
        return $this->texture;
    }

    public function show(): static
    {
        if (! is_null($this->native_window)) {
            Video::showWindow($this->native_window);
        }

        return $this;
    }

    public function raise(): static
    {
        if (! is_null($this->native_window)) {
            Video::raiseWindow($this->native_window);
        }

        return $this;
    }

    /**
     * Pump the Cocoa/SDL event queue — required on macOS for the window to
     * actually appear and refresh after present. Also drains quit/close.
     */
    public function pumpEvents(): static
    {
        Events::pumpEvents();

        while (! is_null($event = Events::pollEvent())) {
            if (
                $event->eventType === EventType::SDL_EVENT_QUIT->value
                || $event->eventType === EventType::SDL_EVENT_WINDOW_CLOSE_REQUESTED->value
            ) {
                $this->close_requested = true;
            }

            Events::freeEvent($event);
        }

        return $this;
    }

    /**
     * True after SDL_EVENT_QUIT (window chrome close / app quit).
     */
    public function shouldClose(): bool
    {
        if (is_null($this->native_window)) {
            return true;
        }

        $this->pumpEvents();

        return $this->close_requested;
    }

    /**
     * Window-level clear using the owned SDL renderer (RGBA8888 word).
     *
     * @throws SDL3DisplayException
     */
    public function clear(int $color = 0x000000FF): static
    {
        $renderer = $this->requireRenderer();
        [$r, $g, $b, $a] = $this->unpackRgba($color);
        Render::setRenderDrawColor($renderer, $r, $g, $b, $a);
        Render::renderClear($renderer);

        return $this;
    }

    /**
     * Present the current backbuffer and pump events so the frame is visible.
     *
     * Does not show/raise the window — that is boot-only so the OS focus
     * owner is respected after the user switches apps.
     *
     * @throws SDL3DisplayException
     */
    public function present(): static
    {
        $renderer = $this->requireRenderer();
        Render::renderPresent($renderer);
        $this->pumpEvents();

        return $this;
    }


    /**
     * @throws SDL3DisplayException
     */
    protected function requireRenderer(): SDLRenderer
    {
        if (is_null($this->renderer)) {
            throw SDL3DisplayException::notBooted();
        }

        return $this->renderer;
    }

    /**
     * @throws SDL3DisplayException
     */
    protected function requireTexture(): SDLTexture
    {
        if (is_null($this->texture)) {
            throw SDL3DisplayException::notBooted();
        }

        return $this->texture;
    }

    /**
     * Draw the logical streaming texture across the scaled window.
     *
     * @throws SDL3DisplayException
     */
    protected function presentTexture(): void
    {
        $renderer = $this->requireRenderer();
        $texture = $this->requireTexture();
        Render::setRenderDrawColor($renderer, 0, 0, 0, 255);
        Render::renderClear($renderer);
        Render::renderTexture($renderer, $texture, null, [
            0.0,
            0.0,
            (float) ($this->width * max(1, $this->scale_factor)),
            (float) ($this->height * max(1, $this->scale_factor)),
        ]);
        $this->present();
    }

    /**
     * Unpack RRGGBBAA via {@see Splices16Bits}.
     *
     * @return array{0: int, 1: int, 2: int, 3: int}
     */
    protected function unpackRgba(int $color): array
    {
        $rg = $this->splitBytes($color >> 16);
        $ba = $this->splitBytes($color);

        return [$rg['high'], $rg['low'], $ba['high'], $ba['low']];
    }
}
