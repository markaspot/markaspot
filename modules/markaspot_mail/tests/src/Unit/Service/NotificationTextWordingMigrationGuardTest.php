<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_mail\Unit\Service;

use Drupal\Component\Serialization\Yaml;
use Drupal\markaspot_mail\Service\NotificationTextWordingMigrationGuard;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

require_once dirname(__DIR__, 4) . '/markaspot_mail.install';
require_once dirname(__DIR__, 5) . '/markaspot_feedback/markaspot_feedback.install';
require_once dirname(__DIR__, 5) . '/markaspot_resubmission/markaspot_resubmission.install';

/**
 * Tests the strict config-write boundary for wording placeholders.
 */
#[CoversClass(NotificationTextWordingMigrationGuard::class)]
#[Group('markaspot_mail')]
final class NotificationTextWordingMigrationGuardTest extends UnitTestCase {

  /**
   * The exact previous EN default is safely replaced by the current template.
   */
  public function testReplacesExactLegacyEnglishDefault(): void {
    $guard = new NotificationTextWordingMigrationGuard();
    $replacement = $this->readShippedTemplate('config/install/markaspot_mail.texts.yml', TRUE);

    $migrated = $guard->replacementForExactLegacyDefault(
      _markaspot_mail_legacy_notification_texts('en'),
      _markaspot_mail_legacy_notification_texts('en'),
      $replacement,
    );

    $this->assertSame($replacement, $migrated);
    $this->assertStringContainsString(
      '{{ citizen_term_singular }}',
      (string) $migrated['report_confirmation']['subject'],
    );
  }

  /**
   * Any tenant edit, even a one-character change, is never overwritten.
   */
  public function testPreservesCustomizedTemplate(): void {
    $guard = new NotificationTextWordingMigrationGuard();
    $customized = _markaspot_mail_legacy_notification_texts('en');
    $customized['status_closed']['headline'] = 'A local operator headline';

    $this->assertNull($guard->replacementForExactLegacyDefault(
      $customized,
      _markaspot_mail_legacy_notification_texts('en'),
      $this->readShippedTemplate('config/install/markaspot_mail.texts.yml', TRUE),
    ));
  }

  /**
   * German overrides use the same strict guard and new title placeholder.
   */
  public function testReplacesOnlyExactLegacyGermanOverride(): void {
    $guard = new NotificationTextWordingMigrationGuard();
    $replacement = $this->readShippedTemplate(
      'config/install/language/de/markaspot_mail.texts.yml',
      FALSE,
    );

    $this->assertSame(
      $replacement,
      $guard->replacementForExactLegacyDefault(
        _markaspot_mail_legacy_notification_texts('de'),
        _markaspot_mail_legacy_notification_texts('de'),
        $replacement,
      ),
    );

    $customized = _markaspot_mail_legacy_notification_texts('de');
    $customized['report_confirmation']['cta_label'] = 'Eigener Text';
    $this->assertNull($guard->replacementForExactLegacyDefault(
      $customized,
      _markaspot_mail_legacy_notification_texts('de'),
      $replacement,
    ));
    $this->assertStringContainsString(
      '{{ citizen_term_singular_title }}',
      (string) $replacement['report_confirmation']['subject'],
    );
  }

  /**
   * Every shipped citizen label is now an explicit runtime term token.
   */
  public function testShippedTemplatesContainNoFixedReportOrMeldungLabels(): void {
    $englishData = $this->readShippedTemplate('config/install/markaspot_mail.texts.yml', TRUE);
    unset($englishData['langcode']);
    $germanData = $this->readShippedTemplate(
      'config/install/language/de/markaspot_mail.texts.yml',
      FALSE,
    );
    // Encode slot values only. The structural key report_confirmation is
    // intentionally part of the API contract and is not citizen-facing copy.
    $english = Yaml::encode(array_values($englishData));
    $german = Yaml::encode(array_values($germanData));

    $this->assertStringNotContainsString('report', strtolower($english));
    $this->assertStringNotContainsString('Meldung', $german);
    $this->assertStringContainsString('{{ citizen_term_singular }}', $english);
    $this->assertStringContainsString('{{ citizen_term_singular_title }}', $german);
  }

  /**
   * The mail module explicitly owns the resolver dependency it consumes.
   */
  public function testMailModuleDeclaresNuxtWordingDependency(): void {
    $info = Yaml::decode((string) file_get_contents(dirname(__DIR__, 4) . '/markaspot_mail.info.yml'));
    $this->assertIsArray($info);
    $this->assertContains('markaspot_nuxt', $info['dependencies']);
  }

  /**
   * Consumer config migration snapshots preserve historic EN and DE shapes.
   */
  public function testConsumerMigrationSnapshotsRecognizeOnlyKnownDefaults(): void {
    $feedbackEnglish = _markaspot_feedback_legacy_mail_template('en', TRUE);
    $feedbackGerman = _markaspot_feedback_legacy_mail_template('de', TRUE);
    $feedbackGermanWithoutLangcode = _markaspot_feedback_legacy_mail_template('de', FALSE);
    $resubmissionEnglish = _markaspot_resubmission_legacy_mail_template('en', TRUE);
    $resubmissionGerman = _markaspot_resubmission_legacy_mail_template('de', TRUE);
    $resubmissionGermanWithoutLangcode = _markaspot_resubmission_legacy_mail_template('de', FALSE);

    $this->assertSame('Your report [node:request_id] - Feedback welcome', $feedbackEnglish['feedback_request']['subject']);
    $this->assertSame('Ihre Meldung [node:request_id] auf dem Mängelmelder: Feedback willkommen', $feedbackGerman['feedback_request']['subject']);
    $this->assertSame($feedbackGerman['feedback_request'], $feedbackGermanWithoutLangcode['feedback_request']);
    $this->assertArrayNotHasKey('langcode', $feedbackGermanWithoutLangcode);

    $this->assertSame('Reminder: [node:title]', $resubmissionEnglish['resubmit_request']['subject']);
    $this->assertSame('Erinnerung: [node:title]', $resubmissionGerman['resubmit_request']['subject']);
    $this->assertSame($resubmissionGerman['resubmit_request'], $resubmissionGermanWithoutLangcode['resubmit_request']);
    $this->assertArrayNotHasKey('langcode', $resubmissionGermanWithoutLangcode);
  }

