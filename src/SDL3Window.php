<?php

namespace DeptOfScrapyardRobotics\Displays\SDL3;

use DeptOfScrapyardRobotics\Displays\SDL3\Concerns\SDL3WindowAPI;
use Exception;
use Fabricate\Contracts\Displays\Interfaces\SoftwarePanel;
use Fabricate\Contracts\Framebuffers\Enums\BitDepth;
use Fabricate\Contracts\Framebuffers\Enums\Endianness;
use Fabricate\Contracts\Framebuffers\Enums\PixelFormat;
use Fabricate\Contracts\Framebuffers\Enums\ScanDirection;
use Fabricate\Contracts\Framebuffers\Framebuffer;
use Fabricate\Contracts\NutsAndBolts\BootSequence;
use Fabricate\Contracts\Rendering\GFXRenderer;
use Fabricate\Framebuffers\DataObjects\DumpedBuffer;
use Fabricate\Framebuffers\FormatSpec;
use Microscrap\Bindings\SDL3\Events;
use Microscrap\Bindings\SDL3\Render;
use Microscrap\Bindings\SDL3\Video;
use Microscrap\GFX\SDL3\SDL3Gfx;
use Microscrap\GFX\SDL3\Sdl3Framebuffer;

class SDL3Window implements SoftwarePanel, BootSequence
{
    use SDL3WindowAPI;

    /**
     * @throws Exception
     */
    public function __construct(
        protected int $width,
        protected int $height,
        protected int $scale_factor = 1,
        protected string $title = 'ScrapyardIO',
        bool $boot_now = false,
    ) {
        if ($this->scale_factor < 1) {
            throw SDL3DisplayException::invalidScaleFactor($this->scale_factor);
        }

        if ($boot_now) {
            $this->boot();
        }
    }

    public function height(): int
    {
        return $this->height;
    }

    public function width(): int
    {
        return $this->width;
    }

    public function supportsRenderer(GFXRenderer $renderer): bool
    {
        return $renderer instanceof SDL3Gfx;
    }

    public function supportsFramebuffer(Framebuffer $framebuffer): bool
    {
        return $framebuffer instanceof Sdl3Framebuffer;
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

        if (! is_null($this->native_window)) {
            Video::destroyWindow($this->native_window);
            $this->native_window = null;
        }

        Events::pumpEvents();

        $this->booted = false;
    }

    /**
     * Row-major RGBA8888, red byte first — identical to the spec the SDL3
     * GFX renderer dumps, so default component wiring never transcodes.
     */
    public function formatSpec(): FormatSpec
    {
        return new FormatSpec(
            PixelFormat::ROW_MAJOR,
            BitDepth::B32,
            ScanDirection::TOP_TO_BOTTOM,
            endianness: Endianness::MSB,
        );
    }

    public function transmit(DumpedBuffer $frame): void
    {
        if (
            $frame->metadata->pixel_format !== PixelFormat::ROW_MAJOR
            || $frame->metadata->bit_depth !== BitDepth::B32
            || $frame->metadata->scan_direction !== ScanDirection::TOP_TO_BOTTOM
        ) {
            throw SDL3DisplayException::unsupportedFrameFormat(
                $frame->metadata->pixel_format->value,
                $frame->metadata->bit_depth->value,
                $frame->metadata->scan_direction->name,
            );
        }

        $width = $frame->width ?? $this->width;
        $height = $frame->height ?? $this->height;

        if ($width <= 0 || $height <= 0) {
            throw SDL3DisplayException::invalidFrameDimensions($width, $height);
        }

        if (
            $frame->origin_x < 0
            || $frame->origin_y < 0
            || ($frame->origin_x + $width) > $this->width
            || ($frame->origin_y + $height) > $this->height
        ) {
            throw SDL3DisplayException::frameOutsideWindow(
                $frame->origin_x,
                $frame->origin_y,
                $width,
                $height,
                $this->width,
                $this->height,
            );
        }

        $expected_bytes = $width * $height * 4;

        if (count($frame->raw_data) !== $expected_bytes) {
            throw SDL3DisplayException::invalidFramePayload(
                $expected_bytes,
                count($frame->raw_data),
            );
        }

        $bytes = $frame->metadata->endianness === Endianness::LSB
            ? $this->toRgbaBytes($frame->raw_data)
            : $frame->raw_data;

        $is_full_frame = $frame->origin_x === 0
            && $frame->origin_y === 0
            && $width === $this->width
            && $height === $this->height;

        Render::updateTexture(
            $this->requireTexture(),
            $this->packBytes($bytes),
            $width * 4,
            $is_full_frame
                ? null
                : [$frame->origin_x, $frame->origin_y, $width, $height],
        );

        $this->presentTexture();
    }

    /**
     * @param array<int, int> $bytes
     *
     * @return array<int, int>
     */
    protected function toRgbaBytes(array $bytes): array
    {
        $rgba = [];

        foreach (array_chunk($bytes, 4) as [$alpha, $blue, $green, $red]) {
            $rgba[] = $red;
            $rgba[] = $green;
            $rgba[] = $blue;
            $rgba[] = $alpha;
        }

        return $rgba;
    }

    /**
     * Pack RGBA byte ints into a binary string without materializing every
     * chunk at once — `array_chunk()` on a 1024×768 frame (~3M ints) was
     * doubling peak memory and OOM'ing the 4GB CLI limit during present.
     *
     * @param array<int, int> $bytes
     */
    protected function packBytes(array $bytes): string
    {
        $length = count($bytes);

        if ($length === 0) {
            return '';
        }

        $parts = [];

        for ($offset = 0; $offset < $length; $offset += 8192) {
            $parts[] = pack('C*', ...array_slice($bytes, $offset, 8192));
        }

        return implode('', $parts);
    }
}
