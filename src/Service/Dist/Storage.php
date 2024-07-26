<?php

declare(strict_types=1);

namespace Buddy\Repman\Service\Dist;

use Buddy\Repman\Service\Dist;
use Buddy\Repman\Service\Downloader;
use Exception;
use League\Flysystem\FileExistsException;
use League\Flysystem\FileNotFoundException;
use League\Flysystem\FilesystemInterface;
use Munus\Control\Option;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;
use ValueError;
use ZipArchive;
use function sprintf;

class Storage
{
    private Downloader $downloader;
    private FilesystemInterface $repoFilesystem;
    private string $distsUrlTemplate;

    public function __construct(Downloader $downloader, FilesystemInterface $repoFilesystem, string $distsUrlTemplate)
    {
        $this->downloader = $downloader;
        $this->repoFilesystem = $repoFilesystem;
        $this->distsUrlTemplate = $distsUrlTemplate;
    }

    public function has(Dist $dist): bool
    {
        return $this->repoFilesystem->has($this->filename($dist));
    }

    /**
     * Downloads a file from a given URL and saves it locally.
     *
     * @param string $url
     * @param Dist $dist
     * @param array $headers
     * @return string|null
     * @throws FileExistsException
     * @throws Throwable
     */
    public function download(string $url, Dist $dist, array $headers = []): ?string
    {
        if ($this->has($dist)) {
            return null;
        }

        $filename = $this->filename($dist);

        $this->repoFilesystem->writeStream(
            $filename,
            $this->downloader->getContents(
                $url,
                $headers,
                function () use ($url): void {
                    throw new NotFoundHttpException(sprintf('File not found at %s', $url));
                }
            )->getOrElseThrow(
                new RuntimeException(sprintf('Failed to download %s from %s', $dist->package(), $url))
            )
        );

        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return self::updateDistUrl($url, $filename, $dist);
        }
        return null;
    }

    public function remove(Dist $dist): void
    {
        $filename = $this->filename($dist);
        if ($this->repoFilesystem->has($filename)) {
            $this->repoFilesystem->delete($filename);
        }
    }

    public function filename(Dist $dist): string
    {
        return sprintf(
            '%s/dist/%s/%s_%s.%s',
            $dist->repo(),
            $dist->package(),
            $dist->version(),
            $dist->ref(),
            $dist->format()
        );
    }

    public function size(Dist $dist): int
    {
        $filename = $this->filename($dist);
        if ($this->repoFilesystem->has($filename)) {
            /* @phpstan-ignore-next-line - will always return int because file exists */
            return $this->repoFilesystem->getSize($filename);
        }

        return 0;
    }

    /**
     * @return Option<string>
     */
    public function getLocalFileForDist(Dist $dist): Option
    {
        return $this->getLocalFileForDistUrl($this->filename($dist));
    }

    /**
     * @return Option<string>
     */
    public function getLocalFileForDistUrl(string $distFilename): Option
    {
        $tmpLocalFilename = $this->getTempFileName();
        $tmpLocalFileHandle = \fopen(
            $tmpLocalFilename,
            'wb'
        );
        if (false === $tmpLocalFileHandle) {
            throw new RuntimeException('Could not open temporary file for writing zip file for dist.');
        }

        $distReadStream = $this->readStream($distFilename)->getOrNull();
        if (null === $distReadStream) {
            return Option::none();
        }
        \stream_copy_to_stream($distReadStream, $tmpLocalFileHandle);
        \fclose($tmpLocalFileHandle);

        return Option::of($tmpLocalFilename);
    }

    private function getTempFileName(): string
    {
        return \sys_get_temp_dir().\DIRECTORY_SEPARATOR.\uniqid('repman-dist-', true);
    }

    /**
     * @return Option<resource>
     */
    private function readStream(string $path): Option
    {
        try {
            $resource = $this->repoFilesystem->readStream($path);
            if (false === $resource) {
                return Option::none();
            }
        } catch (FileNotFoundException $e) {
            return Option::none();
        }

        return Option::of($resource);
    }

    /**
     * Updates the dist URL in composer.json of a package.
     *
     * @param string $url
     * @param string $filename
     * @param Dist $dist
     *
     * @return string
     */
    private function updateDistUrl(string $url, string $filename, Dist $dist): string
    {
        try {
            $zip = new ZipArchive();
            $tmpLocalFilename = $this->getLocalFileForDist($dist);
            $zip->open($tmpLocalFilename->get());
            $composerJsonIndex = $zip->locateName('composer.json', ZipArchive::FL_NODIR);
            $composerJsonZipPath = $zip->getNameIndex($composerJsonIndex);
            $composerJsonStream = $zip->getStream($composerJsonZipPath);
            $composerJson = json_decode(stream_get_contents($composerJsonStream));
            fclose($composerJsonStream);
            $repositoryUrl = str_replace('{organization}', $dist->repo(), $this->distsUrlTemplate);
            $distUrl = sprintf(
                '%s/dists/%s/%s/%s.zip',
                $repositoryUrl,
                $dist->package(),
                $dist->version(),
                $dist->ref()
            );

            $composerJson->dist = (object) [
                'type' => 'zip',
                'url' => $distUrl,
                'reference' => $dist->ref(),
            ];
            $zip->addFromString($composerJsonZipPath, json_encode($composerJson, JSON_PRETTY_PRINT), ZipArchive::FL_OVERWRITE);
            $zip->close();
            $tmpLocalFilenameStream = fopen($tmpLocalFilename->get(), 'r');
            $this->repoFilesystem->putStream($filename, $tmpLocalFilenameStream);
            $artifactFileStream = fopen($url, 'w+');
            stream_copy_to_stream($tmpLocalFilenameStream, $artifactFileStream);
            fclose($artifactFileStream);
            fclose($tmpLocalFilenameStream);
            unlink($tmpLocalFilename->get());
            return $distUrl;

        } catch (Exception|ValueError $error) {
            throw new RuntimeException(
                sprintf('Failed to update dist URL in composer.json of package: %s:%s', $dist->package(), $dist->version()),
                0,
                $error
            );
        }
    }
}
