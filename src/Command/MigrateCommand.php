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

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\StringInput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Process\Process;

/**
 * @author Pierre Allard <pierre.allard@akeneo.com>
 */
class MigrateCommand extends Command
{
    protected static $defaultName = 'app:migrate';

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
            ->addArgument('assetFamilyCode', InputArgument::REQUIRED, 'The asset family code to migrate')
        ;
    }

    public function execute(InputInterface $input, OutputInterface $output)
    {
        $this->io = new SymfonyStyle($input, $output);
        $this->assetFamilyCode = $input->getArgument('assetFamilyCode');

        $tmpfname = tempnam('/tmp', 'migration_target_');

        $this->executeCommand('app:create-family', [$this->assetFamilyCode]);
        $this->executeCommand('app:merge-files', ['/tmp/assets.csv', '/tmp/variations.csv', $tmpfname]);
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
}
