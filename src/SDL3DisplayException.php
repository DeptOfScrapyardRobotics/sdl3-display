<?php

namespace DeptOfScrapyardRobotics\Displays\SDL3;

use Fabricate\Contracts\Displays\DisplayException;

class SDL3DisplayException extends DisplayException
{
    public static function transportMissingTarget(): static
    {
        return new static('SDL3Display requires either a window title (windowed) or the headless flag.');
    }

    public static function notBooted(): static
    {
        return new static('The SDL window/surface has not been opened yet — boot() the display first.');
    }

    public static function unsupportedOpCode(int $register): static
    {
        return new static("Unknown SDL3 window op code {$register}.");
    }

    public static function sdlFailure(string $operation, string $sdl_error = ''): static
    {
        $detail = ($sdl_error === '') ? '' : " SDL says: {$sdl_error}";

        return new static("SDL operation failed: {$operation}.{$detail}");
    }

    public static function invalidProperty(string $name, string $class): static
    {
        return new static("Unknown property {$name} on {$class}.");
    }

    public static function invalidScaleFactor(int $scale_factor): static
    {
        return new static("Scale factor must be at least 1, you input {$scale_factor}.");
    }

    public static function unsupportedFrameFormat(
        string $pixel_format,
        int $bit_depth,
        string $scan_direction,
    ): static {
        return new static(
            "SDL3 windows require a top-to-bottom, 32-bit row-major frame; received "
            ."{$pixel_format} at {$bit_depth} bits with {$scan_direction} scanning."
        );
    }

    public static function invalidFramePayload(int $expected, int $actual): static
    {
        return new static(
            "SDL3 frame payload requires {$expected} bytes; received {$actual}."
        );
    }

    public static function invalidFrameDimensions(int $width, int $height): static
    {
        return new static(
            "SDL3 frame dimensions must be positive; received {$width}x{$height}."
        );
    }

    public static function frameOutsideWindow(
        int $x,
        int $y,
        int $width,
        int $height,
        int $window_width,
        int $window_height,
    ): static {
        return new static(
            "SDL3 frame region {$width}x{$height} at ({$x}, {$y}) exceeds "
            ."the {$window_width}x{$window_height} window."
        );
    }
}
