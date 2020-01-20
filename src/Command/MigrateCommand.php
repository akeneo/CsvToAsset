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

    /** @var string */
    private $assetFamilyCode;

    /** @var SymfonyStyle */
    private $io;

    /** @var CsvReader */
    private $csvReader;

    public function __construct(
        CreateFamilyCommand $createFamilyCommand,
        MergeAssetsAndVariationsFilesCommand $mergeAssetsAndVariationsFilesCommand
    ) {
        parent::__construct($this::$defaultName);
    }

    protected function configure()
    {
        $this
            ->setDescription("Migrate a complete family
    If you only have non localizable assets, it will create and migrate an asset family with a non localizable reference and non localizable variations.
    If you only have localizable assets, it will create and migrate an asset family with localizable reference and localizable variations.
    If you have localizable and non localizable assets, it will create an asset family with both fields.")
            ->addArgument('asset-family-code', InputArgument::REQUIRED, 'The asset family code to migrate')
            ->addArgument('assets-csv-filename', InputArgument::OPTIONAL, 'The path to the Assets CSV file', self::ASSETS_CSV_FILENAME)
            ->addArgument('variations-csv-filename', InputArgument::OPTIONAL, 'The path to the Variations CSV file', self::VARIATIONS_CSV_FILENAME)
            ->addOption('reference-type', null, InputOption::VALUE_OPTIONAL,
                sprintf(
                    'Enable if reference is localizable or not. 
When set to "%s", it will guess the value from the assets file content.
Allowed values: %s|%s|%s|%s',
                    self::LOCALIZABLE,
                    self::NON_LOCALIZABLE,
                    self::BOTH,
                    self::AUTO,
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
        ;
    }

    public function execute(InputInterface $input, OutputInterface $output)
    {
        $this->io = new SymfonyStyle($input, $output);
        $this->assetFamilyCode = $input->getArgument('asset-family-code');

        $assetCsvFilename = $input->getArgument('assets-csv-filename');
        $variationsCsvFilename = $input->getArgument('variations-csv-filename');

        $referenceType = $input->getOption('reference-type');
        ArgumentChecker::assertOptionIsAllowed($referenceType, 'reference-type', [self::LOCALIZABLE, self::NON_LOCALIZABLE, self::BOTH, self::AUTO]);

        $withCategories = $input->getOption('with-categories');
        ArgumentChecker::assertOptionIsAllowed($withCategories, 'with-categories', [self::YES, self::NO, self::AUTO]);

        $convertCategoryToOption = $input->getOption('convert-category-to-option');
        ArgumentChecker::assertOptionIsAllowed($convertCategoryToOption, 'convert-category-to-option', [self::YES, self::NO, self::AUTO]);

        if ($referenceType === self::AUTO) {
            $referenceType = $this->guessReferenceType($assetCsvFilename);
        }

        if ($withCategories === self::AUTO) {
            $withCategories = $this->guessWithCategories($assetCsvFilename);
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
            }
        }

        $tmpfname = tempnam('/tmp', 'migration_target_');

        $arguments = [
            $this->assetFamilyCode,
            sprintf('--reference-type=%s', $referenceType),
            sprintf('--with-categories=%s', $withCategories),
        ];
        if ($convertCategoryToOption === self::YES) {
            $arguments[] = sprintf('--category-options=%s', join(',', $categoryCodes));
        }
        $this->executeCommand('app:create-family', $arguments);
        $this->executeCommand('app:merge-files', [
            $assetCsvFilename,
            $variationsCsvFilename,
            $tmpfname,
            sprintf('--reference-type=%s', $referenceType),
            sprintf('--with-categories=%s', $withCategories)
        ]);
        $this->executeCommand('app:import', [$tmpfname, $this->assetFamilyCode]);

        $this->io->success('Migration success!');
    }

    private function executeCommand($name, $arguments)
    {
        $process = new Process(
            array_merge(['bin/console', $name], $arguments)
        );

        $process->run();
        if ($process->getExitCode() > 0) {
            $this->io->error('An error occurred during migration');
            if ($process->getErrorOutput() !== '') {
                $this->io->error($process->getErrorOutput());
            }
            $this->io->warning($process->getOutput());

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
        if ($this->csvReader === null) {
            $this->csvReader = new CsvReader(
                $assetCsvFilename, [
                    'fieldDelimiter' => self::CSV_FIELD_DELIMITER,
                    'fieldEnclosure' => self::CSV_FIELD_ENCLOSURE,
                    'endOfLineCharacter' => self::CSV_END_OF_LINE_CHARACTER,
            ]);
        } else {
            $this->csvReader->rewind();
        }

        return $this->csvReader;
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
                $categoryCodes = array_merge($categoryCodes, $assetCategories);
            }

            $categoryCodes = array_unique($categoryCodes);
            $this->io->writeln(sprintf('%d categories were found in the assets file.', count($categoryCodes)));

            return $categoryCodes;
        } catch (IOException|UnsupportedTypeException|ReaderNotOpenedException $e) {
            $this->io->error($e->getMessage());

            exit(1);
        }
    }
}
