<?php

namespace DeptOfScrapyardRobotics\Displays\SDL3;

use DeptOfScrapyardRobotics\Displays\SDL3\Enums\SDL3OpCode;

trait SDL3DisplayAPI
{
    use SDL3DisplayInternalAPI;

    protected bool $_visible = true;

    protected bool $_fullscreen = false;

    public function setTitle(string $title): void
    {
        $this->transport->setTitle($title);
    }

    public function windowTitle(): ?string
    {
        return $this->transport->title();
    }

    /**
     * @throws SDL3DisplayException
     */
    public function show(): void
    {
        $this->command(SDL3OpCode::SHOW_WINDOW);
        $this->_visible = true;
    }

    /**
     * @throws SDL3DisplayException
     */
    public function hide(): void
    {
        $this->command(SDL3OpCode::HIDE_WINDOW);
        $this->_visible = false;
    }

    /**
     * @throws SDL3DisplayException
     */
    public function setFullscreen(bool $fullscreen): void
    {
        $this->command(SDL3OpCode::SET_FULLSCREEN, [(int) $fullscreen]);
        $this->_fullscreen = $fullscreen;
    }

    /**
     * Resize the OS window itself (pixels); the logical panel size is fixed.
     *
     * @throws SDL3DisplayException
     */
    public function setWindowSize(int $width, int $height): void
    {
        $this->command(SDL3OpCode::SET_WINDOW_SIZE, [$width, $height]);
    }

    /**
     * @throws SDL3DisplayException
     */
    public function setScaleFactor(int $scale_factor): void
    {
        if ($scale_factor < 1) {
            throw SDL3DisplayException::invalidScaleFactor($scale_factor);
        }

        $this->_scale_factor = $scale_factor;

        if ($this->hasBooted()) {
            $this->command(SDL3OpCode::SET_SCALE_FACTOR, [$scale_factor]);
        }
    }

    /**
     * Blank the panel to opaque black.
     *
     * @throws SDL3DisplayException
     */
    public function clear(): void
    {
        $this->command(SDL3OpCode::CLEAR);
    }

    /**
     * Re-present the current frame (e.g. after window events).
     *
     * @throws SDL3DisplayException
     */
    public function present(): void
    {
        $this->command(SDL3OpCode::PRESENT);
    }

    /**
     * Point the next data() payload at a sub-rectangle of the panel — the
     * SDL analog of an OLED/TFT setAddressWindow().
     *
     * @throws SDL3DisplayException
     */
    public function setUpdateWindow(int $x, int $y, int $width, int $height): void
    {
        $this->command(SDL3OpCode::SET_UPDATE_WINDOW, [$x, $y, $width, $height]);
    }
}
