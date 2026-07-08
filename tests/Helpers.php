<?php

use DeptOfScrapyardRobotics\Displays\SDL3\SDL3Display;
use DeptOfScrapyardRobotics\Displays\SDL3\SDL3WindowTransport;

/**
 * Records every command/data dispatch instead of touching SDL, so driver
 * behavior can be asserted without a window, surface, or even ext-sdl3.
 */
class FakeWindowTransport extends SDL3WindowTransport
{
    /** @var array<int, array{register: int, data: array}> */
    public array $commands = [];

    /** @var array<int, array<int, int>> */
    public array $data_payloads = [];

    /** @var array<int, array{width: int, height: int, scale_factor: int}> */
    public array $opens = [];

    /** @var array<int, string> */
    public array $titles = [];

    public function __construct()
    {
        parent::__construct(headless: true);
    }

    public function open(int $width, int $height, int $scale_factor = 1): void
    {
        $this->opens[] = ['width' => $width, 'height' => $height, 'scale_factor' => $scale_factor];
    }

    public function command(int $register, array $command_data = []): int
    {
        $this->commands[] = ['register' => $register, 'data' => $command_data];

        return 0;
    }

    public function data(array $data = []): void
    {
        $this->data_payloads[] = $data;
    }

    public function setTitle(string $title): void
    {
        $this->titles[] = $title;
        parent::setTitle($title);
    }
}

function fakeDisplay(int $width = 8, int $height = 4, int $scale_factor = 1): SDL3Display
{
    return new SDL3Display(new FakeWindowTransport, $width, $height, $scale_factor);
}

/**
 * Flat R,G,B,A byte list for a solid frame of the given color.
 *
 * @return array<int, int>
 */
function solidFrameBytes(int $width, int $height, int $r, int $g, int $b, int $a = 255): array
{
    $bytes = [];

    for ($i = 0; $i < ($width * $height); $i++) {
        array_push($bytes, $r, $g, $b, $a);
    }

    return $bytes;
}
