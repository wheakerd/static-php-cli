<?php

declare(strict_types=1);

namespace Package\Artifact;

use StaticPHP\Attribute\Artifact\AfterSourceExtract;
use StaticPHP\Attribute\PatchDescription;
use StaticPHP\Exception\ValidationException;
use StaticPHP\Runtime\SystemTarget;
use StaticPHP\Util\FileSystem;
use StaticPHP\Util\System\LinuxUtil;

class libaom
{
    #[AfterSourceExtract('libaom')]
    #[PatchDescription('Patch libaom for Linux Musl distributions - posix implicit declaration')]
    public function patch(string $target_path): void
    {
        spc_skip_if(SystemTarget::getTargetOS() !== 'Linux' || !LinuxUtil::isMuslDist(), 'Only for Linux Musl distros');

        $cmakelists = $target_path . '/CMakeLists.txt';
        $content = FileSystem::readFile($cmakelists);
        if (str_contains($content, '_POSIX_C_SOURCE')) {
            return;
        }
        foreach (['if(ENABLE_APPS)', 'if(ENABLE_EXAMPLES)'] as $guard) {
            if (str_contains($content, $guard)) {
                FileSystem::replaceFileStr($cmakelists, $guard, $guard . "\n  add_definitions(-D_POSIX_C_SOURCE=200112L)");
                return;
            }
        }
        throw new ValidationException('libaom CMakeLists.txt has neither an ENABLE_APPS nor an ENABLE_EXAMPLES guard to patch');
    }
}
