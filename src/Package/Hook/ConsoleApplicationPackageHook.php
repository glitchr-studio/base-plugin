<?php

namespace Base\Composer\Package\Hook;

use Base\Composer\Package\AbstractHook;
use Composer\Installer\PackageEvent;
use Base\Composer\CodeModifier;

final class ConsoleApplicationPackageHook extends AbstractHook
{
    public function getPackageName(): string
    {
        return 'omniscient/omnibase';
    }

    /** omnibase's core by its name, and by its former one (which it replaces). */
    public function getPackageNames(): array
    {
        return ['omniscient/omnibase', 'glitchr/omnibase'];
    }

    public function getPackageRequirements(): string
    {
        return "*";
    }

    public function onPackageRemove(PackageEvent $event)
    {
        $phpFiles = iterator_to_array($this->getBundlePHPFiles());
        foreach ($phpFiles as $phpFile) {
            $codeModifier = new CodeModifier($phpFile, $this->getAuthor());
            $codeModifier->restore();
        }
    }

    public function onPackageChange(PackageEvent $event)
    {
        $this->print('Modify console script in `./bin/console`.');
        // strict: false — consuming projects may have already hand-applied this
        // swap to bin/console outside this tool's tracking (as this one has), so
        // a missing search anchor means "already done", not "upstream changed".
        $codeModifier = new CodeModifier($this->getProjectDir() . '/bin/console', $this->getAuthor(), strict: false);
        $codeModifier->replace("base-application", "use Symfony\\Bundle\\FrameworkBundle\\Console\\Application", "use Base\\Console\\Application");
    }
}
