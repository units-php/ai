<?php

declare(strict_types=1);

/**
 * This package is not `composer install`ed inside this repository yet, so the
 * suite wires its own autoloading:
 *
 *   Units\Ai\Tests\  -> tests/
 *   Units\Ai\        -> src/
 *   Patterns\        -> the sibling packages in this working tree
 *
 * The bridge registers with `prepend: true` on purpose. Composer's autoloader
 * runs first otherwise, and a vendored copy of a `Patterns\` class - this
 * repository has one, `patterns/value-object` and `patterns/result` are both
 * installed under `www/vendor` - would answer before the working tree does and
 * the suite would validate code that is not on disk here.
 *
 * The application's vendor is borrowed last, for the things no package here
 * ships: the PSR-18 and PSR-7 interfaces, and Guzzle, which the fake client and
 * the default transport are typed against.
 */

$appVendor = dirname(__DIR__, 3) . '/www/vendor/autoload.php';

if (is_file($appVendor)) {
    require $appVendor;
}

$patterns = [
    'unit'          => dirname(__DIR__, 2) . '/unit/src',
    'value-object'  => dirname(__DIR__, 2) . '/value-object/src',
    'event-channel' => dirname(__DIR__, 2) . '/event-channel/src',
];

$roots = [
    'Units\\Ai\\Tests\\' => __DIR__,
    'Units\\Ai\\'        => dirname(__DIR__) . '/src',
    'Patterns\\'         => array_values($patterns),
];

// Every bridged directory is a dependency this package must declare. If the
// bridge quietly supplies something composer.json does not require, the suite
// passes here and the package fatals for whoever installs it from Packagist -
// which is exactly what happened with patterns/value-object, hidden until the
// package was installed into a clean project. So the harness refuses to lie.
$manifest = json_decode((string) file_get_contents(dirname(__DIR__) . '/composer.json'), true);
$required = array_keys($manifest['require'] ?? []);

foreach ($patterns as $package => $dir) {
    if (!in_array('patterns/' . $package, $required, true)) {
        fwrite(STDERR, sprintf(
            "bootstrap: Patterns\\ is bridged from %s, but composer.json does not require patterns/%s%s",
            $package,
            $package,
            PHP_EOL,
        ));

        exit(1);
    }
}

spl_autoload_register(static function (string $class) use ($roots): void {
    foreach ($roots as $prefix => $dirs) {
        if (!str_starts_with($class, $prefix)) {
            continue;
        }

        $rest = str_replace('\\', '/', substr($class, strlen($prefix)));

        foreach ((array) $dirs as $dir) {
            $file = $dir . '/' . $rest . '.php';

            if (is_file($file)) {
                require $file;

                return;
            }
        }
    }
}, true, true);
