<?php

namespace DeptOfScrapyardRobotics\Displays\SDL3;

use BareMetal\Contracts\Displays\DataCommandTransfers;
use DeptOfScrapyardRobotics\Displays\SDL3\Enums\SDL3OpCode;
use GPIO\Common\SignalTransporter;
use Microscrap\Bindings\SDL3\DataObjects\SDLRenderer;
use Microscrap\Bindings\SDL3\DataObjects\SDLSurfaceRef;
use Microscrap\Bindings\SDL3\DataObjects\SDLTexture;
use Microscrap\Bindings\SDL3\DataObjects\SDLWindow;
use Microscrap\Bindings\SDL3\Enums\InitFlag;
use Microscrap\Bindings\SDL3\Enums\PixelFormat as SdlPixelFormat;
use Microscrap\Bindings\SDL3\Enums\ScaleMode;
use Microscrap\Bindings\SDL3\Enums\TextureAccess;
use Microscrap\Bindings\SDL3\Error;
use Microscrap\Bindings\SDL3\Init;
use Microscrap\Bindings\SDL3\Render;
use Microscrap\Bindings\SDL3\Surface;
use Microscrap\Bindings\SDL3\Video;

/**
 * The SDL analog of an I2C/SPI display transport: data() clocks packed RGBA
 * frame bytes into a streaming texture (then presents it), command() maps
 * the SDL3OpCode "registers" onto window-level operations.
 *
 * Owns the SDLWindow/SDLRenderer/SDLTexture (windowed) or the off-screen
 * SDLSurfaceRef + software renderer (headless) for its whole lifetime.
 */
class SDL3WindowTransport extends SignalTransporter implements DataCommandTransfers
{
    protected ?SDLWindow $window = null;

    protected ?SDLSurfaceRef $surface = null;

    protected ?SDLRenderer $renderer = null;

    protected ?SDLTexture $texture = null;

    /**
     * The pending texture window the next data() payload targets: [x, y, w, h].
     */
    protected ?array $update_window = null;

    protected int $logical_width = 0;

    protected int $logical_height = 0;

    protected int $scale_factor = 1;

    /**
     * @throws SDL3DisplayException
     */
    public function __construct(
        protected ?string $window_title = null,
        protected bool $headless = false,
        protected int $window_flags = 0,
    ) {
        parent::__construct($this->detectTransport());
    }

    public function __destruct()
    {
        $this->close();
    }

    /**
     * @throws SDL3DisplayException
     */
    protected function detectTransport(): string
    {
        if ($this->headless) {
            return 'headless';
        }

        if (! is_null($this->window_title)) {
            return 'window';
        }

        throw SDL3DisplayException::transportMissingTarget();
    }

