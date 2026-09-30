<?php

/**
 * @file
 * Writes a PHPUnit config that runs one shard of the kernel tests.
 *
 * Usage: php phpunit-shard.php <profile dir> <shard index> <shard count>
 *
 * PHPUnit takes a single path, so each CI job gets its own config: the
 * profile's phpunit.xml.dist with one "shard" testsuite listing its files.
 * Files are spread by their number of test methods, heaviest first onto the
 * lightest shard, so one module with many kernel tests does not pile up in
 * a single job. The assignment is deterministic for a given tree.
 */

declare(strict_types=1);

[, $profile, $index, $count] = $argv + [NULL, NULL, NULL, NULL];
if ($profile === NULL || !is_numeric($index) || !is_numeric($count) || (int) $count < 1 || (int) $index >= (int) $count) {
  fwrite(STDERR, "Usage: php phpunit-shard.php <profile dir> <shard index> <shard count>\n");
  exit(2);
}
$index = (int) $index;
$count = (int) $count;
$profile = rtrim($profile, '/');

$files = [];
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($profile . '/modules', FilesystemIterator::SKIP_DOTS));
foreach ($iterator as $file) {
  $path = $file->getPathname();
  if (str_ends_with($path, 'Test.php') && str_contains($path, '/tests/src/Kernel/')) {
    $weight = preg_match_all('/public function test\w*\s*\(/', (string) file_get_contents($path));
    $files[substr($path, strlen($profile) + 1)] = max(1, $weight);
  }
}
if (!$files) {
  fwrite(STDERR, "No kernel tests found under $profile/modules.\n");
  exit(1);
}

// Heaviest first, ties by path, so the result does not depend on the
// filesystem's directory order.
uksort($files, static fn (string $a, string $b): int => [$files[$b], $a] <=> [$files[$a], $b]);
$load = array_fill(0, $count, 0);
$shards = array_fill(0, $count, []);
foreach ($files as $path => $weight) {
  $target = array_keys($load, min($load), TRUE)[0];
  $shards[$target][] = $path;
  $load[$target] += $weight;
}

$config = new DOMDocument();
$config->preserveWhiteSpace = FALSE;
$config->formatOutput = TRUE;
if (!$config->load($profile . '/phpunit.xml.dist')) {
  fwrite(STDERR, "Cannot read $profile/phpunit.xml.dist.\n");
  exit(1);
}
$root = $config->documentElement;
// A shard that resolves to no tests must fail, not pass quietly.
$root->setAttribute('failOnEmptyTestSuite', 'true');
foreach (iterator_to_array($root->getElementsByTagName('testsuites')) as $old) {
  $root->removeChild($old);
}
$suites = $config->createElement('testsuites');
$suite = $config->createElement('testsuite');
$suite->setAttribute('name', 'shard');
sort($shards[$index]);
foreach ($shards[$index] as $path) {
  $suite->appendChild($config->createElement('file', $path));
}
$suites->appendChild($suite);
$root->appendChild($suites);

fwrite(STDERR, sprintf("Shard %d/%d: %d files, %d test methods (all shards: %s).\n", $index + 1, $count, count($shards[$index]), $load[$index], implode(', ', $load)));
echo $config->saveXML();
