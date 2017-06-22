<?php

namespace Phpactor\Application\ClassMover;

use DTL\ClassFileConverter\CompositeTransformer;
use DTL\ClassFileConverter\FilePath as ConverterFilePath;
use DTL\ClassMover\ClassMover as ClassMoverFacade;
use DTL\ClassMover\Domain\FullyQualifiedName;
use DTL\Filesystem\Domain\FilePath;
use DTL\Filesystem\Domain\Filesystem;


class ClassMover
{
    private $fileClassConverter;
    private $classMover;
    private $filesystem;

    // rename compositetransformer => classToFileConverter
    public function __construct(
        CompositeTransformer $fileClassConverter,
        ClassMoverFacade $classMover,
        Filesystem $filesystem
    ) {
        $this->fileClassConverter = $fileClassConverter;
        $this->filesystem = $filesystem;
        $this->classMover = $classMover;
    }

    public function move(MoveLogger $logger, string $srcPath, string $destPath)
    {
        $srcPath = $this->filesystem->createPath($srcPath);
        $destPath = $this->filesystem->createPath($destPath);

        if (!file_exists(dirname($destPath->absolutePath()))) {
            mkdir(dirname($destPath->absolutePath()), 0777, true);
        }

        $files = [[$srcPath, $destPath]];

        if (is_dir($srcPath)) {
            $files = $this->directoryMap($srcPath, $destPath);
        }

        $this->replaceThoseReferences($files, $logger);
        $logger->moving($srcPath, $destPath);
        $this->filesystem->move($srcPath, $destPath);
    }

    private function directoryMap(FilePath $srcPath, FilePath $destPath)
    {
        $files = [];
        foreach ($this->filesystem->fileList()->within($srcPath)->phpFiles() as $file) {
            $suffix = substr($file->absolutePath(), strlen($srcPath->absolutePath()));
            $files[] = [$file->absolutePath(), $this->filesystem->createPath($destPath.$suffix)];
        }

        return $files;
    }

    private function replaceThoseReferences(array $files, MoveLogger $logger)
    {
        foreach ($files as $paths) {
            list($srcPath, $destPath) = $paths;

            $srcPath = $this->filesystem->createPath($srcPath);
            $destPath = $this->filesystem->createPath($destPath);

            $srcClassName = $this->fileClassConverter->fileToClass(ConverterFilePath::fromString($srcPath->absolutePath()));
            $destClassName = $this->fileClassConverter->fileToClass(ConverterFilePath::fromString($destPath->absolutePath()));

            $this->replaceReferences($logger, $srcClassName->best()->__toString(), $destClassName->best()->__toString());
        }
    }

    private function replaceReferences(MoveLogger $logger, string $srcName, string $destName)
    {
        $targetName = FullyQualifiedName::fromString($srcName);
        $replacementName = FullyQualifiedName::fromString($destName);

        foreach ($this->filesystem->fileList()->phpFiles() as $filePath) {
            $references = $this->classMover->findReferences($this->filesystem->getContents($filePath), $targetName);
            $logger->replacing($filePath, $references, $replacementName);

            $source = $this->classMover->replaceReferences(
                $references,
                $replacementName
            );

            $this->filesystem->writeContents($filePath, (string) $source);
        }
    }
}