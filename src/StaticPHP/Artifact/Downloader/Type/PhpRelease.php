<?php

declare(strict_types=1);

namespace StaticPHP\Artifact\Downloader\Type;

use StaticPHP\Artifact\ArtifactDownloader;
use StaticPHP\Artifact\Downloader\DownloadResult;
use StaticPHP\Exception\DownloaderException;

class PhpRelease implements DownloadTypeInterface, ValidatorInterface, CheckUpdateInterface
{
    use GitHubTokenSetupTrait;

    public const string DEFAULT_PHP_DOMAIN = 'https://www.php.net';

    public const string API_URL = '/releases/index.php?json&version={version}';

    public const string DOWNLOAD_URL = '/distributions/php-{version}.tar.xz';

    public const string GIT_URL = 'https://github.com/php/php-src.git';

    public const string GIT_REV = 'master';

    public const string GITHUB_TAGS_API = 'https://api.github.com/repos/php/php-src/git/matching-refs/tags/{prefix}';

    public const string GITHUB_ARCHIVE_URL = 'https://github.com/php/php-src/archive/refs/tags/{tag}.tar.gz';

    private ?string $sha256 = '';

    public function download(string $name, array $config, ArtifactDownloader $downloader): DownloadResult
    {
        $phpver = $downloader->getOption('with-php', '8.5');
        // Handle 'git' version to clone from php-src repository
        if ($phpver === 'git') {
            $this->sha256 = null;
            return (new Git())->download($name, ['url' => self::GIT_URL, 'rev' => self::GIT_REV], $downloader);
        }
        ['version' => $version, 'url' => $url, 'filename' => $filename, 'sha256' => $this->sha256] = $this->resolveRelease($name, $config, $downloader);
        logger()->debug("Downloading PHP release {$version} from {$url}");
        $path = DOWNLOAD_PATH . "/{$filename}";
        default_shell()->executeCurlDownload($url, $path, retries: $downloader->getRetry());
        return DownloadResult::archive($filename, config: $config, extract: $config['extract'] ?? null, version: $version, downloader: static::class);
    }

    public function validate(string $name, array $config, ArtifactDownloader $downloader, DownloadResult $result): bool
    {
        if ($this->sha256 === null) {
            logger()->debug('Php-src is downloaded from non-release source, skipping validation.');
            return true;
        }

        if ($this->sha256 === '') {
            logger()->error("No SHA256 checksum available for validation of {$name}.");
            return false;
        }

        $path = DOWNLOAD_PATH . "/{$result->filename}";
        $hash = hash_file('sha256', $path);
        if ($hash !== $this->sha256) {
            logger()->error("SHA256 checksum mismatch for {$name}: expected {$this->sha256}, got {$hash}");
            return false;
        }
        logger()->debug("SHA256 checksum validated successfully for {$name}.");
        return true;
    }

    public function checkUpdate(string $name, array $config, ?string $old_version, ArtifactDownloader $downloader): CheckUpdateResult
    {
        $phpver = $downloader->getOption('with-php', '8.5');
        if ($phpver === 'git') {
            // git version: delegate to Git checkUpdate with master branch
            return (new Git())->checkUpdate($name, ['url' => 'https://github.com/php/php-src.git', 'rev' => 'master'], $old_version, $downloader);
        }
        $new_version = $this->resolveRelease($name, $config, $downloader)['version'];
        return new CheckUpdateResult(
            old: $old_version,
            new: $new_version,
            needUpdate: $old_version === null || $new_version !== $old_version,
        );
    }

