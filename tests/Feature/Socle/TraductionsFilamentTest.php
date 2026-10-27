<?php

use Illuminate\Support\Arr;

/**
 * L'interface est 100 % en français : toute clé anglaise de Filament doit avoir sa
 * traduction française (paquet ou surcharge dans lang/vendor).
 */
it('traduit en français tous les textes de Filament', function () {
    $missing = [];

    foreach (glob(base_path('vendor/filament/*/resources/lang/en'), GLOB_ONLYDIR) as $englishDirectory) {
        $package = basename(dirname($englishDirectory, 3));
        $namespace = match ($package) {
            'filament' => 'filament-panels',
            'support' => 'filament',
            default => 'filament-'.$package,
        };

        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($englishDirectory, FilesystemIterator::SKIP_DOTS));

        foreach ($files as $file) {
            $group = str_replace([DIRECTORY_SEPARATOR, '.php'], ['/', ''], substr($file->getPathname(), strlen($englishDirectory) + 1));

            foreach (array_keys(Arr::dot(include $file->getPathname())) as $key) {
                $translationKey = "{$namespace}::{$group}.{$key}";

                if (__($translationKey, [], 'fr') === $translationKey) {
                    $missing[] = $translationKey;
                }
            }
        }
    }

    expect($missing)->toBe([]);
});
