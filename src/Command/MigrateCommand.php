<?php

declare(strict_types=1);

namespace App\Command;

use App\Reader\CsvReader;
use Box\Spout\Common\Exception\IOException;
use Box\Spout\Common\Exception\UnsupportedTypeException;
use Box\Spout\Reader\Exception\ReaderNotOpenedException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Process\Process;

/**
 * @author Pierre Allard <pierre.allard@akeneo.com>
 */
class MigrateCommand extends Command
{
    protected static $defaultName = 'app:migrate';
    private const ASSETS_CSV_FILENAME = '/tmp/assets.csv';
    private const VARIATIONS_CSV_FILENAME = '/tmp/variations.csv';

    private const LOCALIZABLE = 'localizable';
    private const NON_LOCALIZABLE = 'non-localizable';
    private const BOTH = 'both';
    private const AUTO = 'auto';
    private const YES = 'yes';
    private const NO = 'no';

    private const CSV_FIELD_DELIMITER = ';';
    private const CSV_FIELD_ENCLOSURE = '"';
    private const CSV_END_OF_LINE_CHARACTER = "\n";

    private const CATEGORIES = 'categories';
    private const CATEGORY_LIMIT = 100;

    private const TAGS = 'tags';
    private const TAG_LIMIT = 100;

    /** @var SymfonyStyle */
    private $io;

    /** @var CsvReader[] */
    private $csvReaders = [];

    public function __construct(
        CreateFamilyCommand $createFamilyCommand,
        MergeAssetsAndVariationsFilesCommand $mergeAssetsAndVariationsFilesCommand
    ) {
        parent::__construct($this::$defaultName);
    }