    /** @return array{version: string, url: string, filename: string, sha256: null|string} */
    protected function resolveRelease(string $name, array $config, ArtifactDownloader $downloader): array
    {
        $phpver = $downloader->getOption('with-php', '8.5');
        $info = $this->fetchPhpReleaseInfo($name, $config, $downloader);
        if ($info === null) {
            return $this->resolvePrereleaseFromGitTags($phpver, $downloader);
        }

        $version = $info['version'];
        $filename = null;
        $sha256 = '';
        foreach ($info['source'] ?? [] as $source) {
            if (str_ends_with($source['filename'] ?? '', '.tar.xz')) {
                $sha256 = $source['sha256'] ?? '';
                $filename = $source['filename'];
                break;
            }
        }
        if ($filename === null) {
            throw new DownloaderException("No suitable source tarball found for PHP version {$version}");
        }
        $url = $config['domain'] ?? self::DEFAULT_PHP_DOMAIN;
        $url .= str_replace('{version}', $version, self::DOWNLOAD_URL);
        return ['version' => $version, 'url' => $url, 'filename' => $filename, 'sha256' => $sha256];
    }

    /** @return array{version: string, url: string, filename: string, sha256: null|string} */
    protected function resolvePrereleaseFromGitTags(string $phpver, ArtifactDownloader $downloader): array
    {
        $is_branch = preg_match('/^\d+\.\d+$/', $phpver) === 1;
        $prefix = $is_branch ? "php-{$phpver}." : "php-{$phpver}";
        $url = str_replace('{prefix}', $prefix, self::GITHUB_TAGS_API);
        logger()->debug("PHP version {$phpver} is not published on php.net, looking it up in php-src tags from {$url}");

        $data = default_shell()->executeCurl($url, headers: $this->getGitHubTokenHeaders(), retries: $downloader->getRetry());
        if ($data === false) {
            throw new DownloaderException("Failed to fetch php-src git tags for PHP version {$phpver}");
        }
        $data = json_decode($data, true);
        if (!is_array($data)) {
            throw new DownloaderException("Invalid php-src git tag list received for PHP version {$phpver}");
        }

        $pattern = '/^php-(' . preg_quote($phpver, '/') . ($is_branch ? '\.\d+' : '') . '(?:(?:alpha|beta|RC)\d+)?)$/';
        $versions = [];
        foreach ($data as $ref) {
            $tag = substr((string) ($ref['ref'] ?? ''), strlen('refs/tags/'));
            if (preg_match($pattern, $tag, $match) === 1) {
                $versions[] = $match[1];
            }
        }
        if (empty($versions)) {
            throw new DownloaderException("PHP version {$phpver} is not available on php.net nor tagged in php-src.");
        }
        usort($versions, version_compare(...));
        $version = end($versions);

        logger()->notice("PHP {$version} is a pre-release, downloading its source archive from php-src git tag.");
        return [
            'version' => $version,
            'url' => str_replace('{tag}', "php-{$version}", self::GITHUB_ARCHIVE_URL),
            'filename' => "php-{$version}.tar.gz",
            'sha256' => null,
        ];
    }

    /** @return null|array null when php.net does not publish this version (yet) */
    protected function fetchPhpReleaseInfo(string $name, array $config, ArtifactDownloader $downloader): ?array
    {
        $phpver = $downloader->getOption('with-php', '8.5');
        // Handle 'git' version to clone from php-src repository
        if ($phpver === 'git') {
            // cannot fetch release info for git version, return empty info to skip validation
            throw new DownloaderException("Cannot fetch PHP release info for 'git' version.");
        }

        $url = $config['domain'] ?? self::DEFAULT_PHP_DOMAIN;
        $url .= self::API_URL;
        $url = str_replace('{version}', $phpver, $url);
        logger()->debug("Fetching PHP release info for version {$phpver} from {$url}");

        // Fetch PHP release info first
        $info = default_shell()->executeCurl($url, retries: $downloader->getRetry());
        if ($info === false) {
            throw new DownloaderException("Failed to fetch PHP release info for version {$phpver}");
        }
        $info = json_decode($info, true);
        if (!is_array($info)) {
            throw new DownloaderException("Invalid PHP release info received for version {$phpver}");
        }
        if (!isset($info['version'])) {
            logger()->debug("php.net has no release for PHP version {$phpver}: " . ($info['error'] ?? 'no version in response'));
            return null;
        }
        return $info;
    }
}
