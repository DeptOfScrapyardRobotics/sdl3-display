<?php

namespace DeptOfScrapyardRobotics\Displays\SDL3\Providers;

use DeptOfScrapyardRobotics\Displays\SDL3\SDL3Window;
use Fabricate\NutsAndBolts\MagicAliases\Display;
use Fabricate\NutsAndBolts\ServiceProvider;

class SDL3DisplayServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        Display::addWPanel('sdl3', SDL3Window::class);
    }
}
