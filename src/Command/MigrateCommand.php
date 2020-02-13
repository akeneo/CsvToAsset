<?php

declare(strict_types=1);

namespace App\Command;

use App\FieldNameProvider;
use App\Reader\CsvReader;
use Box\Spout\Common\Exception\IOException;
use Box\Spout\Common\Exception\UnsupportedTypeException;
use Box\Spout\Reader\Exception\ReaderNotOpenedException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Question\ConfirmationQuestion;
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

    private const CATEGORY_LIMIT = 100;
    private const TAG_LIMIT = 100;

    /** @var SymfonyStyle */
    private $io;

    /** @var CsvReader[] */
    private $csvReaders = [];

    /** @var string */
    private $pimPath;

    /** @var FieldNameProvider */
    private $fieldNameProvider;

    /** @var string[] */
    private $remainingCommands;

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
    You can specify the argument 'asset_family_code' if you want to create only 1 asset family.
    If you don't specify this argument, you will need an extra column called 'asset' in your asset CSV file.
    If you only have non-localizable assets, it will create and migrate an asset family with a non-localizable reference and non-localizable variations.
    If you only have localizable assets, it will create and migrate an asset family with a localizable reference and localizable variations.
    If you have localizable and non-localizable assets, it will create an asset family with both fields.")
            ->addArgument('assets_csv_filename', InputArgument::OPTIONAL, 'The path to the Assets CSV file', self::ASSETS_CSV_FILENAME)
            ->addArgument('variations_csv_filename', InputArgument::OPTIONAL, 'The path to the Variations CSV file', self::VARIATIONS_CSV_FILENAME)
            ->addArgument('pim_path', InputArgument::OPTIONAL, 'The path to your PIM Enterprise Edition installation')
            ->addOption('asset_family_code', null, InputOption::VALUE_OPTIONAL, 'The asset family code to migrate', null)
            ->addOption('reference_type', null, InputOption::VALUE_OPTIONAL,
                sprintf(
                    'Enable if reference is localizable or not. 
When set to "%s", it will guess the value from the asset file content.
Allowed values: %s|%s|%s|%s',
                    self::AUTO,
                    self::LOCALIZABLE,
                    self::NON_LOCALIZABLE,
                    self::BOTH,
                    self::AUTO
                ),
                null
            )
            ->addOption('with_categories', null, InputOption::VALUE_OPTIONAL,
                sprintf('Import the categories from your asset file.
When set to "%s", your new asset family will have a category field, and every asset will contain its former categories.
When set to "%s", it will guess the value from the asset file content.
It will only create the category field  if more than 1 category is found in the asset file.
Allowed values: %s|%s|%s',
                    self::YES,
                    self::AUTO,
                    self::YES,
                    self::NO,
                    self::AUTO
                ),
                null
            )
            ->addOption('with_variations', null, InputOption::VALUE_OPTIONAL,
                sprintf('Add the variations to your new assets
When set to "%s", your new asset family will have variation field(s), and variations will be imported.
Allowed values: %s|%s',
                    self::YES,
                    self::YES,
                    self::NO
                ),
                self::YES
            )
            ->addOption('with_end_of_use', null, InputOption::VALUE_OPTIONAL,
                sprintf('Import the "end-of-use" data from your asset file.
When set to "%s", your new asset family will have a end-of-use field, and every asset will contain its former data.
When set to "%s", it will guess the value from the asset file content.
Allowed values: %s|%s|%s',
                    self::YES,
                    self::AUTO,
                    self::YES,
                    self::NO,
                    self::AUTO
                ),
                null
            )
            ->addOption('convert_category_to_option', null, InputOption::VALUE_OPTIONAL,
                sprintf('Import the categories as "multiple_options".
When set to "%s", your new asset family will have a multiple options category field.
When set to "%s", your new asset family will have a text category field.
When set to "%s", it will guess the attribute type to set from the asset file content.
It will use a multiple option field if you have less than %d different categories in your asset file.
Allowed values: %s|%s|%s',
                    self::YES,
                    self::NO,
                    self::AUTO,
                    self::CATEGORY_LIMIT,
                    self::YES,
                    self::NO,
                    self::AUTO
                ),
                null
            )
            ->addOption('convert_tag_to_option', null, InputOption::VALUE_OPTIONAL,
                sprintf('Import the tags as "multiple_options".
When set to "%s", your new asset family will have a multiple options tag field.
When set to "%s", your new asset family will have a text tag field.
When set to "%s", it will guess the attribute type to set from the asset file content.
It will use a multiple option field if you have less than %d different tags in your asset file.
Allowed values: %s|%s|%s',
                    self::YES,
                    self::NO,
                    self::AUTO,
                    self::TAG_LIMIT,
                    self::YES,
                    self::NO,
                    self::AUTO
                ),
                null
            )
            ->addOption('mapping', null, InputOption::VALUE_OPTIONAL, 'Use this file for your fields mapping', null)
        ;
    }

    public function execute(InputInterface $input, OutputInterface $output)
    {
        $this->remainingCommands = [];
        $this->io = new SymfonyStyle($input, $output);
        $this->io->title('Migration of your assets');

        $assetCsvFilename = $input->getArgument('assets_csv_filename');
        $variationsCsvFilename = $input->getArgument('variations_csv_filename');

        $assetFamilyCode = $input->getOption('asset_family_code');

        $referenceType = $input->getOption('reference_type');
        ArgumentChecker::assertOptionIsAllowed($referenceType, 'reference_type', [self::LOCALIZABLE, self::NON_LOCALIZABLE, self::BOTH, self::AUTO, null]);

        $withCategories = $input->getOption('with_categories');
        ArgumentChecker::assertOptionIsAllowed($withCategories, 'with_categories', [self::YES, self::NO, self::AUTO, null]);

        $withVariations = $input->getOption('with_variations');
        ArgumentChecker::assertOptionIsAllowed($withVariations, 'with_variations', [self::YES, self::NO]);

        $withEndOfUse = $input->getOption('with_end_of_use');
        ArgumentChecker::assertOptionIsAllowed($withEndOfUse, 'with_end_of_use', [self::YES, self::NO, self::AUTO, null]);

        $convertCategoryToOption = $input->getOption('convert_category_to_option');
        ArgumentChecker::assertOptionIsAllowed($convertCategoryToOption, 'convert_category_to_option', [self::YES, self::NO, self::AUTO, null]);

        $convertTagToOption = $input->getOption('convert_tag_to_option');
        ArgumentChecker::assertOptionIsAllowed($convertTagToOption, 'convert_tag_to_option', [self::YES, self::NO, self::AUTO, null]);

        $this->fieldNameProvider = new FieldNameProvider($input->getOption('mapping'));

        $this->pimPath = $input->getArgument('pim_path');

        if (!empty($assetFamilyCode)) {
            $this->migrate(
                $assetFamilyCode,
                $assetCsvFilename,
                $variationsCsvFilename,
                $referenceType,
                $withCategories,
                $withVariations,
                $withEndOfUse,
                $convertCategoryToOption,
                $convertTagToOption,
                $input->getOption('mapping')
            );
        } else {
            $this->splitAndMigrate(
                $assetCsvFilename,
                $variationsCsvFilename,
                $referenceType,
                $withCategories,
                $withVariations,
                $withEndOfUse,
                $convertCategoryToOption,
                $convertTagToOption,
                $input->getOption('mapping')
            );
        }

        if (!empty($this->remainingCommands)) {
            $this->io->warning(sprintf("Warning: as you did not specify pim_path parameter, the category labels were not translated.\nPlease run these commands in your PIM instance:"));
            foreach ($this->remainingCommands as $remainingCommand) {
                $this->io->writeln($remainingCommand);
            }
            $this->io->newLine(2);
        }
    }

    private function executeCommand(string $name, array $arguments, ?string $path = null)
    {
        $process = new Process(
            array_merge(['bin/console', $name, '--ansi'], $arguments),
            $path,
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

    private function guessReferenceType(string $assetCsvFilename): ?string
    {
        try {
            $this->io->write('The command will parse your asset file... ');
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
                $this->io->writeln('No assets found.');

                return null;
            } else if ($foundNonLocalized && $foundLocalized) {
                $this->io->writeln('Found localized and non localized assets.');

                return self::BOTH;
            } else if ($foundLocalized) {
                $this->io->writeln('Found only localized assets.');

                return self::LOCALIZABLE;
            } else {
                $this->io->writeln('Found only non localized assets.');

                return self::NON_LOCALIZABLE;
            }
        } catch (IOException|UnsupportedTypeException|ReaderNotOpenedException $e) {
            $this->io->error($e->getMessage());

            exit(1);
        }
    }

    private function guessWithEndOfUse(string $assetCsvFilename): string
    {
        try {
            $this->io->write('The command will parse your asset file... ');
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
                $endOfUse = $assetLine[FieldNameProvider::END_OF_USE];
                if (!empty(trim($endOfUse))) {
                    $this->io->writeln(sprintf('At least 1 line with %s was found.', FieldNameProvider::END_OF_USE));

                    return self::YES;
                }
            }

            $this->io->writeln(sprintf('No line with %s was found.', FieldNameProvider::END_OF_USE));

            return self::NO;
        } catch (IOException|UnsupportedTypeException|ReaderNotOpenedException $e) {
            $this->io->error($e->getMessage());

            exit(1);
        }
    }

    private function guessWithCategories(string $assetCsvFilename): string
    {
        try {
            $this->io->writeln('The command will parse your asset file...');
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
                $assetCategories = explode(
                    ',',
                    isset($assetLine[FieldNameProvider::CATEGORIES]) ? $assetLine[FieldNameProvider::CATEGORIES] : ''
                );
                $categories = array_unique(array_merge($categories, $assetCategories));

                if (count($categories) > 1) {
                    $this->io->writeln('More than 1 category was found in the asset file, you should import them.');

                    return self::YES;
                }
            }

            $this->io->writeln(sprintf('%d category was found in the asset file, you should not import them.', count($categories)));

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
            $this->io->write('The script will now load the categories from your asset file... ');
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
                $assetCategories = explode(',', $assetLine[FieldNameProvider::CATEGORIES]);
                $categoryCodes = array_unique(array_merge($categoryCodes, $assetCategories));
            }

            $this->io->writeln(sprintf('%d categories found.', count($categoryCodes)));

            return $categoryCodes;
        } catch (IOException|UnsupportedTypeException|ReaderNotOpenedException $e) {
            $this->io->error($e->getMessage());

            exit(1);
        }
    }

    private function getTags(string $assetCsvFilename): array
    {
        try {
            $this->io->write('The script will now load the tags from your asset file... ');
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
                $assetTags = explode(',', $assetLine[FieldNameProvider::TAGS]);
                $tags = array_unique(array_merge($tags, $assetTags));
                if (\count($tags) > self::TAG_LIMIT) {
                    break;
                }
            }

            $this->io->writeln(sprintf('%d tags found.', count($tags)));

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
        ?string $referenceType,
        ?string $withCategories,
        ?string $withVariations,
        ?string $withEndOfUse,
        ?string $convertCategoryToOption,
        ?string $convertTagToOption,
        ?string $mapping
    ) {
        $this->io->title(sprintf('Migration of the family "%s"', $assetFamilyCode));

        if (in_array($referenceType, [self::AUTO, null])) {
            if ($referenceType === null) {
                $this->io->writeln('You did not set the <options=bold>--reference_type</> option.');
                $this->io->writeln(sprintf('If all your assets are localized, choose <options=bold>%s</>', self::LOCALIZABLE));
                $this->io->writeln(sprintf('If all your assets are not localized, choose <options=bold>%s</>', self::NON_LOCALIZABLE));
                $this->io->writeln(sprintf('If you have both localizable and non-localizable assets in this family, choose <options=bold>%s</>', self::BOTH));
                $newReferenceType = $this->guessReferenceType($assetCsvFilename);
                $referenceType = $this->io->askQuestion(new ChoiceQuestion(
                    'Please pick a value for your reference type',
                    [self::LOCALIZABLE, self::NON_LOCALIZABLE, self::BOTH],
                    $newReferenceType
                ));
            } else if ($referenceType === self::AUTO) {
                $referenceType = $this->guessReferenceType($assetCsvFilename);
            }
            $this->io->writeln(sprintf('The command will be run with <options=bold>--reference_type=%s</>', $referenceType));
            $this->io->newLine();
        }

        if (in_array($withCategories, [self::AUTO, null])) {
            if ($withCategories === null) {
                $this->io->writeln('You did not set the <options=bold>--with_categories</> option.');
                $this->io->writeln('Choose if you want to import the categories into your new Asset family.');
                $newWithCategory = $this->guessWithCategories($assetCsvFilename);
                $withCategories = $this->io->askQuestion(new ConfirmationQuestion(
                    'Do you want to import the categories into your new Asset family?',
                    $newWithCategory === self::YES
                )) ? self::YES : self::NO;
            } else if ($withCategories === self::AUTO) {
                $withCategories = $this->guessWithCategories($assetCsvFilename);
            }
            $this->io->writeln(sprintf('The command will be run with <options=bold>--with_categories=%s</>', $withCategories));
            $this->io->newLine();
        }

        if ($withCategories === self::YES && in_array($convertCategoryToOption, [self::AUTO, self::YES, null])) {
            $categoryCodes = $this->getCategoryCodes($assetCsvFilename);
            if (in_array($convertTagToOption, [self::AUTO, null])) {
                if ($convertCategoryToOption === null) {
                    $this->io->writeln('You did not set the <options=bold>--convert_category_to_option</> option.');
                    $this->io->writeln('Choose this option if you want to convert your categories to an option field instead of a text field.');

                    if (count($categoryCodes) > self::CATEGORY_LIMIT) {
                        $this->io->writeln(sprintf('More than %s categories was found in the asset file, you should not convert the categories.', self::CATEGORY_LIMIT));
                        $defaultAnswer = false;
                    } else if ($this->hasUnsupportedCharactersToConvertIntoOptions($categoryCodes)) {
                        $this->io->writeln('We found category codes with unsupported characters to be converted into an option. The categories will be converted into a text attribute');
                        $defaultAnswer = false;
                    } else {
                        $this->io->writeln(sprintf('Less than %s categories were found in the asset file, you should convert the categories in a multiple option field.', self::CATEGORY_LIMIT));
                        $defaultAnswer = true;
                    }
                    $convertCategoryToOption = $this->io->askQuestion(new ConfirmationQuestion(
                        'Do you want to convert the categories in a multiple option field instead of a text field?',
                        $defaultAnswer
                    )) ? self::YES : self::NO;
                } else if ($convertCategoryToOption === self::AUTO) {
                    $convertCategoryToOption = count($categoryCodes) > self::CATEGORY_LIMIT || $this->hasUnsupportedCharactersToConvertIntoOptions($categoryCodes)
                        ? self::NO : self::YES;
                }
                $this->io->writeln(sprintf('The command will be run with <options=bold>--convert_category_to_option=%s</>', $convertCategoryToOption));
                $this->io->newLine();
            }
        }

        if (in_array($convertTagToOption, [self::AUTO, self::YES, null])) {
            $tags = $this->getTags($assetCsvFilename);
            if (in_array($convertTagToOption, [self::AUTO, null])) {
                if ($convertTagToOption === null) {
                    $this->io->writeln('You did not set the <options=bold>--convert_tag_to_option</> option.');
                    $this->io->writeln('Choose this option if you want to convert your tags to an option field instead of a text field.');

                    if ($this->hasTooManyTagsToBeConvertedIntoMultiOptionAttributes($tags)) {
                        $this->io->writeln(
                            sprintf(
                                'More than %s tags was found in the asset file, you should not convert the tags.',
                                self::TAG_LIMIT
                            )
                        );
                        $defaultAnswer = false;
                    } else if ($this->hasUnsupportedCharactersToConvertIntoOptions($tags)) {
                        $this->io->writeln('We found tag codes with unsupported characters to be converted into an option. The tags will be converted into a text attribute');
                        $defaultAnswer = false;
                    } else {
                        $this->io->writeln(sprintf('Less than %s tags were found in the asset file, you should convert the tags in a multiple option field.', self::TAG_LIMIT));
                        $defaultAnswer = true;
                    }
                    $convertTagToOption = $this->io->askQuestion(new ConfirmationQuestion(
                        'Do you want to convert the tags in a multiple option field instead of a text field?',
                        $defaultAnswer
                    )) ? self::YES : self::NO;
                } else if ($convertTagToOption === self::AUTO) {
                    $convertTagToOption = $this->hasTooManyTagsToBeConvertedIntoMultiOptionAttributes($tags) || $this->hasUnsupportedCharactersToConvertIntoOptions($tags)
                        ? self::NO : self::YES;
                }
                $this->io->writeln(sprintf('The command will be run with <options=bold>--convert_tag_to_option=%s</>', $convertTagToOption));
                $this->io->newLine();
            }
        }

        if (in_array($withEndOfUse, [self::AUTO, null])) {
            if ($withEndOfUse === null) {
                $this->io->writeln('You did not set the <options=bold>--with_end_of_use</> option.');
                $this->io->writeln('Choose if you want to import the end-of-use data into your new Asset family.');
                $newWithEndOfUse = $this->guessWithEndOfUse($assetCsvFilename);
                $withEndOfUse = $this->io->askQuestion(new ConfirmationQuestion(
                    'Do you want to import the end-of-use data into your new Asset family?',
                    $newWithEndOfUse === self::YES
                )) ? self::YES : self::NO;
            } else if ($withEndOfUse === self::AUTO) {
                $withEndOfUse = $this->guessWithEndOfUse($assetCsvFilename);
            }
            $this->io->writeln(sprintf('The command will be run with <options=bold>--with_end_of_use=%s</>', $withEndOfUse));
            $this->io->newLine();
        }

        $tmpfname = tempnam('/tmp/', 'migration_target_') . '.csv';

        $createFamilyArguments = [
            $assetFamilyCode,
            sprintf('--reference_type=%s', $referenceType),
            sprintf('--with_categories=%s', $withCategories),
            sprintf('--with_variations=%s', $withVariations),
            sprintf('--with_end_of_use=%s', $withEndOfUse),
        ];
        if ($convertCategoryToOption === self::YES && !empty($categoryCodes)) {
            $createFamilyArguments[] = sprintf('--category_options=%s', join(',', $categoryCodes));
        }
        if ($convertTagToOption === self::YES && !empty($tags)) {
            $createFamilyArguments[] = sprintf('--tag_options=%s', join(',', $tags));
        }
        if (null !== $mapping) {
            $createFamilyArguments[] = sprintf('--mapping=%s', $mapping);
        }
        $this->executeCommand('app:create-family', $createFamilyArguments);

        $mergeFileArguments = [
            $assetCsvFilename,
            $variationsCsvFilename,
            $tmpfname,
            sprintf('--reference_type=%s', $referenceType),
            sprintf('--with_categories=%s', $withCategories),
            sprintf('--with_variations=%s', $withVariations),
            sprintf('--with_end_of_use=%s', $withEndOfUse),
        ];
        if (null !== $mapping) {
            $mergeFileArguments[] = sprintf('--mapping=%s', $mapping);
        }
        $this->executeCommand('app:merge-files', $mergeFileArguments);

        // Split big file and import one by one to avoid memory leaks
        $filesToImport = $this->splitFilesToImportBy50K($tmpfname);
        foreach ($filesToImport as $i => $fileToImport) {
            $this->io->title(sprintf('Importing asset file %d/%d', $i+1, \count($filesToImport)));
            $this->executeCommand('app:import', [$fileToImport, $assetFamilyCode, '-vvv']);
        }

        if ($withCategories) {
            if (!empty($this->pimPath)) {
                $this->executeCommand('pimee:assets:migrate:migrate-asset-category-labels', [
                    sprintf('--env=%s', $_SERVER['APP_ENV']),
                    $assetFamilyCode,
                    sprintf('--categories-attribute-code=%s', $this->fieldNameProvider->get(FieldNameProvider::CATEGORIES))
                ], $this->pimPath);
            } else {
                $this->remainingCommands[] = sprintf('bin/console pimee:assets:migrate:migrate-asset-category-labels %s %s',
                    $assetFamilyCode,
                    sprintf('--categories-attribute-code=%s', $this->fieldNameProvider->get(FieldNameProvider::CATEGORIES))
                );
            }
        }

        $this->io->success(sprintf("Family %s successfully imported", $assetFamilyCode));
    }

    private function splitAndMigrate(
        string $assetCsvFilename,
        string $variationsCsvFilename,
        ?string $referenceType,
        ?string $withCategories,
        ?string $withVariations,
        ?string $withEndOfUse,
        ?string $convertCategoryToOption,
        ?string $convertTagToOption,
        ?string $mapping
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
                $withEndOfUse,
                $convertCategoryToOption,
                $convertTagToOption,
                $mapping
            );

            $this->io->writeln(sprintf('Deletion of the temporary file "%s"...', $filename));
            unlink($filename);
        }
    }

    private function splitAndFill(string $assetCsvFilename): array {
        $files = [];
        try {
            $this->io->writeln(sprintf('You did not specify the "asset_family_code" argument. The script will now split your "%s" file into several files to migrate them.', $assetCsvFilename));
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
                    throw new \RuntimeException(sprintf('The line %d of "%s" does not contain a valid family. You need to fill a column "family" or use the command option "asset_family_code"', $assetLineNumber, $assetCsvFilename));
                }

                $filename = $this->getFilename($assetCsvFilename, $assetFamilyCode);

                if (!isset($files[$assetFamilyCode])) {
                    $this->io->writeln(sprintf('Found a new family code "%s", creation of a file "%s"...', $assetFamilyCode, $filename));
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

    private function hasTooManyTagsToBeConvertedIntoMultiOptionAttributes(array $tags): bool
    {
        return count($tags) > self::TAG_LIMIT;
    }

    private function hasUnsupportedCharactersToConvertIntoOptions(array $tags): bool
    {
        foreach ($tags as $tag) {
            if (!preg_match('/^[a-zA-Z0-9_]+$/', $tag)) {
                return true;
            }
        }

        return false;
    }
}
