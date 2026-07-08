<?php

namespace DeptOfScrapyardRobotics\Displays\SDL3;

use BareMetal\Contracts\Displays\DisplayException;

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
}
