<?php

declare(strict_types=1);

namespace DaggerModule;

use Dagger\Attribute\DaggerFunction;
use Dagger\Attribute\DaggerObject;
use Dagger\Attribute\Doc;
use Dagger\Container;
use Dagger\Directory;

use Dagger\File;
use function Dagger\dag;

#[DaggerObject]
#[Doc('A generated module for Wpdagger functions')]
class Wpdagger
{
    private const WORDPRESS_URL = 'https://wordpress.org/wordpress-%s.tar.gz';
    private const PHP_INI_CONF_DIR = '/usr/local/etc/php/conf.d';

    #[DaggerFunction]
    #[Doc(<<<'DOC'
        Builds a WordPress container mirroring the official docker-library/wordpress image,
        i.e. the default "wordpress" tag: the apache variant on a Debian-based PHP image.

        The PHP base container and its standard extensions (bcmath, exif, intl, mysqli, zip)
        are managed through the sai module. Everything else (ghostscript/HEIC deps, gd and
        imagick, opcache & error-logging ini files, apache modules, the WordPress tarball,
        wp-config-docker.php and docker-entrypoint.sh) replicates the upstream Dockerfile.

        The result is a Container build graph; export it with exportImage("wordpress:latest"),
        publish() it, or run it directly with up()/asService(). Note: the upstream image also
        declares VOLUME /var/www/html; declare/mount it yourself at runtime.
        DOC)]
    public function wordpress(
        #[Doc('PHP version of the base image, e.g. 8.2, 8.3 or 8.4')]
        string $phpVersion = '8.4',
        #[Doc('WordPress version to install')]
        string $wordpressVersion = '7.1.3',
    ): WPContainer {

        $php = dag()
            ->sai()
            ->php(version: $phpVersion, os: 'debian', variant: 'apache')
            ->withExtensions(['bcmath', 'exif', 'intl', 'mysqli', 'zip', 'gd', 'imagick'])
            ->container();

        $wordpress = dag()->arc()->unarchive(dag()->http(sprintf(self::WORDPRESS_URL, $wordpressVersion)));

        $contentDir = dag()->directory();

        foreach ($wordpress->entries('wordpress/wp-content') as $entry) {
            if (str_ends_with($entry, '/')) {
                $contentDir = $contentDir->withNewDirectory($entry);
            }
        }

        $php = $this->installDependencies($php);
        $php = $this->configureApache($php);
        $php = $this->configurePHP($php);
        $php = $this->addWordPress($php, $wordpress);

        $php = $php
            ->withWorkdir('/var/www/html')
            ->withDirectory('/var/www/html/wp-content', $contentDir, owner: 'www-data:www-data', permissions: 0o1777)
            ->withFile('/usr/local/bin/docker-entrypoint.sh', $this->getFile('docker-entrypoint.sh'), permissions: 0o755)
            ->withSymlink('/usr/local/bin/docker-entrypoint.sh', '/usr/local/bin/docker-ensure-installed.sh')
            ->withEntrypoint(['docker-entrypoint.sh'])
            ->withDefaultArgs(['apache2-foreground']);

        return new WPContainer($php);
    }

    private function getFile(string $name): File
    {
        return dag()->currentModule()->source()->file('src/files/' . $name);
    }

    private function installDependencies(Container $php): Container
    {
        return $php
            ->withExec(['apt-get', 'update'])
            ->withExec(['apt-get', 'install', '-y', '--no-install-recommends', 'ghostscript', 'libheif-plugin-aomenc', 'libheif-plugin-x265'])
            ->withExec(['rm', '-rf', '/var/lib/apt/lists/*']);
    }

    private function configureApache(Container $php): Container
    {
        return $php
            ->withFile('/etc/apache2/conf-available/remoteip.conf', $this->getFile('remoteip.conf'))
            ->withExec(['a2enmod', 'rewrite', 'expires', 'remoteip'])
            ->withExec(['a2enconf', 'remoteip'])
            ->withExec([
                'find',
                '/etc/apache2',
                '-type',
                'f',
                '-name',
                '*.conf',
                '-exec',
                'sed',
                '-ri',
                's/([[:space:]]*LogFormat[[:space:]]+"[^"]*)%h([^"]*")/\1%a\2/g',
                '{}',
                '+'
            ]);
    }

    private function addWordPress(Container $php, Directory $wordpress): Container
    {
        return $php
            ->withDirectory('/usr/src', $wordpress, owner: 'www-data:www-data')
            ->withFile('/usr/src/wordpress/wp-config-docker.php', $this->getFile('wp-config-docker.php'), owner: 'www-data:www-data')
            ->withFile('/usr/src/wordpress/.htaccess', $this->getFile('.htaccess'), owner: 'www-data:www-data')
            ;
    }

    private function configurePHP(Container $php): Container
    {
        return $php
            ->withFile(self::PHP_INI_CONF_DIR . '/opcache-recommended.ini', $this->getFile('opcache.ini'))
            ->withFile(self::PHP_INI_CONF_DIR . '/error-logging.ini', $this->getFile('errorlogging.ini'));
    }
}