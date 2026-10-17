<?php

namespace Base\Composer;

use Base\Composer\Cloner\StubInterface;
use Base\Composer\Exception\CodeModifierException;
use Base\Composer\Package\AbstractHook;
use Base\Composer\Package\HookInterface;

use Composer\ClassMapGenerator\ClassMapGenerator;
use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\InstalledVersions;
use Composer\Installer\PackageEvent;
use Composer\Installer\PackageEvents;
use Composer\IO\IOInterface;
use Composer\Plugin\PluginInterface;
use Composer\Script\Event as ScriptEvent;
use Composer\Script\ScriptEvents;

final class Plugin implements PluginInterface, EventSubscriberInterface
{
    /**
     * @return string
     */
    public static function getPackageName()
    {
        return basename(dirname(__FILE__, 3))."/".basename(dirname(__FILE__, 2));
    }
    
    /**
     * @return string[]
     */
    public static function getSubscribedEvents()
    {
        return [
            PackageEvents::POST_PACKAGE_INSTALL  => 'onPackageInstall',
            PackageEvents::POST_PACKAGE_UPDATE   => 'onPackageUpdate',
            PackageEvents::PRE_PACKAGE_UNINSTALL => 'onPackageRemove',
            ScriptEvents::PRE_AUTOLOAD_DUMP      => "onPreAutoloadDump"
        ];
    }

    protected IOInterface $io;
    protected ?Composer $composer = null;
    public function activate(Composer $composer, IOInterface $io)
    {
        $this->io = $io;
        // Kept so hooks can be resolved against the PROJECT's vendor-dir. See
        // isTargetNotExtracted(): Composer\InstalledVersions answers for
        // composer's OWN dependency set, not the project being installed.
        $this->composer = $composer;
    }

    /**
     * Runs a hook and, if a strict patch could not be applied (upstream
     * source drifted), surfaces it loudly and re-throws so the composer
     * command fails — a strict patch must never silently no-op.
     */
    private function runHook(callable $run): void
    {
        try {
            $run();
        } catch (CodeModifierException $e) {
            $this->io->writeError('<error>[base-plugin] a required source patch could not be applied:</error>');
            $this->io->writeError('<error>' . $e->getMessage() . '</error>');
            throw $e;
        }
    }

    public function deactivate(Composer $composer, IOInterface $io)
    {
    }

    public function uninstall(Composer $composer, IOInterface $io)
    {
        // @todo: restore .bak before removing plugins..
    }

    private function getPluginName(): string
    {
        $composerFile = dirname(__FILE__) . '/../composer.json';
        if (!file_exists($composerFile)) {
            throw new \RuntimeException('Composer file not found: ' . $composerFile);
        }
    
        $composerJson = json_decode(file_get_contents($composerFile), true, 512, JSON_THROW_ON_ERROR);
    
        if (!isset($composerJson['name'])) {
            throw new \UnexpectedValueException('No plugin name found in ' . __CLASS__ . '.');
        }
    
        return $composerJson['name'];
    }

    /**
     * True when a hook's target package has not been extracted to disk yet.
     *
     * When the triggering package is base-plugin ITSELF the loops below replay
     * every hook, so that installing or updating this plugin re-applies its
     * patches across an already populated tree. On a from-scratch install that
     * same replay fires far too early: composer installs plugins BEFORE the
     * packages they patch, so the targets are not on disk. InstalledVersions
     * already lists them (the lock is written before files land), so the
     * isInstalled() guard passes; CodeModifier then reads a file that does not
     * exist, file_get_contents returns false, and a strict patch reports its
     * anchor as "not found" and aborts the entire install. That is why a clean
     * `composer install` of this project died on symfony/process while the
     * running sites, whose vendor trees were already populated, installed fine.
     *
     * Skipping costs nothing: each package's own POST_PACKAGE_INSTALL fires
     * once it really is extracted, and the hook patches it then.
     */
    private function isTargetNotExtracted($class, $packageName): bool
    {
        // Only the self-install replay is affected; a hook triggered by its own
        // package is by definition already on disk.
        if ($this->getPackageName() !== $packageName) return false;
        if (in_array($packageName, self::hookPackageNames($class), true)) return false;

        // Resolve against the PROJECT's vendor-dir, never Composer\InstalledVersions.
        // Inside a plugin that class answers for COMPOSER'S OWN dependency set,
        // and composer itself depends on symfony/process, symfony/console and
        // friends - so getInstallPath('symfony/process') happily returns a real,
        // existing directory inside composer's own tree while the project's copy
        // has not been extracted at all. That false positive is exactly why the
        // pre-existing isInstalled() guard above never prevented anything.
        $vendorDir = $this->composer?->getConfig()?->get('vendor-dir');
        if (!$vendorDir) return false;

        // Require a real, non-empty directory: composer creates the target
        // directory before it finishes extracting into it, so is_dir() alone
        // would go true a moment too early. Any of the hook's names will do.
        foreach (self::hookPackageNames($class) as $name) {
            $path = rtrim($vendorDir, '/') . '/' . $name;
            $entries = is_dir($path) ? @scandir($path) : false;
            if ($entries !== false && count($entries) > 2) return false;
        }

        return true;
    }

