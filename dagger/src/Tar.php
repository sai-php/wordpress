<?php

declare(strict_types=1);

namespace DaggerModule;

use Dagger\Directory;
use Dagger\File;
use function Dagger\dag;

class Tar
{
    private const ALPINE_IMAGE = 'alpine:latest';
    private const ARCHIVE_PATH = '/tmp/archive';
    private const EXTRACTION_DIR = '/tmp/extracted';

    public function __construct(private File $file)
    {
    }

    /**
     * @param bool $gzip Decompress a gzip-compressed archive (-z)
     * @param bool $bzip2 Decompress a bzip2-compressed archive (-j)
     * @param bool $xz Decompress an xz-compressed archive (-J)
     * @param int|null $stripComponents Number of leading components to strip from file names on extraction (--strip-components=N)
     */
    public function extract(
        bool $gzip = false,
        bool $bzip2 = false,
        bool $xz = false,
        ?int $stripComponents = null
    ): Directory {
        $compression = array_values(array_filter([
            $gzip ? 'z' : null,
            $bzip2 ? 'j' : null,
            $xz ? 'J' : null,
        ]));

        if (count($compression) > 1) {
            throw new \InvalidArgumentException('Only one of $gzip, $bzip2 or $xz may be set');
        }

        if ($stripComponents !== null && $stripComponents < 0) {
            throw new \InvalidArgumentException('$stripComponents must not be negative');
        }

        $command = ['tar', '-x', '-f', self::ARCHIVE_PATH, '-C', self::EXTRACTION_DIR, ...$compression];

        if ($stripComponents !== null) {
            $command[] = '--strip-components=' . $stripComponents;
        }

        $container = dag()
            ->container()
            ->from(self::ALPINE_IMAGE)
            ->withExec(['apk', 'add', '--no-cache', 'tar'])
            ->withFile(self::ARCHIVE_PATH, $this->file)
            ->withExec(['mkdir', '-p', self::EXTRACTION_DIR])
            ->withExec($command);

        return $container->directory(self::EXTRACTION_DIR);
    }
}