<?php

namespace Buddy\Repman\Service;

use Buddy\Repman\Query\User\Model\Organization;
use Composer\Config;
use Composer\Factory;
use Composer\IO\BufferIO;
use Composer\Repository\RepositoryFactory;
use Exception;
use League\Flysystem\FilesystemInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use ZipArchive;

class PackageArtifactUploader
{
    private string $artifactsDir;
    private FilesystemInterface $artifactsFilesystem;

    public function __construct(string $artifactsDir, FilesystemInterface $artifactsFilesystem)
    {
        $this->artifactsDir = $artifactsDir;
        $this->artifactsFilesystem = $artifactsFilesystem;
    }

    /**
     * @param UploadedFile[] $files
     * @param Organization $organization
     * @return string[]
     */
    public function save(array $files, Organization $organization): array
    {
        $organizationArtifactsPath = $organization->alias();

        if (!$this->artifactsFilesystem->has($organizationArtifactsPath)) {
            $this->artifactsFilesystem->createDir($organizationArtifactsPath);
        }

        $io = new BufferIO('', OutputInterface::VERBOSITY_VERY_VERBOSE);
        unset(Config::$defaultRepositories['packagist.org']);
        $config = Factory::createConfig($io);

        $packagesPath = [];

        foreach ($files as $file) {
            if (!$file instanceof UploadedFile || !$file->isValid()) {
                continue;
            }

            $fileAbsPath = $file->getRealPath();
            $originalName = $file->getClientOriginalName();
            $extension = $file->guessExtension();
            if ($extension !== 'zip') {
                continue;
            }

            $zip = new ZipArchive();

            if ($zip->open($fileAbsPath) !== true) {
                $error = $zip->getStatusString();
                throw new \RuntimeException("Error while opening ZIP file '$originalName', code: $error");
            }

            $composerJsonIndex = $zip->locateName('composer.json', ZipArchive::FL_NODIR);

            if ($composerJsonIndex === false) {
                throw new \RuntimeException("Could not find any composer.json in the ZIP file '$originalName'");
            }

            $composerJsonZipPath = $zip->getNameIndex($composerJsonIndex);

            if ($composerJsonZipPath === false) {
                throw new \RuntimeException("Could not get composer.json path in the ZIP file '$originalName' at file index '$composerJsonIndex'");
            }

            $composerJsonStream = $zip->getStream($composerJsonZipPath);

            if ($composerJsonStream === false) {
                throw new \RuntimeException("Could not get a read file handler on '$composerJsonZipPath' in ZIP file '$originalName'");
            }

            $composerJsonContent = stream_get_contents($composerJsonStream);
            fclose($composerJsonStream);
            if ($composerJsonContent === false) {
                throw new \RuntimeException("Could not read file '$composerJsonZipPath' in ZIP file '$originalName'");
            }

            $composerJson = json_decode($composerJsonContent, true);

            if ($composerJson === null) {
                throw new \RuntimeException("Parsing error on '$composerJsonZipPath' in ZIP file '$originalName'. File content is : $composerJsonContent");
            }

            $config->merge([
                'repositories' => [
                    [
                        'type' => 'package',
                        'package' => $composerJson,
                    ],
                ],
            ]);

            try {
                $repositories = RepositoryFactory::defaultRepos($io, $config);
                $repository = current($repositories);
                $packages = $repository->getPackages();
                $package = current($packages);
                $packagePath = sprintf(
                    '%s/%s',
                    $organizationArtifactsPath,
                    $package->getName()
                );

                if (!$this->artifactsFilesystem->has($packagePath)) {
                    $this->artifactsFilesystem->createDir($packagePath);
                }

                $packageVersionFilePath = sprintf(
                    '%s/%s_%s.%s',
                    $packagePath,
                    $package->getVersion(),
                    sha1_file($fileAbsPath),
                    $extension
                );

                $composerPackageZipPath = dirname($composerJsonZipPath);

                if ($composerPackageZipPath !== '.') {
                    for ($i = 0; $i <= $zip->count(); $i++) {
                        $itemName = $zip->getNameIndex($i);
                        if (str_starts_with($itemName, $composerPackageZipPath)) {
                            $newItemName = substr($itemName, strlen($composerPackageZipPath) + 1, strlen($itemName));
                            if (empty($newItemName)) {
                                $zip->deleteIndex($i);
                            } else {
                                $zip->renameIndex($i, $newItemName);
                            }
                        } else {
                            $zip->deleteIndex($i);
                        }
                    }
                }
                $zip->close();

                $packageArtifactsAbsPath = $this->artifactsDir . '/' . $packagePath;
                $artifactAbsPath = $this->artifactsDir . '/' . $packageVersionFilePath;

                if (rename($fileAbsPath, $artifactAbsPath)) {
                    if (!in_array($packageArtifactsAbsPath, $packagesPath, true)) {
                        $packagesPath[$package->getName()]=$packageArtifactsAbsPath;
                    }
                } else {
                    throw new \RuntimeException("Failed to move ZIP file '$originalName' from '$fileAbsPath' to '$artifactAbsPath'");
                }

            } catch (Exception $exception) {
                throw new \RuntimeException("Parsing error on composer.json in the ZIP file '$originalName'", 0, $exception);
            }
        }
        return $packagesPath;
    }
}
