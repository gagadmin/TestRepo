<?php

// Generated optimization metadata must never override PHPUnit's isolated
// environment or leave the suite exercising stale routes.
$cacheDirectory = dirname(__DIR__).'/bootstrap/cache';

foreach (['config.php', 'events.php', 'routes-*.php'] as $pattern) {
    foreach (glob($cacheDirectory.'/'.$pattern) ?: [] as $cachedFile) {
        if (is_file($cachedFile)) {
            unlink($cachedFile);
        }
    }
}

require dirname(__DIR__).'/vendor/autoload.php';
