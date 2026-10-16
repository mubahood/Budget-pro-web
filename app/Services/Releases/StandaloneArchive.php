<?php

namespace App\Services\Releases;

use App\Services\Licensing\Signer;
use App\Support\Licensing\Canonical;
use App\Support\Licensing\Verifier;
use ZipArchive;

/**
 * The signed standalone ZIP (docs/STANDALONE_EDITION_PLAN.md §8.1, §10), after True-Doctor's.
 *
 * build() packs two staged apps (a kernel stage and a web stage: copies, never the working trees,
 * because RELEASE.json, RELEASE_KEYS.json and the web autoloader are written into them) under an
 * allow-list (PackageLayout), stops on anything that looks like a secret, fixes every mode (files
 * 0644, the writable folders 0777) and every mtime, signs PACKAGE.json and verifies the result.
 * verify() checks a ZIP before extraction; verifyExtracted() checks the files in place, and runs
 * in the pre-Laravel installer, so neither uses anything from the framework.
 */
final class StandaloneArchive
{
    public const FORMAT = 1;

    public const PRODUCT = 'budget-pro-standalone';

    private const DIRECTORY_MODE = 040777;

    private const FILE_MODE = 0100644;

    private const MTIME = 315532800; // 1980-01-01, the ZIP epoch: the same bytes build the same archive

