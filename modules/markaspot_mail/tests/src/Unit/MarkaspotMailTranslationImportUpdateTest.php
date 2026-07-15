<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_mail\Unit;

use Drupal\Component\Serialization\Yaml;
use Drupal\Tests\UnitTestCase;

/**
 * Tests markaspot_mail update hook coverage.
 *
 * @group markaspot_mail
 */
class MarkaspotMailTranslationImportUpdateTest extends UnitTestCase
{
  /**
   * Tests the module declares the HTML-capable mail stack dependencies.
   */
    public function testMailStackDependenciesExist(): void
    {
        $moduleRoot = dirname(__DIR__, 3);
        $info = Yaml::decode(file_get_contents($moduleRoot . '/markaspot_mail.info.yml'));

        $this->assertContains('mailsystem:mailsystem', $info['dependencies']);
        $this->assertContains('phpmailer_smtp:phpmailer_smtp', $info['dependencies']);
    }

  /**
   * Tests existing tenants get a second translation import update path.
   */
    public function testTranslationReimportUpdatePathExists(): void
    {
        $source = file_get_contents(dirname(__DIR__, 3) . '/markaspot_mail.install');
        $this->assertIsString($source);

        $this->assertStringContainsString('function markaspot_mail_update_10004(): string', $source);
        $this->assertStringContainsString('function markaspot_mail_update_10010(): string', $source);
        $this->assertStringContainsString('function markaspot_mail_update_10011(): string', $source);
        $this->assertStringContainsString('_markaspot_mail_import_shipped_translations()', $source);
        $this->assertStringContainsString('Gettext::fileToDatabase', $source);
        $this->assertStringContainsString("\$report['skips']", $source);
        $this->assertStringContainsString('Rejected %d unsafe or malformed markaspot_mail translation(s)', $source);
    }

  /**
   * Tests existing tenants get routed back to the HTML mail backend.
   */
    public function testHtmlBackendUpdatePathExists(): void
    {
        $source = file_get_contents(dirname(__DIR__, 3) . '/markaspot_mail.install');
        $this->assertIsString($source);

        $this->assertStringContainsString('function markaspot_mail_update_10005(): string', $source);
        $this->assertStringContainsString('_markaspot_mail_resolve_backend', $source);
        $this->assertStringContainsString('_markaspot_mail_delete_stale_mail_config', $source);
        $this->assertStringContainsString("'mailsystem' => ['mailsystem.settings']", $source);
        $this->assertStringContainsString(
            "'phpmailer_smtp' => ['phpmailer_smtp.settings', 'phpmailer_smtp.format']",
            $source
        );
        $this->assertStringContainsString("return 'smtp';", $source);
        $this->assertStringContainsString("return 'phpmailer_smtp';", $source);
        $this->assertStringContainsString("'defaults.sender', 'defaults.formatter'", $source);
        $this->assertStringContainsString('module_installer', $source);
        $this->assertStringContainsString('_markaspot_mail_seed_attachment_defaults()', $source);
        $this->assertStringContainsString('_markaspot_mail_ensure_phpmailer_html_format()', $source);
        $this->assertStringContainsString("format', 'html'", $source);
    }

  /**
   * Tests the shared footer string is present in all shipped translations.
   */
    public function testFooterTranslationExistsInEveryShippedPoFile(): void
    {
        $translationDir = dirname(__DIR__, 3) . '/translations';
        $files = glob($translationDir . '/*.po');
        $this->assertIsArray($files);
        $this->assertNotEmpty($files);

        $msgid = 'This e-mail was sent automatically by @name via the Mark-a-Spot platform.';
        foreach ($files as $file) {
            $source = file_get_contents($file);
            $this->assertIsString($source);
            $this->assertStringContainsString('msgid "' . $msgid . '"', $source, basename($file));
        }
    }
}