  /**
   * Legacy YAML block scalars keep the shipped terminal line ending.
   *
   * Config equality is deliberately strict. Losing the final newline from a
   * YAML literal block would incorrectly classify the untouched default as a
   * tenant customization and skip the safe migration.
   */
  public function testConsumerMigrationSnapshotsKeepFinalBlockScalarNewline(): void {
    $bodies = [
      _markaspot_feedback_legacy_mail_template('en', TRUE)['feedback_request']['body'],
      _markaspot_feedback_legacy_mail_template('de', TRUE)['feedback_request']['body'],
      _markaspot_resubmission_legacy_mail_template('en', TRUE)['resubmit_request']['body'],
      _markaspot_resubmission_legacy_mail_template('de', TRUE)['resubmit_request']['body'],
    ];

    foreach ($bodies as $body) {
      $this->assertIsString($body);
      $this->assertStringEndsWith("\n", $body);
    }
  }

  /**
   * Shipped EN and DE consumer templates opt into runtime citizen terms.
   */
  public function testConsumerShippedTemplatesUseRuntimeCitizenPlaceholders(): void {
    $modulesRoot = dirname(__DIR__, 5);
    $feedbackEnglish = Yaml::decode((string) file_get_contents($modulesRoot . '/markaspot_feedback/config/install/markaspot_feedback.mail.yml'));
    $feedbackGerman = Yaml::decode((string) file_get_contents($modulesRoot . '/markaspot_feedback/config/install/language/de/markaspot_feedback.mail.yml'));
    $resubmissionEnglish = Yaml::decode((string) file_get_contents($modulesRoot . '/markaspot_resubmission/config/install/markaspot_resubmission.mail.yml'));
    $resubmissionGerman = Yaml::decode((string) file_get_contents($modulesRoot . '/markaspot_resubmission/config/install/language/de/markaspot_resubmission.mail.yml'));

    foreach ([$feedbackEnglish, $feedbackGerman, $resubmissionEnglish, $resubmissionGerman] as $config) {
      $this->assertIsArray($config);
      $serialized = Yaml::encode($config);
      $this->assertStringContainsString('{{ citizen_term_', $serialized);
    }
  }

  /**
   * Consumer mail hooks replace terminology before passing text to Token.
   */
  public function testConsumerFallbacksReplaceTermsBeforeDrupalTokens(): void {
    foreach (['markaspot_feedback', 'markaspot_resubmission'] as $module) {
      $moduleRoot = dirname(__DIR__, 5) . '/' . $module;
      $moduleSource = file_get_contents($moduleRoot . '/' . $module . '.module');
      $info = Yaml::decode((string) file_get_contents($moduleRoot . '/' . $module . '.info.yml'));

      $this->assertIsString($moduleSource);
      $this->assertIsArray($info);
      $this->assertContains('markaspot_nuxt', $info['dependencies']);

      $placeholderPosition = strpos($moduleSource, 'replaceMailPlaceholders');
      $tokenPosition = strpos($moduleSource, '$token_service->replace');
      $this->assertNotFalse($placeholderPosition, $module . ' must resolve explicit terminology placeholders.');
      $this->assertNotFalse($tokenPosition, $module . ' must resolve Drupal node tokens.');
      $this->assertLessThan($tokenPosition, $placeholderPosition, $module . ' must replace terminology before Drupal tokens.');
    }
  }

  /**
   * The consumer updates compare the entire active config before writing.
   */
  public function testConsumerMigrationUpdatesUseStrictWholeConfigComparisons(): void {
    $feedbackInstall = file_get_contents(dirname(__DIR__, 5) . '/markaspot_feedback/markaspot_feedback.install');
    $resubmissionInstall = file_get_contents(dirname(__DIR__, 5) . '/markaspot_resubmission/markaspot_resubmission.install');

    $this->assertIsString($feedbackInstall);
    $this->assertIsString($resubmissionInstall);
    $this->assertStringContainsString('function markaspot_feedback_update_9003(): string', $feedbackInstall);
    $this->assertStringContainsString('getRawData() === _markaspot_feedback_legacy_mail_template', $feedbackInstall);
    $this->assertStringContainsString('$current === _markaspot_feedback_legacy_mail_template', $feedbackInstall);
    $this->assertStringContainsString('function markaspot_resubmission_update_9007(): string', $resubmissionInstall);
    $this->assertStringContainsString('getRawData() === _markaspot_resubmission_legacy_mail_template', $resubmissionInstall);
    $this->assertStringContainsString('$current === _markaspot_resubmission_legacy_mail_template', $resubmissionInstall);
  }

  /**
   * Reads the actual current shipped template rather than duplicating it.
   *
   * @return array<string, mixed>
   *   The decoded config data in the active-config or override shape.
   */
  private function readShippedTemplate(string $relativePath, bool $keepLangcode): array {
    $path = dirname(__DIR__, 4) . '/' . $relativePath;
    $decoded = Yaml::decode((string) file_get_contents($path));
    $this->assertIsArray($decoded);
    if (!$keepLangcode) {
      unset($decoded['langcode']);
    }
    return $decoded;
  }

}
