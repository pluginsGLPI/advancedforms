<?php

use PhpCsFixer\Config;
use PhpCsFixer\Finder;

$finder = Finder::create()
    ->in(__DIR__)
    ->exclude([
        '.git/',
        'node_modules/',
        'vendor/',
    ])
    ->name('*.php');

$config = new Config();

$rules = [
    '@PER-CS2.0' => true,
];

return $config
    ->setRules($rules)
    ->setFinder($finder)
    ->setCacheFile(sys_get_temp_dir() . '/php-cs-fixer.advancedforms.cache')
;
