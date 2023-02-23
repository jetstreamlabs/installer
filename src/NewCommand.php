<?php

namespace Serenity\Installer\Console;

use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Process\Process;

class NewCommand extends Command
{
  /**
   * Configure the command options.
   *
   * @return void
   */
  protected function configure()
  {
    $this
        ->setName('new')
        ->setDescription('Create a new Serenity application')
        ->addArgument('name', InputArgument::REQUIRED)
        ->addOption('dev', null, InputOption::VALUE_NONE, 'Installs the latest "development" release')
        ->addOption('pest', null, InputOption::VALUE_NONE, 'Installs the Pest testing framework')
        ->addOption('phpunit', null, InputOption::VALUE_NONE, 'Installs the PHPUnit testing framework')
        ->addOption('force', 'f', InputOption::VALUE_NONE, 'Forces install even if the directory already exists');
  }

  /**
   * Execute the command.
   *
   * @param  \Symfony\Component\Console\Input\InputInterface  $input
   * @param  \Symfony\Component\Console\Output\OutputInterface  $output
   * @return int
   */
  protected function execute(InputInterface $input, OutputInterface $output)
  {
    $output->write(PHP_EOL.'  <fg=magenta>
________                       __________         
__  ___/__________________________(_)_  /_____  __
_____ \_  _ \_  ___/  _ \_  __ \_  /_  __/_  / / /
____/ //  __/  /   /  __/  / / /  / / /_ _  /_/ / 
/____/ \___//_/    \___//_/ /_//_/  \__/ _\__, /  
                                         /____/</>'.PHP_EOL.PHP_EOL);

    sleep(1);

    $name = $input->getArgument('name');

    $directory = $name !== '.' ? getcwd().'/'.$name : '.';

    $version = $this->getVersion($input);

    if (! $input->getOption('force')) {
      $this->verifyApplicationDoesntExist($directory);
    }

    if ($input->getOption('force') && $directory === '.') {
      throw new RuntimeException('Cannot use --force option when using current directory for installation!');
    }

    $composer = $this->findComposer();

    $commands = [
      $composer." create-project serenity/serenity \"$directory\" $version --remove-vcs --prefer-dist",
    ];

    if ($directory != '.' && $input->getOption('force')) {
      if (PHP_OS_FAMILY == 'Windows') {
        array_unshift($commands, "(if exist \"$directory\" rd /s /q \"$directory\")");
      } else {
        array_unshift($commands, "rm -rf \"$directory\"");
      }
    }

    if (PHP_OS_FAMILY != 'Windows') {
      $commands[] = "chmod 755 \"$directory/lotus\"";
    }

    if (($process = $this->runCommands($commands, $input, $output))->isSuccessful()) {
      if ($name !== '.') {
        $this->replaceInFile(
          'APP_DOMAIN=localhost',
          'APP_DOMAIN='.$this->generateAppUrl($name),
          $directory.'/.env'
        );

        $this->replaceInFile(
          'DB_DATABASE=laravel',
          'DB_DATABASE='.str_replace('-', '_', strtolower($name)),
          $directory.'/.env'
        );

        $this->replaceInFile(
          'DB_DATABASE=laravel',
          'DB_DATABASE='.str_replace('-', '_', strtolower($name)),
          $directory.'/.env.example'
        );
      }

      if ($input->getOption('pest')) {
        $this->installPest($directory, $input, $output);
      }

      $output->writeln('  <bg=blue;fg=white> INFO </> Application ready! <options=bold>Build something fast and beautiful.</>'.PHP_EOL);
    }

    return $process->getExitCode();
  }

  /**
   * Return the local machine's default Git branch if set or default to `main`.
   *
   * @return string
   */
  protected function defaultBranch()
  {
    $process = new Process(['git', 'config', '--global', 'init.defaultBranch']);

    $process->run();

    $output = trim($process->getOutput());

    return $process->isSuccessful() && $output ? $output : 'main';
  }

  /**
   * Determine the testing framework for Serenity.
   *
   * @param  \Symfony\Component\Console\Input\InputInterface  $input
   * @param  \Symfony\Component\Console\Output\OutputInterface  $output
   * @return string
   */
  protected function testingFramework(InputInterface $input, OutputInterface $output)
  {
    if ($input->getOption('pest')) {
      return 'pest';
    }

    if ($input->getOption('phpunit')) {
      return 'phpunit';
    }

    $testingFrameworks = [
      'pest',
      'phpunit',
    ];

    $helper = $this->getHelper('question');

    $question = new ChoiceQuestion('Which testing framework do you prefer?', $testingFrameworks);

    $output->write(PHP_EOL);

    return $helper->ask($input, new SymfonyStyle($input, $output), $question);
  }

  /**
   * Install Pest into the application.
   *
   * @param  \Symfony\Component\Console\Input\InputInterface  $input
   * @param  \Symfony\Component\Console\Output\OutputInterface  $output
   * @return void
   */
  protected function installPest(string $directory, InputInterface $input, OutputInterface $output)
  {
    chdir($directory);

    $commands = array_filter([
      $this->findComposer().' remove nunomaduro/collision phpunit/phpunit --dev',
      $this->findComposer().' require nunomaduro/collision:^6.4 pestphp/pest:^1.22 pestphp/pest-plugin-laravel:^1.2 --dev',
      PHP_BINARY.' lotus pest:install --no-interaction',
    ]);

    $this->runCommands($commands, $input, $output);

    $this->replaceFile(
      'pest/Feature.php',
      $directory.'/tests/Feature/ExampleTest.php',
    );

    $this->replaceFile(
      'pest/Unit.php',
      $directory.'/tests/Unit/ExampleTest.php',
    );
  }

  /**
   * Verify that the application does not already exist.
   *
   * @param  string  $directory
   * @return void
   */
  protected function verifyApplicationDoesntExist($directory)
  {
    if ((is_dir($directory) || is_file($directory)) && $directory != getcwd()) {
      throw new RuntimeException('Application already exists!');
    }
  }

  /**
   * Generate a valid APP_URL for the given application name.
   *
   * @param  string  $name
   * @return string
   */
  protected function generateAppUrl($name)
  {
    $hostname = mb_strtolower($name).'.test';

    return $this->canResolveHostname($hostname) ? $hostname : 'localhost';
  }

  /**
   * Determine whether the given hostname is resolvable.
   *
   * @param  string  $hostname
   * @return bool
   */
  protected function canResolveHostname($hostname)
  {
    return gethostbyname($hostname.'.') !== $hostname.'.';
  }

  /**
   * Get the version that should be downloaded.
   *
   * @param  \Symfony\Component\Console\Input\InputInterface  $input
   * @return string
   */
  protected function getVersion(InputInterface $input)
  {
    if ($input->getOption('dev')) {
      return 'dev-master';
    }

    return '';
  }

  /**
   * Get the composer command for the environment.
   *
   * @return string
   */
  protected function findComposer()
  {
    $composerPath = getcwd().'/composer.phar';

    if (file_exists($composerPath)) {
      return '"'.PHP_BINARY.'" '.$composerPath;
    }

    return 'composer';
  }

  /**
   * Run the given commands.
   *
   * @param  array  $commands
   * @param  \Symfony\Component\Console\Input\InputInterface  $input
   * @param  \Symfony\Component\Console\Output\OutputInterface  $output
   * @param  array  $env
   * @return \Symfony\Component\Process\Process
   */
  protected function runCommands($commands, InputInterface $input, OutputInterface $output, array $env = [])
  {
    if (! $output->isDecorated()) {
      $commands = array_map(function ($value) {
        if (substr($value, 0, 5) === 'chmod') {
          return $value;
        }

        return $value.' --no-ansi';
      }, $commands);
    }

    if ($input->getOption('quiet')) {
      $commands = array_map(function ($value) {
        if (substr($value, 0, 5) === 'chmod') {
          return $value;
        }

        return $value.' --quiet';
      }, $commands);
    }

    $process = Process::fromShellCommandline(implode(' && ', $commands), null, $env, null, null);

    if ('\\' !== DIRECTORY_SEPARATOR && file_exists('/dev/tty') && is_readable('/dev/tty')) {
      try {
        $process->setTty(true);
      } catch (RuntimeException $e) {
        $output->writeln('  <bg=yellow;fg=black> WARN </> '.$e->getMessage().PHP_EOL);
      }
    }

    $process->run(function ($type, $line) use ($output) {
      $output->write('    '.$line);
    });

    return $process;
  }

  /**
   * Replace the given file.
   *
   * @param  string  $replace
   * @param  string  $file
   * @return void
   */
  protected function replaceFile(string $replace, string $file)
  {
    $stubs = dirname(__DIR__).'/stubs';

    file_put_contents(
      $file,
      file_get_contents("$stubs/$replace"),
    );
  }

  /**
   * Replace the given string in the given file.
   *
   * @param  string  $search
   * @param  string  $replace
   * @param  string  $file
   * @return void
   */
  protected function replaceInFile(string $search, string $replace, string $file)
  {
    file_put_contents(
      $file,
      str_replace($search, $replace, file_get_contents($file))
    );
  }
}
