<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_nuxt\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Guards against reintroducing a blanket hook_file_access() in the profile.
 *
 * A hook_file_access() implementation returning AccessResult::allowed() is
 * never a no-op: entity access hook results are combined with orIf(), so an
 * unconditional "allowed" overrules FileAccessControlHandler and therefore
 * also \Drupal\file\Hook\FileDownloadHook, which gates private:// delivery on
 * $file->access('download'). Every private file referenced by any field then
 * becomes downloadable by anyone holding the URL, including anonymous users,
 * regardless of the publication status of the referencing media or node.
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
 * @group markaspot_nuxt
 */
final class FileAccessHookAbsenceTest extends TestCase {

  /**
   * No module in the profile may implement hook_file_access().
   */
  public function testProfileDefinesNoFileAccessHook(): void {
    $offenders = [];

    foreach ($this->profileModuleFiles() as $path) {
      $source = file_get_contents($path);
      if ($source === FALSE) {
        continue;
      }
      if (preg_match('/function\s+\w+_file_access\s*\(/', $source) === 1) {
        $offenders[] = basename($path);
      }
    }

    $this->assertSame([], $offenders, sprintf(
      'hook_file_access() found in: %s. An unconditional grant there disables '
      . 'private-file protection profile-wide; see this test docblock.',
      implode(', ', $offenders)
    ));
  }

  /**
   * Lists every .module file shipped by the profile.
   *
   * @return string[]
   *   Absolute paths to the profile's .module files.
   */
  private function profileModuleFiles(): array {
    $modules_dir = dirname(__DIR__, 4);
    $paths = glob($modules_dir . '/*/*.module') ?: [];

    // The profile's own .module file sits one level up from modules/.
    $profile_module = glob(dirname($modules_dir) . '/*.module') ?: [];

    return array_merge($paths, $profile_module);
  }

}
