<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_nuxt\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Guards against reintroducing a blanket file access grant in the profile.
 *
 * A hook_file_access() implementation returning AccessResult::allowed() is
 * never a no-op: entity access hook results are combined with orIf(), so an
 * unconditional "allowed" overrules FileAccessControlHandler and therefore
 * also \Drupal\file\Hook\FileDownloadHook, which gates private:// delivery on
 * $file->access('download'). Every private file referenced by any field then
 * becomes downloadable by anyone holding the URL, including anonymous users,
 * regardless of the publication status of the referencing media or node.
 * hook_file_download() returning headers unconditionally defeats the same
 * chain one step later, so both are treated alike here.
 *
 * markaspot_nuxt shipped exactly such a hook ("Always allow access to files").
 * It was invisible on public:// installs, where core allows downloads anyway,
 * and silently disabled private-file protection on the installs that had
 * deliberately configured private:// — the ones relying on it, e.g. to hold
 * back images that markaspot_vision unpublished for containing PII.
 *
 * Core already resolves this correctly by delegating to the referencing
 * entity, so the profile does not need an opinion here. Should a future
 * requirement genuinely call for one, this test must be updated deliberately
 * rather than a blanket allow slipping back in unnoticed.
 *
 * Scope and limits — this is a static scan, not a behavioural assertion:
 * - It covers procedural hooks in .module, .profile, .inc and .install files,
 *   and the Drupal 11 attribute form #[Hook('file_access')] in PHP classes.
 *   The attribute form is the likeliest shape of a reintroduction, and the
 *   profile already uses #[Hook] elsewhere.
 * - It cannot catch a hook_entity_access() that returns allowed() for file or
 *   media view. That has the same total effect through the same orIf() and
 *   needs review attention, not a regex.
 * - Test fixtures are excluded: a hook in a test module never reaches a site.
 *
 * @group markaspot_nuxt
 */
final class FileAccessHookAbsenceTest extends TestCase {

  /**
   * Matches procedural and attribute-based file access/download hooks.
   */
  private const HOOK_PATTERNS = [
    '/function\s+\w+_file_(?:access|download)\s*\(/',
    '/#\[\s*Hook\(\s*[\'"]file_(?:access|download)[\'"]/',
  ];

  /**
   * No code shipped by the profile may grant file access wholesale.
   */
  public function testProfileDefinesNoFileAccessHook(): void {
    $offenders = [];

    foreach ($this->profileHookCandidates() as $path) {
      $source = file_get_contents($path);
      if ($source === FALSE) {
        continue;
      }
      foreach (self::HOOK_PATTERNS as $pattern) {
        if (preg_match($pattern, $source) === 1) {
          $offenders[] = $this->relativePath($path);
          break;
        }
      }
    }

    sort($offenders);
    $this->assertSame([], $offenders, sprintf(
      'file access/download hook found in: %s. An unconditional grant there '
      . 'disables private-file protection profile-wide; see this test docblock.',
      implode(', ', $offenders)
    ));
  }

  /**
   * The scan must actually reach the profile's code, not an empty set.
   *
   * Without this the test would pass vacuously if the directory layout ever
   * changed — which is how the previous, glob-based version silently stopped
   * covering the profile root.
   */
  public function testScanCoversProfileCode(): void {
    $candidates = $this->profileHookCandidates();
    $relative = array_map($this->relativePath(...), $candidates);

    $this->assertContains(
      'markaspot.profile',
      $relative,
      'The profile root hook file is not being scanned.'
    );
    $this->assertContains(
      'modules/markaspot_nuxt/markaspot_nuxt.module',
      $relative,
      'Module files are not being scanned.'
    );
    $this->assertGreaterThan(
      100,
      count($candidates),
      'Implausibly few files scanned; the traversal is probably broken.'
    );
  }

  /**
   * Lists every file in the profile that could carry a hook implementation.
   *
   * @return string[]
   *   Absolute paths, excluding test fixtures and vendored code.
   */
  private function profileHookCandidates(): array {
    $extensions = ['module', 'profile', 'inc', 'install', 'php'];
    $paths = [];

    // Prune excluded directories during descent rather than filtering their
    // files afterwards. .claude/worktrees holds dozens of working copies of
    // this very repository; descending into them is both slow and wrong, as
    // it would report historical code as current.
    $directories = new \RecursiveDirectoryIterator(
      $this->profileRoot(),
      \RecursiveDirectoryIterator::SKIP_DOTS
    );
    $pruned = new \RecursiveCallbackFilterIterator(
      $directories,
      static function (\SplFileInfo $file): bool {
        if (!$file->isDir()) {
          return TRUE;
        }
        $name = $file->getFilename();
        return !str_starts_with($name, '.')
          && !in_array($name, ['tests', 'vendor', 'node_modules'], TRUE);
      }
    );

    foreach (new \RecursiveIteratorIterator($pruned) as $file) {
      if ($file->isFile()
        && in_array($file->getExtension(), $extensions, TRUE)) {
        $paths[] = $file->getPathname();
      }
    }

    return $paths;
  }

  /**
   * Returns the profile root directory.
   */
  private function profileRoot(): string {
    // tests/src/Unit -> markaspot_nuxt -> modules -> profile root.
    return dirname(__DIR__, 5);
  }

  /**
   * Renders a path relative to the profile root for readable failures.
   */
  private function relativePath(string $path): string {
    return ltrim(str_replace($this->profileRoot(), '', $path), '/');
  }

}