    /**
     * @param  string|null  $webRootStage  the web folder's files; null = taken from the two stages (PackageLayout::WEB_ROOT_SOURCES)
     * @param  array{kernel:string,web:string}  $commits  the full commits both stages were made from
     * @param  array<string,string>|null  $trustedKeys  key id => public key for RELEASE_KEYS.json; null = config edition.public_keys
     * @return array{path:string,sha256:string,count:int,bytes:int,manifest:array}
     */
    public function build(string $kernelStage, string $webStage, ?string $webRootStage, string $destination, string $version,
        array $commits, Signer $signer, ?array $trustedKeys = null): array
    {
        if (! preg_match('/^\d+\.\d+\.\d+(?:-[a-z0-9.-]+)?$/', $version)) {
            throw new \InvalidArgumentException('A release needs a semantic version such as 1.0.0.');
        }
        foreach (['kernel', 'web'] as $side) {
            if (! preg_match('/^[a-f0-9]{40}$/', (string) ($commits[$side] ?? ''))) {
                throw new \InvalidArgumentException("A release needs the full {$side} commit it was built from.");
            }
        }
        foreach ([$kernelStage.'/vendor/autoload.php', $webStage.'/vendor/autoload.php', $webStage.'/public/build/manifest.json'] as $needed) {
            if (! is_file($needed)) {
                throw new \RuntimeException('Composer dependencies and built assets must be staged before packing: '.$needed.' is missing.');
            }
        }
        foreach ([$kernelStage, $webStage] as $stage) {
            foreach (glob($stage.'/.env*') ?: [] as $environment) {
                if (basename($environment) !== '.env.example') {
                    throw new \RuntimeException('A staged app contains '.basename($environment).': stage from a clean copy.');
                }
            }
        }

        WebAutoload::relink($webStage);
        $keys = $trustedKeys ?? (array) config('edition.public_keys', []);
        $keys[$signer->keyId()] = $signer->publicKey();
        ksort($keys);
        $release = [
            'version' => $version, 'commits' => ['kernel' => $commits['kernel'], 'web' => $commits['web']], 'edition' => 'standalone',
            'built_at' => gmdate('Y-m-d\TH:i:s\Z'), 'mobile_min_version' => (string) config('mobile.min_version', ''),
        ];
        file_put_contents($kernelStage.'/RELEASE.json', Canonical::json($release)."\n");
        file_put_contents($kernelStage.'/RELEASE_KEYS.json', Canonical::json($keys)."\n");

        $files = (new PackageCollector)->collect($kernelStage, $webStage, $webRootStage);
        $manifest = [
            'format' => self::FORMAT, 'product' => self::PRODUCT, 'version' => $version,
            'commits' => $release['commits'], 'built_at' => $release['built_at'],
            'directories' => PackageLayout::DIRECTORIES,
            'files' => array_map(static fn (string $source): array => ['sha256' => hash_file('sha256', $source), 'size' => filesize($source)], $files),
        ];
        $signed = ['manifest' => $manifest] + $signer->sign($manifest);

        @unlink($destination);
        $zip = new ZipArchive;
        if ($zip->open($destination, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('The release archive could not be created at '.$destination.'.');
        }
        try {
            foreach (PackageLayout::DIRECTORIES as $directory) {
                $zip->addEmptyDir($directory) || throw new \RuntimeException("Could not add {$directory}.");
                $this->fix($zip, $directory, self::DIRECTORY_MODE);
            }
            foreach ($files as $path => $source) {
                $zip->addFile($source, $path) || throw new \RuntimeException("Could not add {$path}.");
                $this->fix($zip, $path, self::FILE_MODE);
            }
            $zip->addFromString('PACKAGE.json', Canonical::json($signed)."\n");
            $this->fix($zip, 'PACKAGE.json', self::FILE_MODE);
        } finally {
            $zip->close();
        }

        $this->verify($destination, new Verifier($keys));

        return ['path' => $destination, 'sha256' => hash_file('sha256', $destination), 'count' => count($files),
            'bytes' => (int) filesize($destination), 'manifest' => $manifest];
    }

    /** Check a ZIP before extracting it: the signature, then every entry's path, kind, size and hash. */
    public function verify(string $archive, Verifier $verifier): array
    {
        $zip = new ZipArchive;
        if ($zip->open($archive, ZipArchive::RDONLY) !== true) {
            throw new \RuntimeException('The release archive cannot be opened.');
        }
        try {
            $manifest = $this->manifest((string) $zip->getFromName('PACKAGE.json'), $verifier);
            $seen = [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $entry = $zip->statIndex($i);
                $path = (string) ($entry['name'] ?? '');
                $isDirectory = str_ends_with($path, '/');
                $listed = $isDirectory ? in_array($path, PackageLayout::DIRECTORIES, true) : ($path === 'PACKAGE.json' || PackageLayout::allowed($path));
                if (! $listed || isset($seen[$path])) {
                    throw new \RuntimeException("The archive contains an unsafe or duplicate path: {$path}.");
                }
                if ($zip->getExternalAttributesIndex($i, $system, $attributes) && (($attributes >> 16) & 0170000) === 0120000) {
                    throw new \RuntimeException("The archive contains a symbolic link: {$path}.");
                }
                $seen[$path] = true;
                if ($isDirectory || $path === 'PACKAGE.json') {
                    if ($isDirectory && $entry['size'] !== 0) {
                        throw new \RuntimeException('An archive directory contains data.');
                    }

                    continue;
                }
                $expected = $manifest['files'][$path] ?? null;
                if (! is_array($expected) || ($expected['size'] ?? null) !== $entry['size'] || $entry['size'] > 100 * 1024 * 1024) {
                    throw new \RuntimeException("The archive file list does not match: {$path}.");
                }
                $stream = $zip->getStream($path) ?: throw new \RuntimeException("Cannot read {$path}.");
                $hash = hash_init('sha256');
                hash_update_stream($hash, $stream);
                fclose($stream);
                if (! hash_equals((string) ($expected['sha256'] ?? ''), hash_final($hash))) {
                    throw new \RuntimeException("The archive hash does not match: {$path}.");
                }
            }
            if (count($seen) !== count($manifest['files']) + count(PackageLayout::DIRECTORIES) + 1) {
                throw new \RuntimeException('The archive is missing a listed file.');
            }

            return $manifest;
        } finally {
            $zip->close();
        }
    }

    /**
     * Check an extracted package in place, before the installer writes anything: PACKAGE.json beside
     * budget_pro/ and budget_pro_web/ (in $appsParent), the web folder's files wherever the buyer put them.
     */
    public function verifyExtracted(string $appsParent, string $webRoot, Verifier $verifier): array
    {
        $document = rtrim($appsParent, '/').'/PACKAGE.json';
        if (! is_file($document)) {
            throw new \RuntimeException('PACKAGE.json was not found beside budget_pro and budget_pro_web. Extract the complete ZIP in your account\'s home folder.');
        }
        $manifest = $this->manifest((string) file_get_contents($document), $verifier);
        if (count($manifest['files']) > 100000) {
            throw new \RuntimeException('The release lists too many files.');
        }
        foreach (PackageLayout::DIRECTORIES as $directory) {
            $folder = PackageLayout::extractedPath(rtrim($directory, '/'), $appsParent, $webRoot);
            if ($folder !== null && (! is_dir($folder) || is_link($folder))) {
                throw new \RuntimeException("A folder of the release is missing: {$directory}.");
            }
        }
        foreach ($manifest['files'] as $path => $expected) {
            if (! is_string($path) || ! PackageLayout::allowed($path) || ! is_array($expected)) {
                throw new \RuntimeException('The release file list contains an unsafe path.');
            }
            $file = PackageLayout::extractedPath($path, $appsParent, $webRoot);
            if ($file === null) {
                continue;
            }
            if (! is_file($file) || is_link($file) || filesize($file) !== ($expected['size'] ?? null)
                || ! hash_equals((string) ($expected['sha256'] ?? ''), (string) hash_file('sha256', $file))) {
                throw new \RuntimeException("A release file is missing or was changed: {$path}. Upload the ZIP again and extract it whole.");
            }
        }

        return $manifest;
    }

    /** The signed manifest from a PACKAGE.json document, or an exception. */
    private function manifest(string $json, Verifier $verifier): array
    {
        try {
            $signed = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new \RuntimeException('PACKAGE.json is damaged.');
        }
        $manifest = is_array($signed) ? ($signed['manifest'] ?? null) : null;
        if (! is_array($manifest) || ($manifest['format'] ?? null) !== self::FORMAT || ($manifest['product'] ?? null) !== self::PRODUCT
            || ($manifest['directories'] ?? null) !== PackageLayout::DIRECTORIES || ! is_array($manifest['files'] ?? null)
            || ! $verifier->verifies($manifest, (string) ($signed['signature'] ?? ''), (string) ($signed['key_id'] ?? ''))) {
            throw new \RuntimeException('The release signature is invalid. Download the release again from your buyer page.');
        }

        return $manifest;
    }

    private function fix(ZipArchive $zip, string $name, int $mode): void
    {
        $zip->setMtimeName($name, self::MTIME);
        $zip->setExternalAttributesName($name, ZipArchive::OPSYS_UNIX, $mode << 16);
    }
}
