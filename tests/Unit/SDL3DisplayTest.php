<?php

namespace DeptOfScrapyardRobotics\Tests\Unit;

use BareMetal\Contracts\Circuits\BootSequence;
use BareMetal\Contracts\Displays\WindowedDisplay;
use BareMetal\Contracts\Framebuffers\DTO\DumpedBuffer;
use BareMetal\Contracts\Framebuffers\Enums\BitDepth;
use BareMetal\Contracts\Framebuffers\Enums\Endianness;
use BareMetal\Contracts\Framebuffers\Enums\PixelFormat;
use BareMetal\Contracts\Framebuffers\Enums\RenderType;
use BareMetal\Contracts\Framebuffers\Enums\ScanDirection;
use DeptOfScrapyardRobotics\Displays\SDL3\Enums\SDL3OpCode;
use DeptOfScrapyardRobotics\Displays\SDL3\SDL3Display;
use DeptOfScrapyardRobotics\Displays\SDL3\SDL3DisplayException;
use DeptOfScrapyardRobotics\Displays\SDL3\SDL3WindowTransport;
use FakeWindowTransport;

describe('format spec', function (): void {
    it('advertises row-major RGBA8888 with the red byte first', function (): void {
        $spec = fakeDisplay()->formatSpec();

        expect($spec->pixel_format)->toBe(PixelFormat::ROW_MAJOR)
            ->and($spec->bit_depth)->toBe(BitDepth::B32)
            ->and($spec->scan_direction)->toBe(ScanDirection::TOP_TO_BOTTOM)
            ->and($spec->endianness)->toBe(Endianness::MSB)
            ->and($spec->bit_order)->toBeNull()
            ->and($spec->page_axis)->toBeNull()
            ->and($spec->palette)->toBeNull();
    });

    it('wears the windowed display taxonomy', function (): void {
        $display = fakeDisplay();

        expect($display)->toBeInstanceOf(WindowedDisplay::class)
            ->and($display)->toBeInstanceOf(BootSequence::class)
            ->and($display->width())->toBe(8)
            ->and($display->height())->toBe(4);
    });
});

describe('transport detection', function (): void {
    it('detects headless mode', function (): void {
        $transport = new SDL3WindowTransport(headless: true);

        expect($transport->active_transport)->toBe('headless');
    });

    it('detects window mode from a title', function (): void {
        $transport = new SDL3WindowTransport(window_title: 'Panel Preview');

        expect($transport->active_transport)->toBe('window')
            ->and($transport->title())->toBe('Panel Preview');
    });

    it('refuses construction without a target', function (): void {
        new SDL3WindowTransport;
    })->throws(SDL3DisplayException::class);
});

describe('boot sequence', function (): void {
    it('opens the transport at logical size × scale factor on boot', function (): void {
        $display = fakeDisplay(128, 64, scale_factor: 4);
        $transport = $display->transport();

        expect($display->hasBooted())->toBeFalse()
            ->and($transport->opens)->toBeEmpty();

        $display->boot();

        expect($display->hasBooted())->toBeTrue()
            ->and($transport->opens)->toBe([['width' => 128, 'height' => 64, 'scale_factor' => 4]]);
    });

    it('boots only once', function (): void {
        $display = fakeDisplay();
        $display->boot();
        $display->boot();

        expect($display->transport()->opens)->toHaveCount(1);
    });
});

describe('transmit dispatch', function (): void {
    it('sends a full frame with a full-surface update window', function (): void {
        $display = fakeDisplay(4, 2);
        $transport = $display->transport();

        $frame = new DumpedBuffer(
            RenderType::FULL,
            $display->formatSpec(),
            solidFrameBytes(4, 2, 255, 0, 0),
            width: 4,
            height: 2,
        );

        $display->transmit($frame);

        expect($transport->commands)->toHaveCount(1)
            ->and($transport->commands[0]['register'])->toBe(SDL3OpCode::SET_UPDATE_WINDOW->value)
            ->and($transport->commands[0]['data'])->toBe([0, 0, 4, 2])
            ->and($transport->data_payloads)->toHaveCount(1)
            ->and($transport->data_payloads[0])->toHaveCount(4 * 2 * 4);
    });

    it('honors origin and dimensions for partial frames', function (): void {
        $display = fakeDisplay(8, 8);
        $transport = $display->transport();

        $frame = new DumpedBuffer(
            RenderType::PARTIAL,
            $display->formatSpec(),
            solidFrameBytes(2, 3, 0, 255, 0),
            origin_x: 5,
            origin_y: 2,
            width: 2,
            height: 3,
        );

        $display->transmit($frame);

        expect($transport->commands[0]['data'])->toBe([5, 2, 2, 3])
            ->and($transport->data_payloads[0])->toHaveCount(2 * 3 * 4);
    });

    it('falls back to panel dimensions when the frame carries none', function (): void {
        $display = fakeDisplay(6, 3);

        $frame = new DumpedBuffer(
            RenderType::FULL,
            $display->formatSpec(),
            solidFrameBytes(6, 3, 0, 0, 255),
        );

        $display->transmit($frame);

        expect($display->transport()->commands[0]['data'])->toBe([0, 0, 6, 3]);
    });
});

describe('window-level API', function (): void {
    it('maps show/hide/fullscreen onto op codes', function (): void {
        $display = fakeDisplay();
        $transport = $display->transport();

        $display->hide();
        $display->show();
        $display->setFullscreen(true);

        $registers = array_column($transport->commands, 'register');

        expect($registers)->toBe([
            SDL3OpCode::HIDE_WINDOW->value,
            SDL3OpCode::SHOW_WINDOW->value,
            SDL3OpCode::SET_FULLSCREEN->value,
        ])
            ->and($transport->commands[2]['data'])->toBe([1])
            ->and($display->visible)->toBeTrue()
            ->and($display->fullscreen)->toBeTrue();
    });

    it('routes magic properties through the API', function (): void {
        $display = fakeDisplay();
        $transport = $display->transport();

        $display->title = 'Renamed';
        $display->visible = false;

        expect($transport->titles)->toBe(['Renamed'])
            ->and($display->title)->toBe('Renamed')
            ->and($display->visible)->toBeFalse()
            ->and(array_column($transport->commands, 'register'))->toBe([SDL3OpCode::HIDE_WINDOW->value]);
    });

    it('defers scale factor changes until booted', function (): void {
        $display = fakeDisplay(8, 4, scale_factor: 1);
        $transport = $display->transport();

        $display->scale_factor = 3;

        expect($display->scale_factor)->toBe(3)
            ->and($transport->commands)->toBeEmpty();

        $display->boot();
        $display->scale_factor = 2;

        expect($transport->commands[0]['register'])->toBe(SDL3OpCode::SET_SCALE_FACTOR->value)
            ->and($transport->commands[0]['data'])->toBe([2])
            ->and($transport->opens[0]['scale_factor'])->toBe(3);
    });

    it('rejects scale factors below one', function (): void {
        fakeDisplay()->setScaleFactor(0);
    })->throws(SDL3DisplayException::class);

    it('throws on unknown magic properties', function (): void {
        fakeDisplay()->nope;
    })->throws(SDL3DisplayException::class);
});