    /**
     * Bring the SDL side up: video subsystem + window + renderer (windowed)
     * or an off-screen surface + software renderer (headless), plus the
     * streaming texture every frame lands in. The window/surface is sized at
     * logical dimensions × scale factor; the texture stays logical so tiny
     * panels can be previewed enlarged (128x64 at 4x) with crisp pixels.
     *
     * @throws SDL3DisplayException
     */
    public function open(int $width, int $height, int $scale_factor = 1): void
    {
        if ($this->isOpen()) {
            return;
        }

        if ($scale_factor < 1) {
            throw SDL3DisplayException::invalidScaleFactor($scale_factor);
        }

        $this->logical_width = $width;
        $this->logical_height = $height;
        $this->scale_factor = $scale_factor;

        if ($this->active_transport === 'window') {
            Init::init(InitFlag::SDL_INIT_VIDEO);

            $window = Video::createWindow($this->window_title, $width * $scale_factor, $height * $scale_factor, $this->window_flags);
            if (is_null($window)) {
                throw SDL3DisplayException::sdlFailure('create window', Error::getError());
            }

            $renderer = Render::createRenderer($window->ptr);
            if (is_null($renderer)) {
                Video::destroyWindow($window);

                throw SDL3DisplayException::sdlFailure('create window renderer', Error::getError());
            }

            $this->window = $window;
            $this->renderer = $renderer;
        } else {
            Init::init(0);

            $surface = Surface::createSurface($width * $scale_factor, $height * $scale_factor, SdlPixelFormat::SDL_PIXELFORMAT_RGBA8888);
            if (is_null($surface)) {
                throw SDL3DisplayException::sdlFailure('create off-screen surface', Error::getError());
            }

            $renderer = Render::createSoftwareRenderer($surface->ptr);
            if (is_null($renderer)) {
                Surface::destroySurface($surface);

                throw SDL3DisplayException::sdlFailure('create software renderer', Error::getError());
            }

            $this->surface = $surface;
            $this->renderer = $renderer;
        }

        // RGBA32 = byte-order R,G,B,A regardless of host endianness — the
        // same order transcoded ROW_MAJOR B32 (MSB) frames carry their bytes.
        $texture = Render::createTexture(
            $this->renderer,
            SdlPixelFormat::SDL_PIXELFORMAT_RGBA32(),
            TextureAccess::SDL_TEXTUREACCESS_STREAMING,
            $width,
            $height
        );

        if (is_null($texture)) {
            $this->close();

            throw SDL3DisplayException::sdlFailure('create streaming texture', Error::getError());
        }

        $this->texture = $texture;
        Render::setTextureScaleMode($texture, ScaleMode::SDL_SCALEMODE_NEAREST);

        $this->clearTexture();
    }

    public function isOpen(): bool
    {
        return ! is_null($this->texture);
    }

    public function close(): void
    {
        if (! is_null($this->texture)) {
            Render::destroyTexture($this->texture);
            $this->texture = null;
        }

        if (! is_null($this->renderer)) {
            Render::destroyRenderer($this->renderer);
            $this->renderer = null;
        }

        if (! is_null($this->surface)) {
            Surface::destroySurface($this->surface);
            $this->surface = null;
        }

        if (! is_null($this->window)) {
            Video::destroyWindow($this->window);
            $this->window = null;
        }
    }

    // -- DataCommandTransfers ----------------------------------------------------

    /**
     * Stream packed R,G,B,A frame bytes into the texture — the whole surface
     * by default, or just the window set by a preceding SET_UPDATE_WINDOW
     * command (partial refresh) — then present.
     *
     * @param  array<int, int>  $data
     *
     * @throws SDL3DisplayException
     */
    public function data(array $data = []): void
    {
        $this->assertOpen();

        $window = $this->update_window;
        $this->update_window = null;

        $x = $window[0] ?? 0;
        $y = $window[1] ?? 0;
        $width = $window[2] ?? $this->logical_width;
        $height = $window[3] ?? $this->logical_height;

        $is_full_frame = ($x === 0) && ($y === 0)
            && ($width === $this->logical_width) && ($height === $this->logical_height);

        Render::updateTexture(
            $this->texture,
            $this->packBytes($data),
            $width * 4,
            $is_full_frame ? null : [$x, $y, $width, $height]
        );

        $this->present();
    }

    /**
     * @throws SDL3DisplayException
     */
    public function command(int $register, array $command_data = []): int
    {
        $op_code = SDL3OpCode::tryFrom($register);

        if (is_null($op_code)) {
            throw SDL3DisplayException::unsupportedOpCode($register);
        }

        match ($op_code) {
            SDL3OpCode::SET_UPDATE_WINDOW => $this->update_window = [
                (int) ($command_data[0] ?? 0),
                (int) ($command_data[1] ?? 0),
                (int) ($command_data[2] ?? $this->logical_width),
                (int) ($command_data[3] ?? $this->logical_height),
            ],
            SDL3OpCode::SHOW_WINDOW => $this->withWindow(fn (SDLWindow $window) => Video::showWindow($window)),
            SDL3OpCode::HIDE_WINDOW => $this->withWindow(fn (SDLWindow $window) => Video::hideWindow($window)),
            SDL3OpCode::SET_FULLSCREEN => $this->withWindow(
                fn (SDLWindow $window) => Video::setWindowFullscreen($window, (bool) ($command_data[0] ?? 0))
            ),
            SDL3OpCode::SET_WINDOW_SIZE => $this->withWindow(
                fn (SDLWindow $window) => Video::setWindowSize($window, (int) ($command_data[0] ?? 0), (int) ($command_data[1] ?? 0))
            ),
            SDL3OpCode::SET_SCALE_FACTOR => $this->applyScaleFactor((int) ($command_data[0] ?? 1)),
            SDL3OpCode::CLEAR => $this->clearTexture(),
            SDL3OpCode::PRESENT => $this->present(),
        };

        return 0;
    }

