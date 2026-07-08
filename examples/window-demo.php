<?php

/**
 * Manual visual check for SDL3Display (run from this package):
 *
 *     php examples/window-demo.php
 *
 * Opens a 128x64 "panel" scaled 4x, streams a full-frame plasma background,
 * then bounces a red block over it using PARTIAL frames only — the same
 * transmit() path a DisplayComponent drives.
 */

use BareMetal\Contracts\Framebuffers\DTO\DumpedBuffer;
use BareMetal\Contracts\Framebuffers\Enums\RenderType;
use DeptOfScrapyardRobotics\Displays\SDL3\SDL3Display;
use Microscrap\Bindings\SDL3\Events;

require __DIR__.'/../vendor/autoload.php';

$panel_width = 128;
$panel_height = 64;

$display = SDL3Display::window(
    title: 'ScrapyardIO — SDL3Display demo (128x64 @ 4x)',
    width: $panel_width,
    height: $panel_height,
    scale_factor: 4,
    boot_now: true,
);

/**
 * @return array<int, int> R,G,B,A bytes for the full plasma background
 */
function plasmaFrame(int $width, int $height, float $t): array
{
    $bytes = [];

    for ($y = 0; $y < $height; $y++) {
        for ($x = 0; $x < $width; $x++) {
            $v = sin($x / 12 + $t) + sin($y / 8 - $t) + sin(($x + $y) / 16);
            $bytes[] = (int) (127 + 90 * sin($v * M_PI / 3));        // R
            $bytes[] = (int) (127 + 90 * sin($v * M_PI / 3 + 2));    // G
            $bytes[] = (int) (127 + 90 * sin($v * M_PI / 3 + 4));    // B
            $bytes[] = 255;                                          // A
        }
    }

    return $bytes;
}

/**
 * @return array<int, int> R,G,B,A bytes for a solid block
 */
function blockFrame(int $width, int $height, int $r, int $g, int $b): array
{
    $bytes = [];

    for ($i = 0; $i < ($width * $height); $i++) {
        array_push($bytes, $r, $g, $b, 255);
    }

    return $bytes;
}

$spec = $display->formatSpec();
$block = 10;
$x = 8;
$y = 6;
$dx = 2;
$dy = 1;

echo "Streaming frames for ~10 seconds — close the window or wait.\n";

$started = microtime(true);
$frame = 0;

while ((microtime(true) - $started) < 10.0) {
    Events::pumpEvents();

    // Full refresh: the plasma background swallows the previous block position.
    $display->transmit(new DumpedBuffer(
        RenderType::FULL,
        $spec,
        plasmaFrame($panel_width, $panel_height, $frame / 10),
        width: $panel_width,
        height: $panel_height,
    ));

    // Partial refresh: only the bouncing block's window is retransmitted.
    $display->transmit(new DumpedBuffer(
        RenderType::PARTIAL,
        $spec,
        blockFrame($block, $block, 255, 30, 30),
        origin_x: $x,
        origin_y: $y,
        width: $block,
        height: $block,
    ));

    $x += $dx;
    $y += $dy;

    if (($x <= 0) || (($x + $block) >= $panel_width)) {
        $dx *= -1;
    }

    if (($y <= 0) || (($y + $block) >= $panel_height)) {
        $dy *= -1;
    }

    $frame++;
    usleep(33_000);
}

echo "Done — {$frame} frames.\n";
