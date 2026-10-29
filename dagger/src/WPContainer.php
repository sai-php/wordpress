<?php

namespace DaggerModule;

use Dagger\Attribute\DaggerFunction;
use Dagger\Attribute\DaggerObject;
use Dagger\Container;
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
        ?File $pluginTarBall = null
    ): WPContainer {
        if ($pluginUrl !== null) {
            $pluginTarBall = dag()->http($pluginUrl);
        }

        $pluginCode = (new Tar($pluginTarBall))->extract(gzip: true, stripComponents: 1);

        return new WPContainer(
            $this->base->withDirectory(
                '/usr/src/wordpress/wp-content/plugins/' . $name,
                $pluginCode,
                owner: 'www-data:www-data'
            )
        );
    }

    #[DaggerFunction]
    public function container(): Container
    {
        return $this->base;
    }
}