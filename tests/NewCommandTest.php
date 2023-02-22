<?php

namespace Serenity\Installer\Console\Tests;

use PHPUnit\Framework\TestCase;
use Serenity\Installer\Console\NewCommand;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

class NewCommandTest extends TestCase
{
  public function test_it_can_scaffold_a_new_serenity_app()
  {
    $scaffoldDirectoryName = 'tests-output/my-app';
    $scaffoldDirectory = __DIR__.'/../'.$scaffoldDirectoryName;

    if (file_exists($scaffoldDirectory)) {
      if (PHP_OS_FAMILY == 'Windows') {
        exec("rd /s /q \"$scaffoldDirectory\"");
      } else {
        exec("rm -rf \"$scaffoldDirectory\"");
      }
    }

    $app = new Application('Serenity Installer');
    $app->add(new NewCommand);

    $tester = new CommandTester($app->find('new'));

    $statusCode = $tester->execute(['name' => $scaffoldDirectoryName]);

    $this->assertSame(0, $statusCode);
    $this->assertDirectoryExists($scaffoldDirectory.'/vendor');
    $this->assertFileExists($scaffoldDirectory.'/.env');
  }
}
