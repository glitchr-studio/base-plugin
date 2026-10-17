<?php

namespace Base\Composer\Package;
use Base\Composer\Package\HookInterface;

use Composer\Factory;
use Composer\Installer\PackageEvent;
use Composer\IO\IOInterface;
use Composer\Semver\VersionParser;
use Composer\DependencyResolver\Operation\UpdateOperation;

abstract class AbstractHook implements HookInterface
{
    public IOInterface $io;
    public function __construct(IOInterface $io)
    {
        $this->io = $io;
    }

    public function print(string $msg)
    {
        $packageName = $this->getPackageName();
        $author = $this->getAuthor();

        $displayLimit = 25;
        $shortPackageName = strlen($packageName) > $displayLimit - 3 ? substr($packageName, 0, $displayLimit - 5) . '...' : $packageName;
        $shortAuthor = strlen($author) > $displayLimit - 3 ? substr($author, 0, $displayLimit - 5) . '...' : $author;

        $prefix = sprintf(
            "    * Patching \033[0;35m%-".$displayLimit."s\033[0m via \033[0;35m%-".$displayLimit."s\033[0m.. %s",
            $shortPackageName,
            $shortAuthor,
            $msg
        );

        $this->io->write($prefix);
    }

    /**
     * @param PackageEvent $event
     * @return mixed
     */
    public function onPackageChange(PackageEvent $event)
    {
        $methodName = explode("::", __METHOD__);
        $methodName = last($methodName);
        throw new \UnexpectedValueException('Please override ' . static::class . '::' . $methodName);
    }

    /**
     * @param PackageEvent $event
     * @return mixed
     */
    public function onPackageInstall(PackageEvent $event)
    {
        $this->onPackageChange($event);
    }

    /**
     * @param PackageEvent $event
     * @return mixed
     */
    public function onPackageUpdate(PackageEvent $event)
    {
        $this->onPackageChange($event);
    }

    /**
     * @param PackageEvent $event
     * @return mixed
     */
    public function onPackageRemove(PackageEvent $event)
    {
    }
    
    public function checkValidityVersion(PackageEvent $event): bool
    {
        $versionParser = new VersionParser();
        $currentVersion = $this->getPackageVersion($event);
        $constraint = $this->getPackageRequirements($event);
        try {

            $constraintObject = $versionParser->parseConstraints($constraint);
            return $constraintObject->matches($versionParser->parseConstraints($currentVersion));

        } catch (\Exception $e) {
            
            return false;
        }

        return true;
    }

    public function getPackageVersion(PackageEvent $event): string
    {
        $operation = $event->getOperation();

        if ($operation instanceof UpdateOperation) {
            return $operation->getTargetPackage()->getVersion();
        }

        return $operation->getPackage()->getVersion();
    }

    protected function getAuthor(): string
    {
        return basename(dirname(__FILE__, 4))."/".basename(dirname(__FILE__, 3));
    }
    
    protected function getProjectDir(): string
    {
        return dirname(realpath(Factory::getComposerFile()));
    }

    /**
     * The names the patched package is installed under: its own, and the
     * former ones a renamed package replaces (a hook says them by overriding
     * this). The hook runs for an operation on any of them.
     *
     * @return string[]
     */
    public function getPackageNames(): array
    {
        return [$this->getPackageName()];
    }

    /** The patched package's directory: the first of its names that is installed. */
    protected function getBundleDir(): string
    {
        foreach ($this->getPackageNames() as $packageName) {
            if (is_dir($this->getVendorDir() . '/' . $packageName)) {
                return $this->getVendorDir() . '/' . $packageName;
            }
        }

        return $this->getVendorDir() . '/' . $this->getPackageName();
    }

    protected function getVendorDir(): string
    {
        $composerFile = Factory::getComposerFile();
        $composerJson = json_decode(file_get_contents($composerFile), associative: true, flags: JSON_THROW_ON_ERROR);

        return $composerJson['config']['vendor-dir'] ?? $this->getProjectDir() . '/vendor';
    }

    protected function getBundlePHPFiles(): iterable
    {
        $iterator = new \RecursiveDirectoryIterator($this->getBundleDir(), \FilesystemIterator::SKIP_DOTS);
        foreach (new \RecursiveIteratorIterator($iterator) as $filePath) {
            if (is_dir($filePath) || !str_ends_with($filePath, '.php')) {
                continue;
            }

            yield $filePath;
        }
    }
}
