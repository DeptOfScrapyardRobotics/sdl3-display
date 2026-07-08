<?php

namespace DeptOfScrapyardRobotics\Displays\SDL3;

use BareMetal\Contracts\Circuits\BootSequence;
use BareMetal\Contracts\Displays\WindowedDisplay;
use BareMetal\Contracts\Framebuffers\DTO\DumpedBuffer;
use BareMetal\Contracts\Framebuffers\DTO\FormatSpec;
use BareMetal\Contracts\Framebuffers\Enums\BitDepth;
use BareMetal\Contracts\Framebuffers\Enums\Endianness;
use BareMetal\Contracts\Framebuffers\Enums\PixelFormat;
use BareMetal\Contracts\Framebuffers\Enums\ScanDirection;
use BareMetal\Displays\Display;
use ScrapyardIO\NutsAndBolts\ScrapyardIOException;

/**
 * An SDL3 window (or headless off-screen surface) wearing the same display-IC
 * anatomy as the physical drivers: transmit() streams already-transcoded
 * ROW_MAJOR RGBA8888 frames through the transport into a texture, and the
 * window scales them up for tiny-panel previews (128x64 at 4x).
 *
 * @property-read ?string $title
 * @property-read int $scale_factor
 * @property-read bool $visible
 * @property-read bool $fullscreen
 * @property-write string $window_title
 */
class SDL3Display extends Display implements BootSequence, WindowedDisplay
{
    use SDL3DisplayAPI;

    /**
     * @throws ScrapyardIOException
     */
    public function __construct(
        protected SDL3WindowTransport $transport,
        int $width,
        int $height,
        protected int $_scale_factor = 1,
        bool $boot_now = false,
    ) {
        parent::__construct($width, $height);

        if ($boot_now) {
            $this->boot();
        }
    }

    /**
     * @throws SDL3DisplayException
     */
    public function __get(string $name): mixed
    {
        return match ($name) {
            'title' => $this->windowTitle(),
            'scale_factor' => $this->_scale_factor,
            'visible' => $this->_visible,
            'fullscreen' => $this->_fullscreen,
            default => throw SDL3DisplayException::invalidProperty($name, static::class),
        };
    }

    /**
     * @throws SDL3DisplayException
     */
    public function __set(string $name, mixed $value): void
    {
        match ($name) {
            'title', 'window_title' => $this->setTitle((string) $value),
            'scale_factor' => $this->setScaleFactor((int) $value),
            'visible' => ((bool) $value) ? $this->show() : $this->hide(),
            'fullscreen' => $this->setFullscreen((bool) $value),
            default => throw SDL3DisplayException::invalidProperty($name, static::class),
        };
    }

    /**
     * Row-major RGBA8888, red byte first — identical to the spec the SDL3
     * GFX renderer dumps, so default component wiring never transcodes.
     */
    public function generateFormatSpec(): FormatSpec
    {
        return new FormatSpec(
            PixelFormat::ROW_MAJOR,
            BitDepth::B32,
            ScanDirection::TOP_TO_BOTTOM,
            endianness: Endianness::MSB,
        );
    }

    /**
     * Point the texture at the frame's window (partial refresh honors
     * origin_x/origin_y + dimensions), then clock the RGBA bytes out.
     *
     * @throws SDL3DisplayException
     */
    public function transmit(DumpedBuffer $frame): void
    {
        $width = $frame->width ?? $this->width;
        $height = $frame->height ?? $this->height;

        $this->setUpdateWindow($frame->origin_x, $frame->origin_y, $width, $height);
        $this->data($frame->raw_data);
    }

    public function transport(): SDL3WindowTransport
    {
        return $this->transport;
    }

    /**
     * A visible SDL window sized (width × scale_factor, height × scale_factor).
     *
     * @throws SDL3DisplayException
     */
    public static function window(
        string $title = 'ScrapyardIO',
        int $width = 128,
        int $height = 64,
        int $scale_factor = 1,
        int $window_flags = 0,
        bool $boot_now = false,
    ): static {
        $transport = new SDL3WindowTransport(window_title: $title, window_flags: $window_flags);

        return new self($transport, $width, $height, $scale_factor, $boot_now);
    }

    /**
     * An off-screen surface + software renderer — no video subsystem, safe
     * for CI and tests.
     *
     * @throws SDL3DisplayException
     */
    public static function headless(
        int $width = 128,
        int $height = 64,
        int $scale_factor = 1,
        bool $boot_now = false,
    ): static {
        $transport = new SDL3WindowTransport(headless: true);

        return new self($transport, $width, $height, $scale_factor, $boot_now);
    }
}
