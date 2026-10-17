<?php

declare(strict_types=1);

namespace Base\Composer\Tests;

use Base\Composer\Package\Hook\DoctrinePackageHook;
use Composer\Installer\PackageEvent;
use Composer\IO\NullIO;
use PHPUnit\Framework\TestCase;

/**
 * doctrine/orm is patched only for an omnibase core that needs it: one that maps on doctrine/orm as published
 * says so (extra.base-plugin.doctrine/orm: false, omniscient/omnibase since step C6 of the plan "omnibase
 * agnostique"); an older core, by either name, or none, keeps the patches.
 */
class DoctrineHookTest extends TestCase
{
    private ?string $root = null;
    private string|false $composer = false;

    protected function setUp(): void
    {
        $this->composer = getenv('COMPOSER');
    }

    protected function tearDown(): void
    {
        false === $this->composer ? putenv('COMPOSER') : putenv('COMPOSER=' . $this->composer);
        if (null !== $this->root) {
            exec('rm -rf ' . escapeshellarg($this->root));
        }
    }

    /** @return iterable<string, array{array<string, ?array>, bool}> */
    public static function cores(): iterable
    {
        yield 'a core that maps without the patch' => [['omniscient/omnibase' => ['extra' => ['base-plugin' => ['doctrine/orm' => false]]]], false];
        yield 'a core of before' => [['omniscient/omnibase' => []], true];
        yield 'the core by its former name, of before' => [['glitchr/omnibase' => ['extra' => ['symfony' => []]]], true];
        yield 'the core by its former name, without the patch' => [['glitchr/omnibase' => ['extra' => ['base-plugin' => ['doctrine/orm' => false]]]], false];
        yield 'no core' => [[], true];
    }

    /**
     * @dataProvider cores
     *
     * @param array<string, array> $cores
     */
    public function testTheCoreSaysWhetherDoctrineIsPatched(array $cores, bool $needed): void
    {
        self::assertSame($needed, DoctrinePackageHook::isNeededIn($this->project($cores)));
    }

    /**
     * The hook leaves doctrine/orm's ClassMetadataFactory as published for a core that says so, and patches
     * it (private made protected) for one of before.
     *
     * @dataProvider cores
     *
     * @param array<string, array> $cores
     */
    public function testTheFactoryIsLeftOrPatched(array $cores, bool $needed): void
    {
        $vendor = $this->project($cores);
        $published = "<?php\n\nclass ClassMetadataFactory\n{\n    private function getShortName(string \$className): string\n    {\n        return \$className;\n    }\n}\n";
        foreach (['src/Mapping/ClassMetadataFactory.php', 'src/Query/SqlWalker.php', 'src/Internal/Hydration/ObjectHydrator.php'] as $file) {
            @mkdir(dirname($vendor . '/doctrine/orm/' . $file), 0777, true);
            file_put_contents($vendor . '/doctrine/orm/' . $file, $published);
        }

        (new DoctrinePackageHook(new NullIO()))->onPackageChange($this->createMock(PackageEvent::class));

        $factory = (string) file_get_contents($vendor . '/doctrine/orm/src/Mapping/ClassMetadataFactory.php');
        if ($needed) {
            self::assertStringContainsString('protected function getShortName', $factory);
        } else {
            self::assertSame($published, $factory);
        }
    }

    /** @param array<string, array> $cores the core packages installed, by name, with their composer.json */
    private function project(array $cores): string
    {
        $this->root = sys_get_temp_dir() . '/base-plugin-doctrine-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/vendor', 0777, true);
        file_put_contents($this->root . '/composer.json', '{}');
        putenv('COMPOSER=' . $this->root . '/composer.json');

        foreach ($cores as $package => $composer) {
            mkdir($this->root . '/vendor/' . $package, 0777, true);
            file_put_contents($this->root . '/vendor/' . $package . '/composer.json', json_encode(['name' => $package] + $composer));
        }

        return $this->root . '/vendor';
    }
}
