<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_fastmap\Kernel;

use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\Core\Extension\Extension;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\group\Entity\Group;
use Drupal\group\Entity\GroupType;
use Drupal\KernelTests\KernelTestBase;
use Drupal\markaspot_fastmap\Service\LegalNoticeGenerator;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Psr\Log\LoggerInterface;

/**
 * Kernel tests for LegalNoticeGenerator.
 *
 * Exercises structured operator data, the manual-override sentinel, the GoBD
 * revision write on syncGroup(), and defense-in-depth HTML escaping.
 *
 * The full markaspot_fastmap module is intentionally NOT installed: its
 * dependency tree (markaspot_group, markaspot_passwordless, field_permissions)
 * is too heavy for a focused service test. We instantiate the service
 * directly with the real container's Twig engine plus a mocked module
 * handler that points to the module's template directory.
 *
 * @group markaspot_fastmap
 *
 * @coversDefaultClass \Drupal\markaspot_fastmap\Service\LegalNoticeGenerator
 */
#[RunTestsInSeparateProcesses]
class LegalNoticeGeneratorTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'address',
    'field',
    'text',
    'filter',
    'entity',
    'flexible_permissions',
    'group',
    'options',
    'datetime',
    'language',
    'content_translation',
  ];

  /**
   * The service under test.
   */
  protected LegalNoticeGenerator $generator;

  /**
   * Jurisdiction group used in most tests.
   */
  protected Group $group;

  /**
   * Captured log messages, keyed numerically.
   *
   * @var array<int, array{level: string, message: string, context: array}>
   */
  protected array $logMessages = [];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Register the markaspot_fastmap PSR-4 namespace manually. The module
    // isn't installed (its full dependency tree is too heavy) but we need
    // the LegalNoticeGenerator class and its sibling Service interfaces to
    // autoload.
    $modulePath = dirname(__DIR__, 3);
    /** @var \Composer\Autoload\ClassLoader $classLoader */
    $classLoader = require $this->container->getParameter('app.root') . '/autoload.php';
    $classLoader->addPsr4('Drupal\\markaspot_fastmap\\', $modulePath . '/src');

    $this->installEntitySchema('user');
    $this->installEntitySchema('group');
    $this->installEntitySchema('group_relationship');
    $this->installEntitySchema('configurable_language');
    $this->installConfig(['system', 'user', 'field', 'filter', 'group', 'language']);

    // Add German so syncGroup() can create the 'de' translation. Tests
    // exercise the de template path because it carries the TMG §5 wording
    // we actually validate against.
    ConfigurableLanguage::createFromLangcode('de')->save();

    GroupType::create(['id' => 'jur', 'label' => 'Jurisdiction'])->save();

    FieldStorageConfig::create([
      'field_name' => 'field_jurisdiction_e_mail',
      'entity_type' => 'group',
      'type' => 'email',
      'cardinality' => -1,
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_jurisdiction_e_mail',
      'entity_type' => 'group',
      'bundle' => 'jur',
      'label' => 'Jurisdiction email',
    ])->save();

    FieldStorageConfig::create([
      'field_name' => 'field_jurisdiction_address',
      'entity_type' => 'group',
      'type' => 'address',
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_jurisdiction_address',
      'entity_type' => 'group',
      'bundle' => 'jur',
      'label' => 'Jurisdiction address',
    ])->save();

    // field_legal_notice: translatable text_long, the target of the sync.
    FieldStorageConfig::create([
      'field_name' => 'field_legal_notice',
      'entity_type' => 'group',
      'type' => 'text_long',
      'translatable' => TRUE,
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_legal_notice',
      'entity_type' => 'group',
      'bundle' => 'jur',
      'label' => 'Legal Notice',
      'translatable' => TRUE,
    ])->save();

    $this->generator = $this->buildGenerator();

    $this->group = Group::create([
      'type' => 'jur',
      'label' => 'Test Workspace',
      'field_jurisdiction_address' => [
        'country_code' => 'DE',
        'organization' => 'Civic Patches GmbH',
        'address_line1' => 'Musterstraße 1',
        'address_line2' => 'c/o Beispiel AG',
        'postal_code' => '10115',
        'locality' => 'Berlin',
      ],
      'field_jurisdiction_e_mail' => [
        ['value' => 'kontakt@example.org'],
        ['value' => 'ignored@example.org'],
      ],
    ]);
    $this->group->save();
  }

  /**
   * Instantiates the generator with the real Twig + stubbed module handler.
   *
   * The Twig namespace @markaspot_fastmap is registered on the fly so the
   * service can load templates under the worktree path.
   */
  protected function buildGenerator(): LegalNoticeGenerator {
    $absModulePath = dirname(__DIR__, 3);
    $appRoot = $this->container->getParameter('app.root');
    // Extension::__construct asserts that $pathname is relative to app.root,
    // so derive the relative form here. realpath() collapses any symlinks
    // before substr() so the prefix check holds for worktree copies.
    $realRoot = \realpath($appRoot);
    $realModule = \realpath($absModulePath);
    $relativePath = \is_string($realRoot) && \is_string($realModule) && \str_starts_with($realModule, $realRoot . '/')
      ? \substr($realModule, \strlen($realRoot) + 1)
      : $absModulePath;
    $twig = $this->container->get('twig');
    /** @var \Drupal\Core\Template\Loader\FilesystemLoader $filesystemLoader */
    $filesystemLoader = $this->container->get('twig.loader.filesystem');
    $filesystemLoader->addPath($absModulePath . '/templates', 'markaspot_fastmap');

    $moduleHandler = $this->createMock(ModuleHandlerInterface::class);
    $moduleHandler->method('getModule')
      ->with('markaspot_fastmap')
      ->willReturn(new Extension(
        $appRoot,
        'module',
        $relativePath . '/markaspot_fastmap.info.yml',
        'markaspot_fastmap.module'
      ));

    $logger = $this->createMock(LoggerInterface::class);
    $logger->method('info')->willReturnCallback(
      function (string $message, array $context = []): void {
        $this->logMessages[] = ['level' => 'info', 'message' => $message, 'context' => $context];
      }
    );
    $logger->method('error')->willReturnCallback(
      function (string $message, array $context = []): void {
        $this->logMessages[] = ['level' => 'error', 'message' => $message, 'context' => $context];
      }
    );

    return new LegalNoticeGenerator(
      $twig,
      $this->container->get('language_manager'),
      $moduleHandler,
      $logger,
      $this->container->get('address.country_repository'),
    );
  }

  /**
   * @covers ::generateForGroup
   */
  public function testGenerateForGroupWithCompleteData(): void {
    $html = $this->generator->generateForGroup($this->group, 'de');

    $this->assertStringContainsString('Impressum', $html);
    $this->assertStringContainsString('Angaben gemäß § 5 TMG', $html);
    $this->assertStringContainsString('Civic Patches GmbH', $html);
    $this->assertStringContainsString('Musterstraße 1', $html);
    $this->assertStringContainsString('c/o Beispiel AG', $html);
    $this->assertStringContainsString('10115', $html);
    $this->assertStringContainsString('Berlin', $html);
    $this->assertStringContainsString('Deutschland', $html);
    $this->assertStringContainsString('kontakt@example.org', $html);
    $this->assertStringNotContainsString('ignored@example.org', $html);
    $this->assertStringNotContainsString('[Firmenname noch nicht hinterlegt]', $html);
    $this->assertStringNotContainsString('unvollständig', $html);
  }

  /**
   * @covers ::generateForGroup
   */
  public function testGenerateForGroupUsesNaturalPersonName(): void {
    $this->group->set('field_jurisdiction_address', [
      'country_code' => 'DE',
      'organization' => '',
      'given_name' => 'Erika',
      'family_name' => 'Mustermann',
      'address_line1' => 'Musterstraße 1',
      'postal_code' => '10115',
      'locality' => 'Berlin',
    ]);
    $this->group->save();

    $html = $this->generator->generateForGroup($this->group, 'de');

    $this->assertStringContainsString('Erika Mustermann', $html);
    $this->assertStringNotContainsString('Civic Patches GmbH', $html);
  }

  /**
   * @covers ::generateForGroup
   */
  public function testGenerateForGroupShowsPlaceholdersWhenEmpty(): void {
    $empty = Group::create([
      'type' => 'jur',
      'label' => 'Empty Workspace',
    ]);
    $empty->save();

    $html = $this->generator->generateForGroup($empty, 'de');

    $this->assertStringContainsString('[Firmenname noch nicht hinterlegt]', $html);
    $this->assertStringContainsString('[Anschrift noch nicht hinterlegt]', $html);
    $this->assertStringContainsString('[E-Mail noch nicht hinterlegt]', $html);
    $this->assertStringContainsString('unvollständig', $html);
  }

  /**
   * @covers ::generateForGroup
   */
  public function testGenerateForGroupWorksWithoutBillingFields(): void {
    $billingFields = [
      'field_billing_name',
      'field_billing_email',
      'field_billing_address_line1',
      'field_billing_address_line2',
      'field_billing_city',
      'field_billing_postal_code',
      'field_billing_country',
      'field_billing_tax_id',
    ];
    foreach ($billingFields as $fieldName) {
      $this->assertFalse($this->group->hasField($fieldName));
    }

    $html = $this->generator->generateForGroup($this->group, 'de');

    $this->assertStringContainsString('Civic Patches GmbH', $html);
    $this->assertStringContainsString('kontakt@example.org', $html);
    $this->assertStringNotContainsString('unvollständig', $html);
  }

  /**
   * @covers ::generateForGroup
   */
  public function testGenerateForGroupPrintsTaxIdOnlyWhenPresent(): void {
    FieldStorageConfig::create([
      'field_name' => 'field_billing_tax_id',
      'entity_type' => 'group',
      'type' => 'string',
      'settings' => ['max_length' => 50],
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_billing_tax_id',
      'entity_type' => 'group',
      'bundle' => 'jur',
      'label' => 'Billing Tax ID',
    ])->save();

    $group = Group::load($this->group->id());
    $this->assertInstanceOf(Group::class, $group);
    $html = $this->generator->generateForGroup($group, 'de');
    $this->assertStringNotContainsString('Umsatzsteuer-ID', $html);

    $group->set('field_billing_tax_id', 'DE123456789');
    $group->save();

    $html = $this->generator->generateForGroup($group, 'de');

    $this->assertStringContainsString('DE123456789', $html);
    $this->assertStringContainsString('§ 27 a Umsatzsteuergesetz', $html);
  }

  /**
   * @covers ::generateForGroup
   */
  public function testGenerateForGroupEscapesScriptTagInOperatorName(): void {
    $address = $this->group->get('field_jurisdiction_address')->first()?->getValue() ?? [];
    $address['organization'] = '<script>alert(1)</script>';
    $this->group->set('field_jurisdiction_address', $address);
    $this->group->save();

    $html = $this->generator->generateForGroup($this->group, 'de');

    $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
    $this->assertStringNotContainsString('<script>', $html);
  }

  /**
   * @covers ::syncGroup
   */
  public function testSyncGroupWritesFieldAndRevision(): void {
    $written = $this->generator->syncGroup($this->group, ['de']);

    $this->assertSame(['de'], $written);

    $reloaded = $this->container->get('entity_type.manager')
      ->getStorage('group')
      ->loadUnchanged($this->group->id());
    // The de translation carries the rendered Impressum; the default (en)
    // translation may still be empty because the test only synced 'de'.
    $this->assertTrue($reloaded->hasTranslation('de'));
    $deTranslation = $reloaded->getTranslation('de');
    $this->assertFalse($deTranslation->get('field_legal_notice')->isEmpty());
    $value = (string) $deTranslation->get('field_legal_notice')->value;
    $this->assertStringContainsString('Civic Patches GmbH', $value);
    $this->assertStringContainsString('Impressum', $value);

    $this->assertSame(
      'Auto-generated legal notice from operator address',
      (string) $reloaded->getRevisionLogMessage()
    );

    // Audit log emitted with synced langcodes.
    $infoMessages = array_filter(
      $this->logMessages,
      static fn(array $entry): bool => $entry['level'] === 'info'
    );
    $this->assertNotEmpty($infoMessages, 'syncGroup must emit an info-level audit log entry.');
  }

  /**
   * @covers ::syncGroup
   * @covers ::hasManualOverride
   */
  public function testSyncGroupRespectsManualOverrideSentinel(): void {
    $manual = '<p>Hand-curated Impressum</p>' . LegalNoticeGenerator::MANUAL_OVERRIDE_SENTINEL;
    $this->group->set('field_legal_notice', [
      'value' => $manual,
      'format' => 'basic_html',
    ]);
    $this->group->save();

    $this->assertTrue(
      $this->generator->hasManualOverride($this->group, 'de'),
      'Sentinel must be detected on the stored field value.'
    );

    $written = $this->generator->syncGroup($this->group, ['de']);

    $this->assertSame(
      [],
      $written,
      'syncGroup must skip langcodes carrying the manual-override sentinel.'
    );

    $reloaded = $this->container->get('entity_type.manager')
      ->getStorage('group')
      ->loadUnchanged($this->group->id());
    $this->assertSame($manual, (string) $reloaded->get('field_legal_notice')->value);
  }

  /**
   * @covers ::hasManualOverride
   */
  public function testHasManualOverrideReturnsFalseForGeneratedValue(): void {
    $this->group->set('field_legal_notice', [
      'value' => '<h2>Impressum</h2><p>Auto-generated.</p>',
      'format' => 'basic_html',
    ]);
    $this->group->save();

    $this->assertFalse($this->generator->hasManualOverride($this->group, 'de'));
  }

  /**
   * @covers ::hasManualOverride
   */
  public function testHasManualOverrideIsCaseInsensitive(): void {
    // Operators may type the sentinel with mixed case; the detection must
    // not silently lose protection on capitalisation drift.
    $this->group->set('field_legal_notice', [
      'value' => '<p>Custom</p><!-- MANUAL-OVERRIDE -->',
      'format' => 'basic_html',
    ]);
    $this->group->save();

    $this->assertTrue($this->generator->hasManualOverride($this->group, 'de'));
  }

  /**
   * @covers ::getSupportedLangcodes
   */
  public function testGetSupportedLangcodesContainsDeAndEn(): void {
    $langcodes = $this->generator->getSupportedLangcodes();
    $this->assertContains('de', $langcodes);
    $this->assertContains('en', $langcodes);
  }

}
