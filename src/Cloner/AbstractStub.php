<?php

namespace Base\Composer\Cloner;

use Composer\IO\IOInterface;

abstract class AbstractStub implements StubInterface
{
    /** omnibase's core, by its name, then by its former one. */
    public const CORE_PACKAGES = ['omniscient/omnibase', 'glitchr/omnibase'];

    public IOInterface $io;

    public function __construct(IOInterface $io)
    {
        $this->io = $io;
    }

    public function print(string $msg)
    {
        $inputNamespace = $this->getStubInputNamespace();
        $outputNamespace = $this->getStubOutputNamespace();

        $displayLimit = 18;
        $shortInputNamespace = strlen($inputNamespace) > $displayLimit-3 ? substr($inputNamespace, 0, $displayLimit-5) . '...' : $inputNamespace;
        $shortOutputNamespace = strlen($outputNamespace) > $displayLimit-3 ? substr($outputNamespace, 0, $displayLimit-5) . '...' : $outputNamespace;
        
        $prefix = sprintf(
            "    *Aliasing  \033[0;35m%-".$displayLimit."s\033[0m to\033[0;35m %-".$displayLimit."s\033[0m.. %s",
            "\"".$shortInputNamespace."\"",
            "\"".$shortOutputNamespace."\"",
            $msg
        );

        $this->io->write($prefix);
    }

    public function getAppPath(): string
    {
        return dirname(__FILE__, 6) . "/src/".$this->getStubName();
    }

    public function getStubName(): string
    {
        return preg_replace('/Stub$/', '', basename(str_replace('\\', '/', get_class($this))));
    }

    public function getStubPath(): string
    {
        return dirname(__FILE__, 3) . "/stubs/".$this->getStubName();
    }

    public function getStubInputNamespace(): string
    {
        return "Base\\".str_replace("/", "\\", $this->getStubName())."\\";
    }

    public function getStubOutputNamespace(): string
    {
        return "App\\".str_replace("/", "\\", $this->getStubName())."\\";
    }

    public function getClassName(string $classFile): string
    {
        return basename($classFile, '.php');
    }

    /**
     * The core's classes the stubs extend: vendor/omniscient/omnibase (its name
     * since 2026-10-18), else vendor/glitchr/omnibase (its former name, which
     * it replaces), wherever this plugin is installed beside them.
     */
    public function getClassPath(): string
    {
        $vendorDir = dirname(__FILE__, 5);
        foreach (self::CORE_PACKAGES as $package) {
            $path = $vendorDir . "/" . $package . "/src/" . $this->getStubName();
            if (is_dir($vendorDir . "/" . $package)) {
                return $path;
            }
        }

        return $vendorDir . "/" . self::CORE_PACKAGES[0] . "/src/" . $this->getStubName();
    }

    public function getClassFiles(): array
    {
        $locationPath = $this->getClassPath();
        if (!is_dir($locationPath)) return [];

        $classes = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($locationPath, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {

                $fqcn = $this->getNamespace($file)."\\".$this->getClassName($file);
                $relativePath = str_replace($locationPath . DIRECTORY_SEPARATOR, '', $file);
                $classes[$fqcn] = $relativePath;                
            }
        }

        return $classes;
    }

    public function getNamespace(string $classFile): string
    {
        if (!file_exists($classFile)) {
            return '';
        }
    
        $lines = file($classFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            if (preg_match('/^namespace\s+([^;]+);/', $line, $matches)) {
                return trim($matches[1]);
            }
        }
    
        return '';
    }

    public function isTrait(string $classFile): bool
    {
        if (!file_exists($classFile)) {
            return false;
        }

        $lines = file($classFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            if (preg_match('/^\s*trait\s+\w+/', $line)) {
                return true;
            }
        }

        return false;
    }

    public function isInterface(string $classFile): bool
    {
        if (!file_exists($classFile)) {
            return false;
        }

        $lines = file($classFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            if (preg_match('/^\s*interface\s+\w+/', $line)) {
                return true;
            }
        }

        return false;
    }

    public function isAbstract(string $classFile): bool
    {
        if (!file_exists($classFile)) {
            return false;
        }

        $lines = file($classFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            if (preg_match('/^\s*abstract\s+class\s+\w+/', $line)) {
                return true;
            }
        }

        return false;
    }

    public function isFinal(string $classFile): bool
    {
        if (!file_exists($classFile)) {
            return false;
        }

        $lines = file($classFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            if (preg_match('/^\s*final\s+class\s+\w+/', $line)) {
                return true;
            }
        }

        return false;
    }

    public function isClass(string $classFile): bool
    {
        if (!file_exists($classFile)) {
            return false;
        }

        $lines = file($classFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            if (preg_match('/^\s*class\s+\w+/', $line)) {
                return true;
            }
        }

        return false;
    }

    public function generate()
    {
        $nCounts = 0;
        foreach($this->getClassFiles() as $className => $classFile) {
            
            $classPath = $this->getClassPath()."/".$classFile;
            $appFile = $this->getAppPath()."/".$classFile;

            $stubBasename = basename(str_replace('\\', '/', $className));
            $stubNamespace = str_replace("/", "\\", preg_replace("/^Base\//", "App/", dirname(str_replace('\\', '/', $className))));
            $stubFile = $this->getStubPath()."/".$classFile;
            $stubPath = dirname($stubFile);

            if(file_exists($stubFile)) unlink($stubFile);
            if(file_exists($appFile)) continue;

            if(!$this->isClass($classPath)) continue;
            if ($this->isTrait($classPath)) continue;
            if ($this->isInterface($classPath)) continue;
            if ($this->isFinal($classPath)) continue;
            if ($this->isAbstract($classPath)) continue;

            $stubCode = "<?php\n\nnamespace " . $stubNamespace . ";\n\n";
            $stubCode .= "/**\n";
            $stubCode .= " * This file is auto-generated by glitch/base-plugin.\n";
            $stubCode .= " * Do not edit this file manually as changes will be overwritten at composer dump-autoload stage.\n";
            $stubCode .= " */\n\n";
            $stubCode .= "if (!class_exists('\\".$stubNamespace."\\".$stubBasename."')) {\n";
            $stubCode .= "    class " . $stubBasename . " extends \\" . $className . " {}\n";
            $stubCode .= "}\n";

            @mkdir($stubPath, 0777, true);
            file_put_contents($stubFile, $stubCode);
            $nCounts++;
        }

        if($nCounts > 0) {
            $this->print($nCounts." stub(s) succesfully generated in ".$this->getStubPath());
        }
    }
}