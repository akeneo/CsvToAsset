#!/usr/bin/env php
<?php

/**
*For PAAS & on-premise, there is a php file:*

- in the PIM : Run 'export-pam-assets' and put them int tmp folder
- in the PIM : Run 'create-api-credentials' and store them
- Use the CSVToAsset tool (and the API credentials + CSV files) to run "make migration" and a default asset_family_code
    - In the CSVToAsset: run create asset family
    - In the CSVToAsset: run merge 2 CSV files in 1
    - In the CSVToAsset: run import assets into the PIM through API
- In the PIM : run 'migrate-pam-attributes' command
*/

use Symfony\Component\Console\Exception\RuntimeException;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Process\Process;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Output\ConsoleOutput;
use App\Reader\CredentialReader;

set_time_limit(0);

require dirname(__DIR__).'/vendor/autoload.php';
$io = new SymfonyStyle(new ArgvInput(), new ConsoleOutput());

$inputDefinition = new InputDefinition([
    new InputArgument('asset_family_code', InputArgument::REQUIRED),
    new InputArgument('pim_path', InputArgument::REQUIRED),
    new InputOption('--help', '-h', InputOption::VALUE_NONE, 'Display this help message'),
]);

if (count(array_intersect($_SERVER['argv'], ['-h', '--help']))) {
    $io->writeln(sprintf("<comment>Description:</comment>
  Migrate an asset family in full-automatic scenario

<comment>Usage:</comment>
  %s

<comment>Arguments:</comment>
  <info>asset_family_code</info>  The asset family code to create
  <info>pim_path</info>           The path to your PIM Enterprise Edition installation'

<comment>Options:</comment>
  <info>-h, --help</info>         Display this help message
",
        $_SERVER['argv'][0] . ' ' . $inputDefinition->getSynopsis(true)));

    die(0);
};

try {
    $input = new ArgvInput(null, $inputDefinition);
    $input->validate();
} catch (RuntimeException $e) {
    $io->error($e->getMessage());
    $io->writeln('<info>' . $_SERVER['argv'][0] . ' ' . $inputDefinition->getSynopsis(true) . '</>');
    $io->newLine();

    exit(1);
}

$assetFamilyCode = $input->getArgument('asset_family_code');
$pimPath = $input->getArgument('pim_path');

require dirname(__DIR__).'/config/bootstrap.php';

function executeCommand($arguments, $path, $callback)
{
    $io = new SymfonyStyle(new ArgvInput(), new ConsoleOutput());
    if (!empty($path) && !is_dir($path)) {
        $io->error(sprintf('The pim folder "%s" is not accessible.', $path));

        die(1);
    }

    $process = new Process($arguments, $path, null, null, null);
    $process->start();
    $output = '';
    foreach ($process as $type => $data) {
        $io->write($data);
        $output = $output . $data;
    }

    if ($process->getExitCode() > 0) {
        $io->error('An error occurred during migration.');

        die($process->getExitCode());
    } else {
        $callback($output);
    }
}

executeCommand(
    ['bin/console', 'pimee:migrate-pam-assets:export-assets', '--ansi', sprintf('--env=%s', $_ENV['APP_ENV']), '/tmp'],
    $pimPath,
    function ($output) { }
);


const CLIENT_LABEL = 'migrations_pam';

$credentials = CredentialReader::read();
if (null === $credentials) {
    $io->title(sprintf('Creation of the credentials to connect through the API (label: %s)', CLIENT_LABEL));
    executeCommand(
        ['bin/console', 'akeneo:connectivity-connection:create', '--ansi', CLIENT_LABEL],
        $pimPath,
        function ($output) use ($io) {
            $outputLines = preg_split("/\n/", $output);
            $credentials['clientId'] = preg_split('/: /', $outputLines[2])[1];
            $credentials['secret'] = preg_split('/: /', $outputLines[3])[1];
            $credentials['username'] = preg_split('/: /', $outputLines[4])[1];
            $credentials['password'] = preg_split('/: /', $outputLines[5])[1];

            CredentialReader::write(
                $credentials['clientId'],
                $credentials['secret'],
                $credentials['username'],
                $credentials['password']
            );

            $io->success('Credentials created and stored in ./credientials');
        }
    );

    $credentials = CredentialReader::read();
} else {
    $io->success(sprintf('Credentials already existing in file "%s".', CredentialReader::FILENAME));
}

executeCommand(
    ['bin/console', 'app:migrate', '--ansi', sprintf('--env=%s', $_ENV['APP_ENV']), '/tmp/assets.csv', '/tmp/variations.csv',  $pimPath, sprintf('--asset_family_code=%s', $assetFamilyCode)],
    null,
    function ($output) { }
);

$io->title('Update the PAM attributes to Asset Manager attributes');
$io->writeln('This command will update the type of the existing attributes in your PIM instance to match the new Asset Manager type.');

executeCommand(
    ['bin/console', 'pimee:assets:migrate:migrate-pam-attributes', '--ansi', sprintf('--env=%s', $_ENV['APP_ENV']), $assetFamilyCode],
    $pimPath,
    function ($output) { }
);

$io->title('Warning!');
$io->writeln(sprintf("<comment>Don't forget to remove the API credential file located in the file \"%s\".\nIt contains sensitive data to connect to your PIM instance.", CredentialReader::FILENAME));

$io->success('All your assets were fully migrated!');
