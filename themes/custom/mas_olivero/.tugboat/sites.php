<?php

// Set preloaded as the default.
$sites['localhost'] = 'preloaded';
$sites['preloaded'] = 'preloaded';
$sites[getenv('TUGBOAT_DEFAULT_SERVICE_URL_HOST')] = 'preloaded';
$sites['preloaded-' . getenv('TUGBOAT_DEFAULT_SERVICE_TOKEN') . '.tugboat.qa'] = 'preloaded';

// Domain mappings for minimal.
$sites['minimal'] = 'minimal';
$sites['minimal-' . getenv('TUGBOAT_DEFAULT_SERVICE_TOKEN') . '.tugboat.qa'] = 'minimal';

// Domain mappings for standard.
$sites['standard'] = 'standard';
$sites['standard-' . getenv('TUGBOAT_DEFAULT_SERVICE_TOKEN') . '.tugboat.qa'] = 'standard';
