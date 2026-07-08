<?php

namespace DeptOfScrapyardRobotics\Displays\SDL3\Enums;

/**
 * The window-level "registers" SDL3WindowTransport::command() understands —
 * the SDL analog of a display controller's opcode set.
 */
enum SDL3OpCode: int
{
    case SET_UPDATE_WINDOW = 0x01;

    case SHOW_WINDOW = 0x02;

    case HIDE_WINDOW = 0x03;

    case SET_FULLSCREEN = 0x04;

    case SET_WINDOW_SIZE = 0x05;

    case SET_SCALE_FACTOR = 0x06;

    case CLEAR = 0x07;

    case PRESENT = 0x08;
}