    /** @return string[] the names a hook patches its package under (HookInterface has getPackageName() alone). */
    private static function hookPackageNames(object $class): array
    {
        return method_exists($class, 'getPackageNames') ? $class->getPackageNames() : [$class->getPackageName()];
    }

    private static function isHookInstalled(object $class): bool
    {
        foreach (self::hookPackageNames($class) as $name) {
            if (InstalledVersions::isInstalled($name)) {
                return true;
            }
        }

        return false;
    }

    private array $installedPackageNames = [];
    public function onPackageInstall(PackageEvent $event)
    {
        $operation = $event->getOperation();
        $packageName = $operation->getPackage()?->getName();
        if (in_array($packageName, $this->installedPackageNames)) {
            return;
        }

        $this->installedPackageNames[] = $packageName;

        foreach (ClassMapGenerator::createMap(__DIR__) as $className => $_) {

            if (!in_array(HookInterface::class, class_implements($className))) {
                continue;
            }

            try {
                $class = new $className($this->io);
            } catch (\Error $e) {
                continue;
            }
 
            if (!self::isHookInstalled($class)) {
                continue;
            }

            if (!in_array($packageName, self::hookPackageNames($class), true) && $this->getPackageName() != $packageName) {
                continue;
            }
            if ($this->isTargetNotExtracted($class, $packageName)) {
                continue;
            }
            if (!$class->checkValidityVersion($event)) {
                continue;
            }

            $this->runHook(fn() => $class->onPackageInstall($event));
        }
    }

    private array $updatedPackageNames = [];

    public function onPackageUpdate(PackageEvent $event)
    {
        $operation = $event->getOperation();
        $packageName = $operation->getInitialPackage()?->getName();
        if (in_array($packageName, $this->updatedPackageNames)) {
            return;
        }

        $this->updatedPackageNames[] = $packageName;
        foreach (ClassMapGenerator::createMap(__DIR__) as $className => $_) {

            if (!in_array(HookInterface::class, class_implements($className))) {
                continue;
            }

            try {
                $class = new $className($this->io);
            } catch (\Error $e) {
                continue;
            }

            if (!self::isHookInstalled($class)) {
                continue;
            }

            if (!in_array($packageName, self::hookPackageNames($class), true) && $this->getPackageName() != $packageName) {
                continue;
            }

            if ($this->isTargetNotExtracted($class, $packageName)) {
                continue;
            }

            if (!$class->checkValidityVersion($event)) {
                continue;
            }

            $this->runHook(fn() => $class->onPackageUpdate($event));
        }
    }

    private array $removedPackageNames = [];

    public function onPackageRemove(PackageEvent $event)
    {
        $operation = $event->getOperation();
        $packageName = $operation->getPackage()?->getName();
        if (in_array($packageName, $this->removedPackageNames)) {
            return;
        }

        $this->removedPackageNames[] = $packageName;

        foreach (ClassMapGenerator::createMap(__DIR__) as $className => $_) {

            if (!in_array(HookInterface::class, class_implements($className))) {
                continue;
            }

            try {
                $class = new $className($this->io);
            } catch (\Error $e) {
                continue;
            }

            if (!self::isHookInstalled($class)) {
                continue;
            }
            if (!in_array($packageName, self::hookPackageNames($class), true) && $this->getPackageName() != $packageName) {
                continue;
            }
            if (!$class->checkValidityVersion($event)) {
                continue;
            }

            $this->runHook(fn() => $class->onPackageRemove($event));
        }
    }

    public function onPreAutoloadDump(ScriptEvent $event)
    {
        $io = $event->getIO();
        foreach (ClassMapGenerator::createMap(__DIR__) as $className => $_) {

            if (!in_array(StubInterface::class, class_implements($className))) {
                continue;
            }

            try {
                $class = new $className($io);
            } catch (\Error $e) {
                continue;
            }

            $class->generate();
        }

        $io->write("\033[32mStubbing generation finished.\033[0m");
    }
}
