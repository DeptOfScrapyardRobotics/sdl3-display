<?php

namespace DeptOfScrapyardRobotics\Displays\SDL3\Console;

use Fabricate\Console\Command;
use Fabricate\Filesystem\Filesystem;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'config:sdl3-display')]
class ConfigureSdl3DisplayCommand extends Command
{
    protected ?string $signature = 'config:sdl3-display
                    {--force : Overwrite an existing windowed.sdl3 entry}';

    protected string $description = 'Add a default SDL3 entry to config/displays.php windowed displays';

    public function isHidden(): bool
    {
        return $this->hasWindowedEntry();
    }

    public function handle(): int
    {
        $path = $this->scrapyard_io->configPath('displays.php');
        $files = new Filesystem;

        if (! $files->exists($path)) {
            $this->components->error("Missing configuration file [{$path}].");

            return self::FAILURE;
        }

        if ($this->hasWindowedEntry() && ! $this->option('force')) {
            $this->components->info('Windowed SDL3 display configuration already exists.');

            return self::SUCCESS;
        }

        if (! $this->writeWindowedEntry($files, $path)) {
            $this->components->error('Unable to update [config/displays.php] with an SDL3 windowed entry.');

            return self::FAILURE;
        }

        $this->components->info('Added default [windowed.sdl3] display configuration.');

        return self::SUCCESS;
    }

    protected function hasWindowedEntry(): bool
    {
        if (! isset($this->scrapyard_io) || ! $this->scrapyard_io->bound('config')) {
            return false;
        }

        $windowed = $this->scrapyard_io['config']->get('displays.windowed', []);

        return is_array($windowed) && array_key_exists('sdl3', $windowed);
    }

    protected function writeWindowedEntry(Filesystem $files, string $path): bool
    {
        $contents = $files->get($path);
        $entry = $this->defaultEntrySnippet();

        if ($this->option('force') && preg_match("/['\"]sdl3['\"]\\s*=>\\s*\\[/", $contents) === 1) {
            $replaced = preg_replace(
                "/['\"]sdl3['\"]\\s*=>\\s*\\[(?:[^\\[\\]]*(?:\\[[^\\[\\]]*\\][^\\[\\]]*)*)*\\],?/",
                trim($entry),
                $contents,
                1,
            );

            if (is_null($replaced) || $replaced === $contents) {
                return false;
            }

            $files->put($path, $replaced);

            return true;
        }

        if (preg_match("/['\"]windowed['\"]\\s*=>\\s*\\[/", $contents) === 1) {
            $updated = preg_replace(
                "/(['\"]windowed['\"]\\s*=>\\s*\\[)/",
                "$1\n".$entry,
                $contents,
                1,
            );

            if (is_null($updated) || $updated === $contents) {
                return false;
            }

            $files->put($path, $updated);

            return true;
        }

        $windowedBlock = <<<PHP
    'windowed' => [
{$entry}    ],
PHP;

        $updated = preg_replace('/return\\s*\\[/', "return [\n".$windowedBlock, $contents, 1);

        if (is_null($updated) || $updated === $contents) {
            return false;
        }

        $files->put($path, $updated);

        return true;
    }

    protected function defaultEntrySnippet(): string
    {
        return <<<'PHP'
        'sdl3' => [
            'width' => 1024,
            'height' => 768,
            'scale_factor' => 1,
            'title' => env('APP_NAME'),
            'boot_now' => true,
        ],
PHP;
    }
}
