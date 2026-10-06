<?php

namespace TractorCow\DynamicCache\Dev;

use SilverStripe\Dev\BuildTask;
use SilverStripe\PolyExecution\PolyOutput;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use TractorCow\DynamicCache\DynamicCacheMiddleware;

/**
 * Clears the cache
 *
 * @author  Jake Bentvelzen
 * @package dynamiccache
 */
class DynamicCacheClearTask extends BuildTask
{
    protected static string $commandName = 'DynamicCacheClearTask';
    protected string $title = "DynamicCache Clear Task";
    protected static string $description = "This task clears the entire DynamicCache";

    protected function execute(InputInterface $input, PolyOutput $output): int
    {
        DynamicCacheMiddleware::inst()->clear();
        $output->writeln('DynamicCache has been cleared.');

        return Command::SUCCESS;
    }
}
