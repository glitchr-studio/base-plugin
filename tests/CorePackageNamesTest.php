<?php

declare(strict_types=1);

namespace Base\Composer\Tests;

use Base\Composer\Package\Hook\ConsoleApplicationPackageHook;
use Composer\IO\NullIO;
use PHPUnit\Framework\TestCase;

/**
 * omnibase's core is omniscient/omnibase since 2026-10-18 (formerly
 * glitchr/omnibase, which it replaces): Composer installs it in
 * vendor/omniscient/omnibase, an application not yet updated still has
 * vendor/glitchr/omnibase. The console hook runs for either name, and the
 * stubs extend the core's classes wherever it is.
 */
class CorePackageNamesTest extends TestCase
{
    private ?string $root = null;

    protected function tearDown(): void
    {
        if (null !== $this->root) {
            exec('rm -rf ' . escapeshellarg($this->root));
        }
    }

    public function testTheConsoleHookAnswersToBothNames(): void
    {
        $hook = new ConsoleApplicationPackageHook(new NullIO());

        self::assertSame('omniscient/omnibase', $hook->getPackageName());
        self::assertSame(['omniscient/omnibase', 'glitchr/omnibase'], $hook->getPackageNames());
    }

    /** @return iterable<string, array{string[], string}> */
    public static function layouts(): iterable
    {
        yield 'renamed' => [['omniscient/omnibase'], 'omniscient/omnibase'];
        yield 'not yet' => [['glitchr/omnibase'], 'glitchr/omnibase'];
        yield 'both' => [['omniscient/omnibase', 'glitchr/omnibase'], 'omniscient/omnibase'];
    }

    /**
     * @dataProvider layouts
     *
     * @param string[] $installed
     */
    public function testTheStubsFindTheCoreWhereverItIs(array $installed, string $expected): void
    {
        // The process below needs Composer's classes (Composer\IO\NullIO), which the plugin's own
        // harness installs (composer/composer, require-dev) and an application does not: run in an
        // application's vendor suite (lesringostars runs vendor/glitchr/base-plugin/tests), there is
        // no vendor/autoload.php beside the plugin to require.
        if (!is_file(dirname(__DIR__) . '/vendor/autoload.php')) {
            self::markTestSkipped('Needs the plugin\'s own vendor/autoload.php (composer install in the plugin): absent in an application\'s vendor.');
        }

        // The plugin copied into a project's vendor/glitchr/base-plugin, beside the core.
        $this->root = sys_get_temp_dir() . '/base-plugin-' . bin2hex(random_bytes(4));
        $vendor = $this->root . '/vendor';
        mkdir($vendor . '/glitchr/base-plugin', 0777, true);
        exec('cp -R ' . escapeshellarg(dirname(__DIR__) . '/src') . ' ' . escapeshellarg($vendor . '/glitchr/base-plugin/src'));
        foreach ($installed as $package) {
            mkdir($vendor . '/' . $package . '/src/Entity', 0777, true);
        }

        $src = $vendor . '/glitchr/base-plugin/src/Cloner';
        $code = 'require ' . var_export(dirname(__DIR__) . '/vendor/autoload.php', true) . ';'
            . 'require ' . var_export($src . '/StubInterface.php', true) . ';'
            . 'require ' . var_export($src . '/AbstractStub.php', true) . ';'
            . 'require ' . var_export($src . '/Stub/EntityStub.php', true) . ';'
            . 'echo (new Base\Composer\Cloner\Stub\EntityStub(new Composer\IO\NullIO()))->getClassPath();';
        $path = exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($code), $output, $status);

        self::assertSame(0, $status, implode("\n", $output));
        self::assertSame($vendor . '/' . $expected . '/src/Entity', $path);
    }
}
