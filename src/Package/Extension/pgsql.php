<?php

declare(strict_types=1);

namespace Package\Extension;

use Package\Target\php;
use StaticPHP\Attribute\Package\CustomPhpConfigureArg;
use StaticPHP\Attribute\Package\Extension;
use StaticPHP\Package\PackageBuilder;
use StaticPHP\Package\PackageInstaller;
use StaticPHP\Package\PhpExtensionPackage;
use StaticPHP\Util\DependencyResolver;
use StaticPHP\Util\SPCConfigUtil;

#[Extension('pgsql')]
class pgsql extends PhpExtensionPackage
{
    #[CustomPhpConfigureArg('Darwin')]
    #[CustomPhpConfigureArg('Linux')]
    public function getUnixConfigureArg(bool $shared, PackageBuilder $builder, PackageInstaller $installer): string
    {
        if (php::getPHPVersionID() >= 80400) {
            return '--with-pgsql' . ($shared ? '=shared' : '') . self::libpqConfigureVars($builder, $installer);
        }
        return '--with-pgsql=' . ($shared ? 'shared,' : '') . $builder->getBuildRootPath();
    }

    /** These override pkg-config, so they must carry libpq itself too */
    public static function libpqConfigureVars(PackageBuilder $builder, PackageInstaller $installer): string
    {
        $sub_deps = DependencyResolver::getSubDependencies('postgresql', array_keys($installer->getResolvedPackages()), include_suggests: true);
        $libfiles = new SPCConfigUtil(['no_php' => true, 'libs_only_deps' => true])->configWithResolvedPackages(['postgresql', ...$sub_deps])['libs'];
        return ' PGSQL_CFLAGS=-I' . $builder->getIncludeDir() .
            ' PGSQL_LIBS="-L' . $builder->getLibDir() . ' ' . $libfiles . '"';
    }

    #[CustomPhpConfigureArg('Windows')]
    public function getWindowsConfigureArg(bool $shared, PackageBuilder $builder): string
    {
        if (php::getPHPVersionID() >= 80400) {
            return '--with-pgsql';
        }
        return "--with-pgsql={$builder->getBuildRootPath()}";
    }

    public function getSharedExtensionEnv(): array
    {
        $parent = parent::getSharedExtensionEnv();
        // gnu17, not c17: PHP 8.6 headers use typeof
        $parent['CFLAGS'] .= ' -std=gnu17 -Wno-int-conversion';
        return $parent;
    }
}
