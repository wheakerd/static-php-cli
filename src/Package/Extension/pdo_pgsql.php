<?php

declare(strict_types=1);

namespace Package\Extension;

use Package\Target\php;
use StaticPHP\Attribute\Package\CustomPhpConfigureArg;
use StaticPHP\Attribute\Package\Extension;
use StaticPHP\Package\PackageBuilder;
use StaticPHP\Package\PackageInstaller;
use StaticPHP\Package\PhpExtensionPackage;

#[Extension('pdo_pgsql')]
class pdo_pgsql extends PhpExtensionPackage
{
    #[CustomPhpConfigureArg('Darwin')]
    #[CustomPhpConfigureArg('Linux')]
    public function getUnixConfigureArg(bool $shared, PackageBuilder $builder, PackageInstaller $installer): string
    {
        if (php::getPHPVersionID() >= 80400) {
            return '--with-pdo-pgsql' . ($shared ? '=shared' : '') . pgsql::libpqConfigureVars($builder, $installer);
        }
        return '--with-pdo-pgsql=' . ($shared ? 'shared,' : '') . $builder->getBuildRootPath();
    }
}
