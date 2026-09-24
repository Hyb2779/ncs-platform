<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class LangCheck extends Command
{
    protected $signature = 'lang:check';

    protected $description = 'Fail when translation keys differ or values are empty across tr, en, de, and ar';

    private const LOCALES = ['tr', 'en', 'de', 'ar'];

    public function handle(): int
    {
        $catalogs = [];

        foreach (self::LOCALES as $locale) {
            $directory = lang_path($locale);

            if (! is_dir($directory)) {
                $this->error("Missing locale directory: lang/{$locale}");

                return self::FAILURE;
            }

            $catalogs[$locale] = $this->catalog($directory);
        }

        $problems = [];
        $union = [];

        foreach ($catalogs as $keys) {
            $union = array_merge($union, $keys);
        }

        ksort($union);

        foreach (array_keys($union) as $key) {
            foreach (self::LOCALES as $locale) {
                if (! array_key_exists($key, $catalogs[$locale])) {
                    $problems[] = "{$locale}: missing {$key}";

                    continue;
                }

                if (trim((string) $catalogs[$locale][$key]) === '') {
                    $problems[] = "{$locale}: empty {$key}";
                }
            }
        }

        if ($problems !== []) {
            foreach ($problems as $problem) {
                $this->line($problem);
            }

            $this->error(count($problems).' translation problem(s).');

            return self::FAILURE;
        }

        $this->info('Language catalogs match ('.count($union).' keys).');

        return self::SUCCESS;
    }

    /**
     * @return array<string, string>
     */
    private function catalog(string $directory): array
    {
        $catalog = [];

        foreach (File::allFiles($directory) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $relative = str_replace('\\', '/', $file->getRelativePathname());
            $group = substr($relative, 0, -4);
            $messages = include $file->getPathname();

            if (! is_array($messages)) {
                continue;
            }

            foreach ($this->flatten($messages) as $key => $value) {
                $catalog[$group.'.'.$key] = is_scalar($value) ? (string) $value : '';
            }
        }

        return $catalog;
    }

    /**
     * @param  array<mixed>  $messages
     * @return array<string, mixed>
     */
    private function flatten(array $messages, string $prefix = ''): array
    {
        $flat = [];

        foreach ($messages as $key => $value) {
            $full = $prefix === '' ? (string) $key : $prefix.'.'.$key;

            if (is_array($value)) {
                $flat += $this->flatten($value, $full);
            } else {
                $flat[$full] = $value;
            }
        }

        return $flat;
    }
}
