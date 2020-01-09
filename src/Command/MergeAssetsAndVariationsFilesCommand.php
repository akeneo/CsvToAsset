<?php

declare(strict_types=1);

namespace App\Command;

use App\Reader\CsvReader;
use Box\Spout\Common\Exception\IOException;
use Box\Spout\Common\Exception\UnsupportedTypeException;
use Box\Spout\Common\Type;
use Box\Spout\Reader\Exception\ReaderNotOpenedException;
use Box\Spout\Writer\CSV\Writer;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Box\Spout\Writer\WriterFactory;

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

    /** @var SymfonyStyle */
    private $io;

    /** @var CsvReader */
    private $assetsReader;

    /** @var CsvReader */
    private $variationsReader;

    /** @var string[] */
    private $channels = [];

    /** @var string[] */
    private $locales = [];

    protected function configure()
    {
        $this
            ->setDescription('Merge Assets & Variations CSV files into one')
            ->addArgument('assetsFilePath', InputArgument::REQUIRED, 'Path to the Assets CSV file')
            ->addArgument('variationsFilePath', InputArgument::REQUIRED, 'Path to the Variations CSV file path')
            ->addArgument('targetFilePath', InputArgument::REQUIRED, 'The filePath to the new CSV file to create.')
        ;
    }

    public function execute(InputInterface $input, OutputInterface $output)
    {
        $this->io = new SymfonyStyle($input, $output);

        $assetsFilePath = $input->getArgument('assetsFilePath');
        $variationsFilePath = $input->getArgument('variationsFilePath');
        $targetFilePath = $input->getArgument('targetFilePath');

        $this->io->title('Merge PAM Assets CSV file with PAM Variation CSV file');
        $this->io->text(
            sprintf('This command will merge a given PAM Asset CSV file with a given Variations CSV file into one single file: "%s"', $targetFilePath)
        );

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

        $this->io->text('Now merging files and create new Assets...');
        $this->retrieveChannelsAndLocales();
        $this->mergeFiles($output, $targetFilePath);
        $this->io->success(sprintf('%s assets created in "%s"', $this->assetsReader->count(), $targetFilePath));
    }

    private function mergeFiles(OutputInterface $output, string $targetFilePath)
    {
        $progressBar = new ProgressBar($output, $this->assetsReader->count());
        $progressBar->start();

        /** @var Writer $writer */
        $writer = WriterFactory::create(Type::CSV);
        $writer->setFieldDelimiter(self::CSV_FIELD_DELIMITER);
        $writer->setFieldEnclosure(self::CSV_FIELD_ENCLOSURE);
        $writer->openToFile($targetFilePath);
        $writer->addRow($this->getAssetManagerFileHeaders());

        foreach ($this->assetsReader as $assetLineNumber => $row) {
            if ($assetLineNumber === 1) {
                continue;
            }

            if (!$this->isHeaderValid($this->assetsReader, $row)) {
                continue;
            }

            $assetLine = array_combine($this->assetsReader->getHeaders(), $row);

            $variationsForThisAsset = [];
            foreach ($this->variationsReader as $variationLineNumber => $variationRow) {
                if ($variationLineNumber === 1) {
                    continue;
                }

                if (!$this->isHeaderValid($this->variationsReader, $variationRow)) {
                    continue;
                }

                $variationLine = array_combine($this->variationsReader->getHeaders(), $variationRow);

                if ($variationLine['asset'] === $assetLine['code']) {
                    $variationsForThisAsset[] = $variationLine;
                }
            }

            $newAsset = $this->mergeAssetAndVariations($assetLine, $variationsForThisAsset);

            $writer->addRow($newAsset);
            $progressBar->advance();
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
        $valuesHeaders = [];
        foreach ($this->channels as $channel) {
            $valuesHeaders[] = sprintf('reference_file-%s', $channel);
            $valuesHeaders[] = sprintf('variation_file-%s', $channel);

            foreach ($this->locales as $locale) {
                $valuesHeaders[] = sprintf('reference_file-%s-%s', $channel, $locale);
                $valuesHeaders[] = sprintf('variation_file-%s-%s', $channel, $locale);
            }
        }

        return array_merge($assetHeaders, $valuesHeaders);
    }

    /**
     * Retrieve Channels & Locales we'll need to write the new Assets values for.
     */
    private function retrieveChannelsAndLocales(): void
    {
        foreach ($this->variationsReader as $variationLineNumber => $variationRow) {
            if ($variationLineNumber === 1) {
                continue;
            }

            if (!$this->isHeaderValid($this->variationsReader, $variationRow)) {
                continue;
            }

            $variationLine = array_combine($this->variationsReader->getHeaders(), $variationRow);

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
        $structure = $this->getAssetManagerFileHeaders();
        $structure = array_flip($structure);
        
        foreach ($variations as $variation) {
            if (!empty($variation['locale'])) {
                $structure[sprintf('reference_file-%s-%s', $variation['channel'], $variation['locale'])] = $variation['reference_file'];
                $structure[sprintf('variation_file-%s-%s', $variation['channel'], $variation['locale'])] = $variation['variation_file'];
            } else {
                $structure[sprintf('reference_file-%s', $variation['channel'])] = $variation['reference_file'];
                $structure[sprintf('variation_file-%s', $variation['channel'])] = $variation['variation_file'];
            }
        }

        return array_merge($oldAsset, $structure);
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
}