    protected function configure()
    {
        $this
            ->setDescription("Migrate your assets
    You can specify the argument 'asset-family-code' if you want to create only 1 asset family.
    If you don't specify this argument, you will need an extra column called 'asset' in your assets CSV file.
    If you only have non localizable assets, it will create and migrate an asset family with a non localizable reference and non localizable variations.
    If you only have localizable assets, it will create and migrate an asset family with localizable reference and localizable variations.
    If you have localizable and non localizable assets, it will create an asset family with both fields.")
            ->addArgument('assets-csv-filename', InputArgument::OPTIONAL, 'The path to the Assets CSV file', self::ASSETS_CSV_FILENAME)
            ->addArgument('variations-csv-filename', InputArgument::OPTIONAL, 'The path to the Variations CSV file', self::VARIATIONS_CSV_FILENAME)
            ->addOption('asset-family-code', null, InputOption::VALUE_OPTIONAL, 'The asset family code to migrate', null)
            ->addOption('reference-type', null, InputOption::VALUE_OPTIONAL,
                sprintf(
                    'Enable if reference is localizable or not. 
When set to "%s", it will guess the value from the assets file content.
Allowed values: %s|%s|%s|%s',
                    self::AUTO,
                    self::LOCALIZABLE,
                    self::NON_LOCALIZABLE,
                    self::BOTH,
                    self::AUTO
                ),
                self::AUTO
            )
            ->addOption('with-categories', null, InputOption::VALUE_OPTIONAL,
                sprintf('Import the categories from your assets file.
When set to "%s", your new asset family will have a "%s" field, and every asset will contains its former categories.
When set to "%s", it will guess the value from the assets file content.
It will only create the "%s" field if more than 1 category is found in the assets file.
Allowed values: %s|%s|%s',
                    self::YES,
                    self::CATEGORIES,
                    self::AUTO,
                    self::CATEGORIES,
                    self::YES,
                    self::NO,
                    self::AUTO
                ),
                self::AUTO
            )
            ->addOption('with-variations', null, InputOption::VALUE_OPTIONAL,
                sprintf('Add the variations to your new assets
When set to "%s", your new asset family will have variation field(s), and variations will be imported.
Allowed values: %s|%s',
                    self::YES,
                    self::YES,
                    self::NO
                ),
                self::YES
            )
            ->addOption('convert-category-to-option', null, InputOption::VALUE_OPTIONAL,
                sprintf('Import the categories as "multiple_options".
When set to "%s", your new asset family will have a multiple options "%s" field.
When set to "%s", your new asset family will have a text "%s" field.
When set to "%s", it will guess the attribute type to set from the assets file content.
It will use a multiple option field if you have less than %d different categories in your assets file.
Allowed values: %s|%s|%s',
                    self::YES,
                    self::CATEGORIES,
                    self::NO,
                    self::CATEGORIES,
                    self::AUTO,
                    self::CATEGORY_LIMIT,
                    self::YES,
                    self::NO,
                    self::AUTO
                ),
                self::AUTO
            )
            ->addOption('convert-tag-to-option', null, InputOption::VALUE_OPTIONAL,
                sprintf('Import the tags as "multiple_options".
When set to "%s", your new asset family will have a multiple options "%s" field.
When set to "%s", your new asset family will have a text "%s" field.
When set to "%s", it will guess the attribute type to set from the assets file content.
It will use a multiple option field if you have less than %d different tags in your assets file.
Allowed values: %s|%s|%s',
                    self::YES,
                    self::TAGS,
                    self::NO,
                    self::TAGS,
                    self::AUTO,
                    self::TAG_LIMIT,
                    self::YES,
                    self::NO,
                    self::AUTO
                ),
                self::AUTO
            )
        ;
    }

    public function execute(InputInterface $input, OutputInterface $output)
    {
        $this->io = new SymfonyStyle($input, $output);
        $this->io->title('Migration of your assets');

        $assetCsvFilename = $input->getArgument('assets-csv-filename');
        $variationsCsvFilename = $input->getArgument('variations-csv-filename');

        $assetFamilyCode = $input->getOption('asset-family-code');

        $referenceType = $input->getOption('reference-type');
        ArgumentChecker::assertOptionIsAllowed($referenceType, 'reference-type', [self::LOCALIZABLE, self::NON_LOCALIZABLE, self::BOTH, self::AUTO]);

        $withCategories = $input->getOption('with-categories');
        ArgumentChecker::assertOptionIsAllowed($withCategories, 'with-categories', [self::YES, self::NO, self::AUTO]);

        $withVariations = $input->getOption('with-variations');
        ArgumentChecker::assertOptionIsAllowed($withVariations, 'with-variations', [self::YES, self::NO]);

        $convertCategoryToOption = $input->getOption('convert-category-to-option');
        ArgumentChecker::assertOptionIsAllowed($convertCategoryToOption, 'convert-category-to-option', [self::YES, self::NO, self::AUTO]);

        $convertTagToOption = $input->getOption('convert-tag-to-option');
        ArgumentChecker::assertOptionIsAllowed($convertTagToOption, 'convert-tag-to-option', [self::YES, self::NO, self::AUTO]);

        if (!empty($assetFamilyCode)) {
            $this->migrate(
                $assetFamilyCode,
                $assetCsvFilename,
                $variationsCsvFilename,
                $referenceType,
                $withCategories,
                $withVariations,
                $convertCategoryToOption,
                $convertTagToOption
            );
        } else {
            $this->splitAndMigrate(
                $assetCsvFilename,
                $variationsCsvFilename,
                $referenceType,
                $withCategories,
                $withVariations,
                $convertCategoryToOption,
                $convertTagToOption
            );
        }

        $this->io->success('Migration success!');
    }

    private function executeCommand($name, $arguments)
    {
        $process = new Process(
            array_merge(['bin/console', $name, '--ansi'], $arguments),
            null,
            null,
            null,
            null
        );

        $process->start();
        foreach ($process as $type => $data) {
            $this->io->write($data);
        }

        if ($process->getExitCode() > 0) {
            $this->io->error(sprintf('An error occurred during %s.', self::$defaultName));

            die($process->getExitCode());
        } else {
            $output = $process->getOutput();
            $this->io->write($output);
        }
    }

    private function guessReferenceType(string $assetCsvFilename): string
    {
        try {
            $this->io->writeln('The script will now guess if your assets are localizable, non localizable or both...');
            $assetsReader = $this->getReader($assetCsvFilename);

            $headers = $assetsReader->getHeaders();
            $foundLocalized = false;
            $foundNonLocalized = false;
            foreach ($assetsReader as $assetLineNumber => $row) {
                if ($assetLineNumber === 1) {
                    continue;
                }

                if (!$this->isHeaderValid($assetsReader, $row)) {
                    continue;
                }

                $assetLine = array_combine($headers, $row);
                $localized = $assetLine['localized'];
                if ($localized === '1') {
                    $foundLocalized = true;
                } else {
                    $foundNonLocalized = true;
                }
            }

            if (!$foundLocalized && !$foundNonLocalized) {
                $this->io->error('No assets found. This script can not guess the reference type.');

                exit(1);
            } else if ($foundNonLocalized && $foundLocalized) {
                $this->io->writeln(sprintf('Found localized and non localized assets, set reference type to "%s".', self::BOTH));

                return self::BOTH;
            } else if ($foundLocalized) {
                $this->io->writeln(sprintf('Only found localized assets, set reference type to "%s".', self::LOCALIZABLE));

                return self::LOCALIZABLE;
            } else {
                $this->io->writeln(sprintf('Only found non localized assets, set reference type to "%s".', self::NON_LOCALIZABLE));

                return self::NON_LOCALIZABLE;
            }
        } catch (IOException|UnsupportedTypeException|ReaderNotOpenedException $e) {
            $this->io->error($e->getMessage());

            exit(1);
        }
    }

    private function guessWithCategories(string $assetCsvFilename): string
    {
        try {
            $this->io->writeln('The script will now guess if the categories field need to be imported...');
            $assetsReader = $this->getReader($assetCsvFilename);

            $headers = $assetsReader->getHeaders();
            $categories = [];
            foreach ($assetsReader as $assetLineNumber => $row) {
                if ($assetLineNumber === 1) {
                    continue;
                }

                if (!$this->isHeaderValid($assetsReader, $row)) {
                    continue;
                }

                $assetLine = array_combine($headers, $row);
                $assetCategories = explode(',', $assetLine['categories']);
                $categories = array_unique(array_merge($categories, $assetCategories));

                if (count($categories) > 1) {
                    $this->io->writeln('More than 1 categories was found in the assets file, it will import the categories.');

                    return self::YES;
                }
            }

            $this->io->writeln(sprintf('%d category was found in the assets file, it will not import the categories.', count($categories)));

            return self::NO;
        } catch (IOException|UnsupportedTypeException|ReaderNotOpenedException $e) {
            $this->io->error($e->getMessage());

            exit(1);
        }
    }

    private function isHeaderValid(CsvReader $reader, $row)
    {
        return count($reader->getHeaders()) === count($row);
    }

    private function getReader(string $assetCsvFilename): CsvReader
    {
        if (!isset($this->csvReaders[$assetCsvFilename])) {
            $this->csvReaders[$assetCsvFilename] = new CsvReader(
                $assetCsvFilename, [
                    'fieldDelimiter' => self::CSV_FIELD_DELIMITER,
                    'fieldEnclosure' => self::CSV_FIELD_ENCLOSURE,
                    'endOfLineCharacter' => self::CSV_END_OF_LINE_CHARACTER,
            ]);
        }

        $this->csvReaders[$assetCsvFilename]->rewind();

        return $this->csvReaders[$assetCsvFilename];
    }

    private function getCategoryCodes(string $assetCsvFilename): array
    {
        try {
            $this->io->writeln('The script will now load the categories from your assets file...');
            $assetsReader = $this->getReader($assetCsvFilename);

            $headers = $assetsReader->getHeaders();
            $categoryCodes = [];
            foreach ($assetsReader as $assetLineNumber => $row) {
                if ($assetLineNumber === 1) {
                    continue;
                }

                if (!$this->isHeaderValid($assetsReader, $row)) {
                    continue;
                }

                $assetLine = array_combine($headers, $row);
                $assetCategories = explode(',', $assetLine['categories']);
                $categoryCodes = array_unique(array_merge($categoryCodes, $assetCategories));
            }

            $this->io->writeln(sprintf('%d categories were found in the assets file.', count($categoryCodes)));

            return $categoryCodes;
        } catch (IOException|UnsupportedTypeException|ReaderNotOpenedException $e) {
            $this->io->error($e->getMessage());

            exit(1);
        }
    }

    private function getTags(string $assetCsvFilename): array
    {
        try {
            $this->io->writeln('The script will now load the tags from your assets file...');
            $assetsReader = $this->getReader($assetCsvFilename);

            $headers = $assetsReader->getHeaders();
            $tags = [];
            foreach ($assetsReader as $assetLineNumber => $row) {
                if ($assetLineNumber === 1) {
                    continue;
                }

                if (!$this->isHeaderValid($assetsReader, $row)) {
                    continue;
                }

                $assetLine = array_combine($headers, $row);
                $assetTags = explode(',', $assetLine['tags']);
                $tags = array_unique(array_merge($tags, $assetTags));
                if (\count($tags) > self::TAG_LIMIT) {
                    break;
                }
            }

            $this->io->writeln(sprintf('%d tags were found in the assets file.', count($tags)));

            return $tags;
        } catch (IOException|UnsupportedTypeException|ReaderNotOpenedException $e) {
            $this->io->error($e->getMessage());

            exit(1);
        }
    }

    private function migrate(
        string $assetFamilyCode,
        string $assetCsvFilename,
        string $variationsCsvFilename,
        string $referenceType,
        string $withCategories,
        string $withVariations,
        string $convertCategoryToOption,
        string $convertTagToOption
    ) {
        $this->io->title(sprintf('Migration of the family "%s"', $assetFamilyCode));

        if ($referenceType === self::AUTO) {
            $referenceType = $this->guessReferenceType($assetCsvFilename);
            $this->io->newLine();
        }

        if ($withCategories === self::AUTO) {
            $withCategories = $this->guessWithCategories($assetCsvFilename);
            $this->io->newLine();
        }

        if ($withCategories === self::YES &&
            ($convertCategoryToOption === self::AUTO || $convertCategoryToOption === self::YES)
        ) {
            $categoryCodes = $this->getCategoryCodes($assetCsvFilename);
            if ($convertCategoryToOption === self::AUTO) {
                if (count($categoryCodes) > self::CATEGORY_LIMIT) {
                    $this->io->writeln(sprintf('More than %s categories were found in the assets file, it will import the categories as text.', self::CATEGORY_LIMIT));
                    $convertCategoryToOption = self::NO;
                } else {
                    $this->io->writeln(sprintf('Less than %s categories were found in the assets file, it will import the categories as multiple options.', self::CATEGORY_LIMIT));
                    $convertCategoryToOption = self::YES;
                }
                $this->io->newLine();
            }
        }

        if ($convertTagToOption === self::AUTO || $convertCategoryToOption === self::YES) {
            $tags = $this->getTags($assetCsvFilename);
            if ($convertTagToOption === self::AUTO) {
                if (count($tags) > self::TAG_LIMIT) {
                    $this->io->writeln(sprintf('More than %s tags were found in the assets file, it will import the tags as text.', self::TAG_LIMIT));
                    $convertTagToOption = self::NO;
                } else {
                    $this->io->writeln(sprintf('Less than %s tags were found in the assets file, it will import the tags as multiple options.', self::TAG_LIMIT));
                    $convertTagToOption = self::YES;
                }
                $this->io->newLine();
            }
        }

        $tmpfname = tempnam('./', 'migration_target_') . '.csv';

        $arguments = [
            $assetFamilyCode,
            sprintf('--reference-type=%s', $referenceType),
            sprintf('--with-categories=%s', $withCategories),
            sprintf('--with-variations=%s', $withVariations),
        ];
        if ($convertCategoryToOption === self::YES) {
            $arguments[] = sprintf('--category-options=%s', join(',', $categoryCodes));
        }
        if ($convertTagToOption === self::YES) {
            $arguments[] = sprintf('--tag-options=%s', join(',', $tags));
        }

        $this->executeCommand('app:create-family', $arguments);
        $this->executeCommand('app:merge-files', [
            $assetCsvFilename,
            $variationsCsvFilename,
            $tmpfname,
            sprintf('--reference-type=%s', $referenceType),
            sprintf('--with-categories=%s', $withCategories),
            sprintf('--with-variations=%s', $withVariations),
        ]);

        // Split big file and import one by one to avoid memory leaks
        $filesToImport = $this->splitFilesToImportBy50K($tmpfname);
        foreach ($filesToImport as $i => $fileToImport) {
            $this->io->title(sprintf('Importing asset file %d/%d', $i, \count($filesToImport)));
            $this->executeCommand('app:import', [$fileToImport, $assetFamilyCode, '-vvv']);
        }
        $this->executeCommand('app:import', [$tmpfname, $assetFamilyCode]);

        $this->io->success(sprintf("Family %s successfully imported", $assetFamilyCode));
    }

    private function splitAndMigrate(
        string $assetCsvFilename,
        string $variationsCsvFilename,
        string $referenceType,
        string $withCategories,
        string $withVariations,
        string $convertCategoryToOption,
        string $convertTagToOption
    ) {
        $assetFamilyCodes = $this->splitAndFill($assetCsvFilename);

        foreach ($assetFamilyCodes as $assetFamilyCode) {
            $filename = $this->getFilename($assetCsvFilename, $assetFamilyCode);
            $this->migrate(
                $assetFamilyCode,
                $filename,
                $variationsCsvFilename,
                $referenceType,
                $withCategories,
                $withVariations,
                $convertCategoryToOption,
                $convertTagToOption
            );

            $this->io->writeln(sprintf('Deletion of the temporary file "%s"...', $filename));
            unlink($filename);
        }
    }

    private function splitAndFill(string $assetCsvFilename): array {
        $files = [];
        try {
            $this->io->writeln(sprintf('You did not specify "asset-family-code" argument. The script will now split your "%s" file into several files to migrate them.', $assetCsvFilename));
            $assetsReader = $this->getReader($assetCsvFilename);

            $headers = $assetsReader->getHeaders();
            foreach ($assetsReader as $assetLineNumber => $row) {
                if ($assetLineNumber === 1) {
                    continue;
                }

                if (!$this->isHeaderValid($assetsReader, $row)) {
                    continue;
                }

                $assetLine = array_combine($headers, $row);
                $assetFamilyCode = isset($assetLine['family']) ? $assetLine['family'] : null;
                if (empty($assetFamilyCode)) {
                    throw new \RuntimeException(sprintf('The line %d of "%s" does not contain a valid family. You need to fill a column "family" or use the command option "asset-family-code"', $assetLineNumber, $assetCsvFilename));
                }

                $filename = $this->getFilename($assetCsvFilename, $assetFamilyCode);

                if (!isset($files[$assetFamilyCode])) {
                    $this->io->writeln(sprintf('Found a new family code, creation of a file "%s"...', $filename));
                    $files[$assetFamilyCode] = fopen($filename, 'w');
                    fputcsv($files[$assetFamilyCode], $headers, ';');
                }

                fputcsv($files[$assetFamilyCode], $row, ';');
            }
        } catch (IOException|UnsupportedTypeException|ReaderNotOpenedException $e) {
            $this->io->error($e->getMessage());

            exit(1);
        } finally {
            foreach ($files as $assetFamilyCode => $file) {
                fclose($file);
            }
        }

        return array_keys($files);
    }

    private function getFilename(string $assetCsvFilename, $assetFamilyCode)
    {
        return dirname($assetCsvFilename) . DIRECTORY_SEPARATOR . pathinfo($assetCsvFilename, PATHINFO_EXTENSION) . '_' . $assetFamilyCode . '.csv';
    }

    private function splitFilesToImportBy50K(string $fileToBeSplit): array
    {
        $targetFile = sprintf('%s_', $fileToBeSplit);
        exec(sprintf('head -n 1 %s', $fileToBeSplit), $output);
        $headers = current($output);
        exec(sprintf('rm -f %s*', $targetFile));
        exec(sprintf('split -l 50000 %s %s', $fileToBeSplit, $targetFile));
        exec(sprintf('ls %s*', $targetFile), $filesToBeImported);

        // Add headers to each file (except the first one)
        $filesToAddHeadersTo = $filesToBeImported;
        array_shift($filesToAddHeadersTo);
        foreach ($filesToAddHeadersTo as $fileToAddHeadersTo) {
            exec(sprintf('echo "%s" > tmpFile', $headers));
            exec(sprintf('cat %s >> tmpFile', $fileToAddHeadersTo));
            exec(sprintf('mv tmpFile %s', $fileToAddHeadersTo));
        }

        return $filesToBeImported;
    }
}