    // -- Window-level helpers ------------------------------------------------------

    public function setTitle(string $title): void
    {
        $this->window_title = $title;

        if (! is_null($this->window)) {
            Video::setWindowTitle($this->window, $title);
        }
    }

    public function title(): ?string
    {
        return $this->window_title;
    }

    public function scaleFactor(): int
    {
        return $this->scale_factor;
    }

    /**
     * Re-blit the current texture (scaled to the full output) and present it.
     *
     * @throws SDL3DisplayException
     */
    public function present(): void
    {
        $this->assertOpen();

        Render::setRenderDrawColor($this->renderer, 0, 0, 0, 255);
        Render::renderClear($this->renderer);
        Render::renderTexture($this->renderer, $this->texture, null, [
            0.0,
            0.0,
            (float) ($this->logical_width * $this->scale_factor),
            (float) ($this->logical_height * $this->scale_factor),
        ]);
        Render::renderPresent($this->renderer);
    }

    // -- Introspection (tests, demos) ------------------------------------------------

    public function window(): ?SDLWindow
    {
        return $this->window;
    }

    public function sdlRenderer(): ?SDLRenderer
    {
        return $this->renderer;
    }

    public function sdlSurface(): ?SDLSurfaceRef
    {
        return $this->surface;
    }

    public function texture(): ?SDLTexture
    {
        return $this->texture;
    }

    /**
     * The presented output as flat row-major 0xRRGGBBAA words (scaled size).
     *
     * @return array<int, int>
     *
     * @throws SDL3DisplayException
     */
    public function readPixelWords(): array
    {
        $this->assertOpen();

        $result = Render::renderReadPixels($this->renderer);

        return array_values($result['pixels']['data'] ?? []);
    }

    // -- Internals ---------------------------------------------------------------------

    /**
     * @throws SDL3DisplayException
     */
    protected function assertOpen(): void
    {
        if (! $this->isOpen()) {
            throw SDL3DisplayException::notBooted();
        }
    }

    protected function withWindow(callable $operation): void
    {
        if (! is_null($this->window)) {
            $operation($this->window);
        }
    }

    /**
     * @throws SDL3DisplayException
     */
    protected function applyScaleFactor(int $scale_factor): void
    {
        if ($scale_factor < 1) {
            throw SDL3DisplayException::invalidScaleFactor($scale_factor);
        }

        $this->scale_factor = $scale_factor;

        $this->withWindow(fn (SDLWindow $window) => Video::setWindowSize(
            $window,
            $this->logical_width * $scale_factor,
            $this->logical_height * $scale_factor
        ));

        if ($this->isOpen()) {
            $this->present();
        }
    }

    /**
     * Reset the whole texture to opaque black and present the blank frame.
     *
     * @throws SDL3DisplayException
     */
    protected function clearTexture(): void
    {
        $this->assertOpen();

        $black = str_repeat(pack('C4', 0, 0, 0, 255), $this->logical_width * $this->logical_height);

        Render::updateTexture($this->texture, $black, $this->logical_width * 4, null);
        $this->present();
    }

    /**
     * @param  array<int, int>  $bytes
     */
    protected function packBytes(array $bytes): string
    {
        $binary = '';

        foreach (array_chunk($bytes, 8192) as $chunk) {
            $binary .= pack('C*', ...$chunk);
        }

        return $binary;
    }
}
