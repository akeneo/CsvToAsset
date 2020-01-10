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
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * @author Pierre Allard <pierre.allard@akeneo.com>
 */
class MigrateCommand extends Command
{
    protected static $defaultName = 'app:migrate';

    /** @var CreateFamilyCommand */
    private $createFamilyCommand;

    /** @var string */
    private $assetFamilyCode;

    /** @var SymfonyStyle */
    private $io;

    public function __construct(CreateFamilyCommand $createFamilyCommand)
    {
        parent::__construct($this::$defaultName);

        $this->createFamilyCommand = $createFamilyCommand;
    }

    protected function configure()
    {
        $this
            ->setDescription('Migrate TODO')
            ->addArgument('assetFamilyCode', InputArgument::REQUIRED, 'The asset family code to migrate')
        ;
    }

    public function execute(InputInterface $input, OutputInterface $output)
    {
        $this->io = new SymfonyStyle($input, $output);
        $this->assetFamilyCode = $input->getArgument('assetFamilyCode');

        $this->createFamilyCommand->execute($input, $output);

        $this->io->success('TODO To implement!');
    }
}
