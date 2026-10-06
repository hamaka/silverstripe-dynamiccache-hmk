<?php

namespace TractorCow\DynamicCache\Dev;

use SilverStripe\Dev\BuildTask;
use SilverStripe\ORM\DB;
use TractorCow\DynamicCache\DynamicCacheMiddleware;

/**
 * Clears the cache
 *
 * @author  Jake Bentvelzen
 * @package dynamiccache
 */
class DynamicCacheClearTask extends BuildTask
{
    private static $segment = 'DynamicCacheClearTask';
    protected $title = "DynamicCache Clear Task";
    protected $description = "This task clears the entire DynamicCache";

    public function run($request)
    {
        DynamicCacheMiddleware::inst()->clear();
        DB::alteration_message('DynamicCache has been cleared.');
    }
}
