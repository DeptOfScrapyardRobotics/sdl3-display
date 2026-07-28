# dept-of-scrapyard-robotics/sdl3-display

[![Latest Version on Packagist](https://img.shields.io/packagist/v/dept-of-scrapyard-robotics/sdl3-display.svg)](https://packagist.org/packages/dept-of-scrapyard-robotics/sdl3-display)
[![License](https://img.shields.io/packagist/l/dept-of-scrapyard-robotics/sdl3-display.svg)](LICENSE)

Drive SDL3-powered windows from ScrapyardIO.

This package registers an `sdl3` windowed display panel (`SDL3Window`) that pairs with [`microscrap/sdl3-gfx`](https://packagist.org/packages/microscrap/sdl3-gfx) for rendering. Use it when you want a desktop window instead of (or in addition to) an embedded panel.

## Requirements

* PHP 8.3+
* **ext-sdl3** ^0.5.0 — [php-io-extensions/sdl3](https://github.com/php-io-extensions/sdl3)
* [`microscrap/sdl3`](https://packagist.org/packages/microscrap/sdl3) ^0.5.0
* [`microscrap/sdl3-gfx`](https://packagist.org/packages/microscrap/sdl3-gfx) ^0.6.0
* ScrapyardIO Framework 0.6 (`fabricate/displays`, …)

## Installation

Confirm the extension is loaded:

```bash
php -m | grep sdl3
```

### From SDL3 GFX (recommended)

If the app already has `microscrap/sdl3-gfx`:

```bash
workshop install:sdl3-display
```

That requires this package and runs `workshop config:sdl3-display` afterward. The install command is **hidden** once this package is already listed in the app’s `composer.json`.

### Via Composer

```bash
composer require dept-of-scrapyard-robotics/sdl3-display
php workshop package:discover
php workshop config:sdl3-display
```

Package discovery registers `DeptOfScrapyardRobotics\Displays\SDL3\Providers\SDL3DisplayServiceProvider`.

## Workshop configuration command

```bash
workshop config:sdl3-display
workshop config:sdl3-display --force
```

Adds a default entry under `config/displays.php` → `windowed.sdl3`:

```php
'windowed' => [
    'sdl3' => [
        'width' => 1024,
        'height' => 768,
        'scale_factor' => 1,
        'title' => env('APP_NAME'),
        'boot_now' => true,
    ],
],
```

The command is **hidden** when `config('displays.windowed.sdl3')` already exists. Pass `--force` to overwrite that block.

## Point `main` at the window (optional)

To make SDL3 the primary display:

```php
// config/displays.php
'main' => [
    'type' => 'windowed',
    'driver' => 'sdl3',
    'renderer' => 'sdl3',
    'buffer' => 'sdl3',
],
```

And ensure `config/gfx.php` can resolve the SDL3 engine:

```php
'rendering' => [
    'default' => 'sdl3',
    'engines' => [
        'sdl3' => [],
    ],
],
```

`workshop install:gfx --sdl3 --default=sdl3 --force` can set both of those for you when installing GFX.

## What it registers

| Display driver key | Class |
|---|---|
| `sdl3` | `DeptOfScrapyardRobotics\Displays\SDL3\SDL3Window` |

`SDL3Window` implements Fabricate’s software panel / boot sequence contracts and expects an `SDL3Gfx` renderer.

## Fresh Scrapyard checklist

```bash
# 1. Extension
php -m | grep sdl3

# 2. Rendering
workshop install:gfx --sdl3 --default=sdl3

# 3. Windowed display (if not pulled in already)
workshop install:sdl3-display

# 4. Confirm config
workshop config:show displays.windowed.sdl3
workshop config:show gfx.rendering.default
```

## Stack overview

```text
ext-sdl3
  └── microscrap/sdl3
        └── microscrap/sdl3-gfx
              └── dept-of-scrapyard-robotics/sdl3-display  ← this package
```

## License

MIT
