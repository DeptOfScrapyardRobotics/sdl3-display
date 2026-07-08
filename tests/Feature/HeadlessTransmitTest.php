<?php

namespace DeptOfScrapyardRobotics\Tests\Feature;

use BareMetal\Contracts\Framebuffers\DTO\DumpedBuffer;
use BareMetal\Contracts\Framebuffers\Enums\RenderType;
use DeptOfScrapyardRobotics\Displays\SDL3\SDL3Display;
use DeptOfScrapyardRobotics\Displays\SDL3\SDL3DisplayException;

beforeEach(function (): void {
    if (! extension_loaded('sdl3')) {
        $this->markTestSkipped('ext-sdl3 is not loaded');
    }
});

it('refuses to transmit before boot', function (): void {
    $display = SDL3Display::headless(4, 4);

    $display->transmit(new DumpedBuffer(
        RenderType::FULL,
        $display->formatSpec(),
        solidFrameBytes(4, 4, 255, 0, 0),
    ));
})->throws(SDL3DisplayException::class);

it('presents a transmitted full frame on the off-screen target', function (): void {
    $display = SDL3Display::headless(4, 2, boot_now: true);

    $display->transmit(new DumpedBuffer(
        RenderType::FULL,
        $display->formatSpec(),
        solidFrameBytes(4, 2, 255, 0, 0),
        width: 4,
        height: 2,
    ));

    $words = $display->transport()->readPixelWords();

    expect($words)->toHaveCount(8)
        ->and(array_unique($words))->toBe([0xFF0000FF]);
});

it('applies partial frames only inside their update window', function (): void {
    $display = SDL3Display::headless(4, 4, boot_now: true);

    // Baseline: solid blue everywhere
    $display->transmit(new DumpedBuffer(
        RenderType::FULL,
        $display->formatSpec(),
        solidFrameBytes(4, 4, 0, 0, 255),
        width: 4,
        height: 4,
    ));

    // Patch: 2x2 green at (2,1)
    $display->transmit(new DumpedBuffer(
        RenderType::PARTIAL,
        $display->formatSpec(),
        solidFrameBytes(2, 2, 0, 255, 0),
        origin_x: 2,
        origin_y: 1,
        width: 2,
        height: 2,
    ));

    $words = $display->transport()->readPixelWords();

    expect($words[(1 * 4) + 2])->toBe(0x00FF00FF)
        ->and($words[(2 * 4) + 3])->toBe(0x00FF00FF)
        ->and($words[0])->toBe(0x0000FFFF)
        ->and($words[(3 * 4) + 3])->toBe(0x0000FFFF);
});

it('scales the presented output by the pixel scale factor', function (): void {
    $display = SDL3Display::headless(2, 2, scale_factor: 3, boot_now: true);

    // Top row red, bottom row green
    $bytes = array_merge(
        solidFrameBytes(2, 1, 255, 0, 0),
        solidFrameBytes(2, 1, 0, 255, 0),
    );

    $display->transmit(new DumpedBuffer(
        RenderType::FULL,
        $display->formatSpec(),
        $bytes,
        width: 2,
        height: 2,
    ));

    $words = $display->transport()->readPixelWords();

    // 2x2 logical → 6x6 presented pixels
    expect($words)->toHaveCount(36)
        ->and($words[0])->toBe(0xFF0000FF)
        ->and($words[(2 * 6) + 5])->toBe(0xFF0000FF)
        ->and($words[(3 * 6)])->toBe(0x00FF00FF)
        ->and($words[35])->toBe(0x00FF00FF);
});

it('clears the panel back to opaque black', function (): void {
    $display = SDL3Display::headless(3, 3, boot_now: true);

    $display->transmit(new DumpedBuffer(
        RenderType::FULL,
        $display->formatSpec(),
        solidFrameBytes(3, 3, 255, 255, 255),
        width: 3,
        height: 3,
    ));

    $display->clear();

    expect(array_unique($display->transport()->readPixelWords()))->toBe([0x000000FF]);
});
