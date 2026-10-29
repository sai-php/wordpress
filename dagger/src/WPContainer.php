<?php

namespace DaggerModule;

use Dagger\Attribute\DaggerFunction;
use Dagger\Attribute\DaggerObject;
use Dagger\Container;
use Dagger\Directory;
use Dagger\File;
use function Dagger\dag;

#[DaggerObject]
class WPContainer
{
    public function __construct(private Container $base)
    {

    }

    #[DaggerFunction]
    public function withPlugin(
        string $name,
        ?string $pluginUrl = null,
        File $pluginTarBall = null
    ): WPContainer {
        if ($pluginUrl !== null) {
            $pluginTarBall = dag()->http($pluginUrl);
        }

        $pluginCode = dag()->arc()->unarchive($pluginTarBall);

        $slug = $this->topLevelDirectory($pluginCode);

        return new WPContainer(
            $this->base->withDirectory(
                '/usr/src/wordpress/wp-content/plugins/' . $name,
                $pluginCode->directory($slug),
                owner: 'www-data:www-data'
            )
        );
    }

    #[DaggerFunction]
    public function container(): Container
    {
        return $this->base;
    }

    private function topLevelDirectory(Directory $dir): string
    {
        foreach ($dir->entries() as $entry) {
            if (str_ends_with($entry, '/')) {
                return substr($entry, 0, -1);
            }
        }
        throw new \RuntimeException('Plugin archive does not contain a top-level directory');
    }
}