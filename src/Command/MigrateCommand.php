<?php

declare(strict_types=1);

/*
 * This file is part of the Akeneo PIM Enterprise Edition.
 *
 * (c) 2020 Akeneo SAS (http://www.akeneo.com)
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

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

    private const CSV_FIELD_DELIMITER = ';';
    private const CSV_FIELD_ENCLOSURE = '"';
    private const CSV_END_OF_LINE_CHARACTER = "\n";

    /** @var string */
    private $assetFamilyCode;

    /** @var SymfonyStyle */
    private $io;

    public function __construct(
        CreateFamilyCommand $createFamilyCommand,
        MergeAssetsAndVariationsFilesCommand $mergeAssetsAndVariationsFilesCommand
    ) {
        parent::__construct($this::$defaultName);
    }

    protected function configure()
    {
        $this
            ->setDescription('Migrate a complete family')
            ->addArgument('asset-family-code', InputArgument::REQUIRED, 'The asset family code to migrate')
            ->addArgument('assets-csv-filename', InputArgument::OPTIONAL, 'The path to the Assets CSV file', self::ASSETS_CSV_FILENAME)
            ->addArgument('variations-csv-filename', InputArgument::OPTIONAL, 'The path to the Variations CSV file', self::VARIATIONS_CSV_FILENAME)
            ->addOption('reference-type', null, InputOption::VALUE_OPTIONAL,
                sprintf(
                    'Enable if reference is localizable or not. Allowed values: %s|%s|%s|%s',
                    self::LOCALIZABLE,
                    self::NON_LOCALIZABLE,
                    self::BOTH,
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
        if (!in_array($referenceType, [self::LOCALIZABLE, self::NON_LOCALIZABLE, self::BOTH, self::AUTO])) {
            throw new \InvalidArgumentException(sprintf(
                'Argument "reference-type" should be "%s", "%s", "%s" or "%s".',
                self::LOCALIZABLE,
                self::NON_LOCALIZABLE,
                self::BOTH,
                self::AUTO
            ));
        }

        if ($referenceType === self::AUTO) {
            $referenceType = $this->guessReferenceType($assetCsvFilename);
        }

        $tmpfname = tempnam('/tmp', 'migration_target_');

        $this->executeCommand('app:create-family', [$this->assetFamilyCode, sprintf('--reference-type=%s', $referenceType)]);
        $this->executeCommand('app:merge-files', [$assetCsvFilename, $variationsCsvFilename, $tmpfname, sprintf('--reference-type=%s', $referenceType)]);
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
            $this->io->error('An error occured during migration');
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
            $this->io->writeln("The script will now guess if your assets are localizable, non localizable or both...");
            $assetsReader = new CsvReader(
                $assetCsvFilename, [
                    'fieldDelimiter' => self::CSV_FIELD_DELIMITER,
                    'fieldEnclosure' => self::CSV_FIELD_ENCLOSURE,
                    'endOfLineCharacter' => self::CSV_END_OF_LINE_CHARACTER,
                ]
            );

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
                $this->io->writeln(sprintf("Localized and non localized assets found, set reference type to %s.", self::BOTH));
                return self::BOTH;
            } else if ($foundLocalized) {
                $this->io->writeln(sprintf("Only localized assets found, set reference type to %s.", self::LOCALIZABLE));
                return self::LOCALIZABLE;
            } else {
                $this->io->writeln(sprintf("Only non localized assets found, set reference type to %s.", self::NON_LOCALIZABLE));
                return self::NON_LOCALIZABLE;
            }
        } catch (IOException|UnsupportedTypeException|ReaderNotOpenedException $e) {
            $this->io->error($e->getMessage());

            exit(1);
        }
    }

    private function isHeaderValid(CsvReader $reader, $row)
    {
        return count($reader->getHeaders()) === count($row);
    }
}
