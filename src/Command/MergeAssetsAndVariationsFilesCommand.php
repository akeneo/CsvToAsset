<?php

declare(strict_types=1);

namespace App\Command;

use App\FieldNameProvider;
use App\Reader\CsvReader;
use Box\Spout\Common\Exception\IOException;
use Box\Spout\Common\Exception\UnsupportedTypeException;
use Box\Spout\Common\Type;
use Box\Spout\Reader\Exception\ReaderNotOpenedException;
use Box\Spout\Writer\CSV\Writer;
use Box\Spout\Writer\WriterFactory;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * @author    Adrien Pétremann <adrien.petremann@akeneo.com>
 * @copyright 2020 Akeneo SAS (https://www.akeneo.com)
 * @license   http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */
class MergeAssetsAndVariationsFilesCommand extends Command
{
    protected static $defaultName = 'app:merge-files';

    private const CSV_FIELD_DELIMITER = ';';
    private const CSV_FIELD_ENCLOSURE = '"';
    private const CSV_END_OF_LINE_CHARACTER = "\n";

    private const LOCALIZABLE = 'localizable';
    private const NON_LOCALIZABLE = 'non-localizable';
    private const BOTH = 'both';

    private const YES = 'yes';
    private const NO = 'no';

    /** @var SymfonyStyle */
    private $io;

    /** @var CsvReader */
    private $assetsReader;

    /** @var CsvReader */
    private $variationsReader;

    /** @var array */
    private $indexedVariations = [];

    /** @var string */
    private $variationsFilePath;

    /** @var string[] */
    private $channels = [];

    /** @var string[] */
    private $locales = [];

    /** @var string */
    private $referenceType;

    /** @var string */
    private $withCategories;

    /** @var string */
    private $withVariations;

    /** @var string */
    private $withEndOfUse;

    /** @var FieldNameProvider */
    private $fieldNameProvider;

    protected function configure()
    {
        $this
            ->setDescription('Merge Assets & Variations CSV files into one')
            ->addArgument('assets_file_path', InputArgument::REQUIRED, 'Path to the Assets CSV file')
            ->addArgument('variations_file_path', InputArgument::REQUIRED, 'Path to the Variations CSV file path')
            ->addArgument('target_file_path', InputArgument::REQUIRED, 'The filePath to the new CSV file to create.')
            ->addOption('reference_type', null, InputOption::VALUE_OPTIONAL,
                sprintf(
                    'Enable if reference is localizable or not. Allowed values: %s|%s|%s.',
                    self::LOCALIZABLE,
                    self::NON_LOCALIZABLE,
                    self::BOTH
                ),
                self::BOTH
            )
            ->addOption('with_categories', null, InputOption::VALUE_OPTIONAL,
                sprintf('Add a categories column to the merged file or not to import it to the generated assets. Allowed values: %s|%s',
                    self::YES,
                    self::NO
                ),
                self::YES
            )
            ->addOption('with_variations', null, InputOption::VALUE_OPTIONAL,
                sprintf('Add the variations to the merged file or not to import it to the generated assets. Allowed values: %s|%s',
                    self::YES,
                    self::NO
                ),
                self::YES
            )
            ->addOption('with_end_of_use', null, InputOption::VALUE_OPTIONAL,
                sprintf('Import the end_of_use data or not. Allowed values: %s|%s',
                    self::YES,
                    self::NO
                ),
                self::YES
            )
            ->addOption('mapping', null, InputOption::VALUE_OPTIONAL, 'Use this file for your fields mapping', null)
        ;
    }

    public function execute(InputInterface $input, OutputInterface $output)
    {
        $this->io = new SymfonyStyle($input, $output);

        $assetsFilePath = $input->getArgument('assets_file_path');
        $variationsFilePath = $input->getArgument('variations_file_path');
        $targetFilePath = $input->getArgument('target_file_path');
        $this->variationsFilePath = $variationsFilePath;

        $this->referenceType = $input->getOption('reference_type');
        ArgumentChecker::assertOptionIsAllowed($this->referenceType, 'reference_type', [self::LOCALIZABLE, self::NON_LOCALIZABLE, self::BOTH]);

        $this->withCategories = $input->getOption('with_categories');
        ArgumentChecker::assertOptionIsAllowed($this->withCategories, 'with_categories', [self::YES, self::NO]);

        $this->withVariations = $input->getOption('with_variations');
        ArgumentChecker::assertOptionIsAllowed($this->withVariations, 'with_variations', [self::YES, self::NO]);

        $this->withEndOfUse = $input->getOption('with_end_of_use');
        ArgumentChecker::assertOptionIsAllowed($this->withEndOfUse, 'with_end_of_use', [self::YES, self::NO]);

        $this->fieldNameProvider = new FieldNameProvider($input->getOption('mapping'));

        $this->io->title('Merge PAM Assets CSV file with PAM Variation CSV file');
        $this->io->text([
            sprintf('This command will merge a given PAM Asset CSV file with a given Variations CSV file into one single file: "%s"', $targetFilePath),
            'This file will be importable via the command "app:import"'
        ]);

        if ($this->withCategories === self::NO) {
            $this->io->text([
                sprintf('This command will remove the categories column of the "%s" file before merging the files.',
                $assetsFilePath)
            ]);
        }

        $hasValidFilePaths = $this->hasValidFilePaths($assetsFilePath, $variationsFilePath);
        if (!$hasValidFilePaths) {
            return 1;
        }

        try {
            $this->assetsReader = new CsvReader(
                $assetsFilePath, [
                    'fieldDelimiter' => self::CSV_FIELD_DELIMITER,
                    'fieldEnclosure' => self::CSV_FIELD_ENCLOSURE,
                    'endOfLineCharacter' => self::CSV_END_OF_LINE_CHARACTER,
                ]
            );

            $this->variationsReader = new CsvReader(
                $variationsFilePath, [
                    'fieldDelimiter' => self::CSV_FIELD_DELIMITER,
                    'fieldEnclosure' => self::CSV_FIELD_ENCLOSURE,
                    'endOfLineCharacter' => self::CSV_END_OF_LINE_CHARACTER,
                ]
            );
        } catch (IOException|UnsupportedTypeException|ReaderNotOpenedException $e) {
            $this->io->error($e->getMessage());

            exit;
        }

        $this->io->text('');
        $this->retrieveChannelsAndLocales();
        $this->mergeFiles($output, $targetFilePath);
        $this->io->newLine();
        $this->io->success(sprintf('%s assets created in "%s"', $this->assetsReader->count(), $targetFilePath));
        $this->io->text('You can now import it directly into your PIM by running the "app:import" command');
    }

    private function mergeFiles(OutputInterface $output, string $targetFilePath)
    {
        $progressBar = new ProgressBar($output, $this->assetsReader->count());

        /** @var Writer $writer */
        $writer = WriterFactory::create(Type::CSV);
        $writer->setFieldDelimiter(self::CSV_FIELD_DELIMITER);
        $writer->setFieldEnclosure(self::CSV_FIELD_ENCLOSURE);
        $writer->openToFile($targetFilePath);
        $writer->addRow($this->getAssetManagerFileHeaders());

        $this->io->text('Indexing variations...');
        $this->indexVariationLinesByAssetCode();

        $this->io->text('Now merging files and create new Assets...');
        $this->io->newLine();

        $progressBar->start();

        $headers = $this->assetsReader->getHeaders();
        foreach ($this->assetsReader as $assetLineNumber => $row) {
            if ($assetLineNumber === 1) {
                continue;
            }

            if (!$this->isHeaderValid($this->assetsReader, $row)) {
                continue;
            }

            $assetLine = array_combine($headers, $row);

            $index = $this->getIndexationKey($assetLine['code']);
            $variationsForThisAsset = isset($this->indexedVariations[$index])
                ? $this->indexedVariations[$index]
                : [];

            $newAsset = $this->mergeAssetAndVariations($assetLine, $variationsForThisAsset);

            $writer->addRow($newAsset);
            if ($assetLineNumber % 100 === 0) {
                $progressBar->advance(100);
            }
        }

        $writer->close();
        $progressBar->finish();
    }

    private function isHeaderValid(CsvReader $reader, $row)
    {
        return count($reader->getHeaders()) === count($row);
    }

    /**
     * The new file Headers are a merge between the Asset CSV file headers and the generated value keys
     * computed from the Variation CSV file headers (attribute-channel-locale)
     */
    private function getAssetManagerFileHeaders(): array
    {
        $assetHeaders = $this->assetsReader->getHeaders();

        if ($this->withCategories === self::NO) {
            // Removes the "categories" field from headers
            $index = array_search(FieldNameProvider::CATEGORIES, $assetHeaders);
            if ($index !== FALSE){
                unset($assetHeaders[$index]);
            }
        }

        if ($this->withEndOfUse === self::NO) {
            // Removes the "end_of_use" field from headers
            $index = array_search(FieldNameProvider::END_OF_USE, $assetHeaders);
            if ($index !== FALSE){
                unset($assetHeaders[$index]);
            }
        }

        $mappedAssetHeaders = [];
        foreach ($assetHeaders as $assetHeader) {
            try {
                $mappedAssetHeaders[] = $this->fieldNameProvider->get($assetHeader);
            } catch (\InvalidArgumentException $e) {
                $mappedAssetHeaders[] = $assetHeader;
            }
        }

        // Reference attributes
        if ($this->referenceType === self::NON_LOCALIZABLE || $this->referenceType === self::BOTH) {
            $valuesHeaders[] = sprintf('%s', $this->fieldNameProvider->get(FieldNameProvider::REFERENCE));
        }
        foreach ($this->locales as $locale) {
            $valuesHeaders[] = sprintf('%s-%s', $this->fieldNameProvider->get(FieldNameProvider::REFERENCE_LOCALIZABLE), $locale);
        }

        // Variation attributes
        foreach ($this->channels as $channel) {
            if ($this->withVariations === self::YES) {
                $valuesHeaders[] = sprintf('%s-%s', $this->fieldNameProvider->get(FieldNameProvider::VARIATION_SCOPABLE), $channel);
            }

            if ($this->referenceType === self::LOCALIZABLE || $this->referenceType === self::BOTH) {
                foreach ($this->locales as $locale) {
                    if ($this->withVariations === self::YES) {
                        $valuesHeaders[] = sprintf('%s-%s-%s', $this->fieldNameProvider->get(FieldNameProvider::VARIATION_LOCALIZABLE_SCOPABLE), $locale, $channel);
                    }
                }
            }
        }

        return array_merge($mappedAssetHeaders, $valuesHeaders);
    }

    /**
     * Retrieve Channels & Locales we'll need to write the new Assets values for.
     */
    private function retrieveChannelsAndLocales(): void
    {
        $this->io->text('Retrieving locales & channels...');
        $headers = $this->variationsReader->getHeaders();

        foreach ($this->variationsReader as $variationLineNumber => $variationRow) {
            if ($variationLineNumber === 1) {
                continue;
            }

            if (!$this->isHeaderValid($this->variationsReader, $variationRow)) {
                continue;
            }

            $variationLine = array_combine($headers, $variationRow);

            if (!in_array($variationLine['channel'], $this->channels)) {
                $this->channels[] = $variationLine['channel'];
            }

            if (!in_array($variationLine['locale'], $this->locales)) {
                $this->locales[] = $variationLine['locale'];
            }
        }

        $this->channels = array_filter($this->channels);
        $this->locales = array_filter($this->locales);
    }

    /**
     * Merge and return a given $oldAsset and its $variations into a new Asset Manager asset ready to import.
     */
    private function mergeAssetAndVariations(array $oldAsset, array $variations): array
    {
        $mappedStructure = $this->getAssetManagerFileHeaders();
        $mappedStructure = array_fill_keys(array_keys(array_flip($mappedStructure)), null);

        foreach ($variations as $variation) {
            if (!empty($variation['locale'])) {
                if ($this->referenceType === self::LOCALIZABLE || $this->referenceType === self::BOTH) {
                    $mappedStructure[sprintf('%s-%s', $this->fieldNameProvider->get(FieldNameProvider::REFERENCE_LOCALIZABLE), $variation['locale'])] = $variation['reference_file'];
                    if ($this->withVariations === self::YES) {
                        $mappedStructure[sprintf('%s-%s-%s', $this->fieldNameProvider->get(FieldNameProvider::VARIATION_LOCALIZABLE_SCOPABLE), $variation['locale'], $variation['channel'])] = $variation['variation_file'];
                    }
                } else {
                    throw new \RuntimeException(sprintf(
                        "The merge script encountered an issue with \"%s\".
                        \nThis line contains a locale (\"%s\") but there is no localizable fields in your asset family.",
                        json_encode($variation),
                        $variation['locale']
                    ));
                }
            } else {
                if ($this->referenceType === self::NON_LOCALIZABLE || $this->referenceType === self::BOTH) {
                    $mappedStructure[sprintf('%s', $this->fieldNameProvider->get(FieldNameProvider::REFERENCE))] = $variation['reference_file'];
                    if ($this->withVariations === self::YES) {
                        $mappedStructure[sprintf('%s-%s', $this->fieldNameProvider->get(FieldNameProvider::VARIATION_SCOPABLE), $variation['channel'])] = $variation['variation_file'];
                    }
                } else {
                    throw new \RuntimeException(
                        sprintf(
                            "The merge script encountered an issue with \"%s\".
                        \nThis line does not contains any value in the locale column, but this value is needed for the asset family.",
                            json_encode($variation)
                        )
                    );
                }
            }
        }

        if ($this->withCategories === self::NO) {
            unset($oldAsset[FieldNameProvider::CATEGORIES]);
        }
        if ($this->withEndOfUse === self::NO) {
            unset($oldAsset[FieldNameProvider::END_OF_USE]);
        }

        $mappedOldAsset = [];
        foreach ($oldAsset as $oldAssetKey => $oldAssetValue) {
            try {
                $mappedOldAsset[$this->fieldNameProvider->get($oldAssetKey)] = $oldAssetValue;
            } catch (\InvalidArgumentException $e) {
                $mappedOldAsset[$oldAssetKey] = $oldAssetValue;
            }
        }

        return array_merge($mappedStructure, $mappedOldAsset);
    }

    /**
     * Check if given filepaths are existing files.
     */
    private function hasValidFilePaths(string $assetsFilePath, string $variationsFilePath): bool
    {
        $hasValidFilePath = true;

        if (!file_exists($assetsFilePath)) {
            $this->io->warning(sprintf('The file "%s" does not exist.', $assetsFilePath));
            $hasValidFilePath = false;
        }

        if (!file_exists($variationsFilePath)) {
            $this->io->warning(sprintf('The file "%s" does not exist.', $variationsFilePath));
            $hasValidFilePath = false;
        }

        return $hasValidFilePath;
    }

    /**
     * Parse the whole variations CSV file and index them by Asset Code:
     * This is to avoid to re-parse the whole variations file for each asset later.
     */
    private function indexVariationLinesByAssetCode(): void
    {
        $headers = $this->variationsReader->getHeaders();

        foreach ($this->variationsReader as $variationLineNumber => $variationRow) {
            $variationLine = array_combine($headers, $variationRow);

            if ($variationLineNumber === 1) {
                continue;
            }

            if (!$this->isHeaderValid($this->variationsReader, $variationRow)) {
                continue;
            }

            $index = $this->getIndexationKey($variationLine['asset']);
            $this->indexedVariations[$index][] = $variationLine;
        }
    }

    private function getIndexationKey($assetCode): string
    {
        return sprintf('ass_%s', $assetCode);
    }
}
