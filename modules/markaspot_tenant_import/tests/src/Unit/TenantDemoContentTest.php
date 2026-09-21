<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_tenant_import\Unit;

use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityConstraintViolationList;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\State\StateInterface;
use Drupal\markaspot_open311\Service\GeoreportProcessorServiceInterface;
use Drupal\markaspot_tenant_import\Service\TenantDemoContent;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Validator\ConstraintViolation;

/**
 * Protects synthetic input, site binding, constraints and repeat ownership.
 */
#[Group('markaspot_tenant_import')]
final class TenantDemoContentTest extends UnitTestCase {

  /**
   * Returns a complete minimal fixture contract.
   */
  private function fixture(): array {
    return [
      'version' => 1,
      'synthetic' => TRUE,
      'fixture_id' => 'test-fixture',
      'requests' => [[
        'key' => 'request-one',
        'title' => 'Synthetic report',
        'category' => 'Example category',
        'status' => 'Open',
        'fields' => ['field_e_mail' => 'demo@example.invalid', 'field_notification' => FALSE],
        'status_history' => [
          ['status' => 'Open', 'note' => 'Synthetic history', 'author_email' => 'staff@example.invalid'],
        ],
      ]],
    ];
  }

  /**
   * Builds an isolated service with no real persistence.
   */
  private function service(?EntityTypeManagerInterface $entities = NULL): TenantDemoContent {
    return new TenantDemoContent(
      $entities ?? $this->createMock(EntityTypeManagerInterface::class),
      $this->createMock(EntityFieldManagerInterface::class),
      $this->getConfigFactoryStub(['system.site' => ['uuid' => 'expected-site']]),
      $this->createMock(StateInterface::class),
      $this->createMock(LockBackendInterface::class),
      $this->createMock(Connection::class),
      $this->createMock(FileSystemInterface::class),
      $this->createMock(GeoreportProcessorServiceInterface::class),
      $this->createMock(AccountInterface::class),
    );
  }

  /**
   * Rejects real contact, arbitrary fields and notification activation.
   */
  public function testSyntheticContractRejectsUnsafeOrUnknownInput(): void {
    $fixture = $this->fixture();
    TenantDemoContent::validateFixture($fixture);
    $mutations = [
      ['field_e_mail' => 'real@example.com'],
      ['field_first_name' => 'A real person'],
      ['field_phone' => '+49 123 456789'],
      ['field_notification' => TRUE],
      ['field_jurisdiction' => 123],
      ['field_status_internal' => 'retired'],
      ['field_gdpr' => TRUE],
    ];
    foreach ($mutations as $mutation) {
      $changed = $fixture;
      $changed['requests'][0]['fields'] = $mutation;
      try {
        TenantDemoContent::validateFixture($changed);
        $this->fail('Unsafe fixture fields were accepted.');
      }
      catch (\RuntimeException) {
        $this->addToAssertionCount(1);
      }
    }
  }

  /**
   * Unknown versions, duplicate keys and incomplete histories fail closed.
   */
  public function testBoundedExplicitFixtureIdentity(): void {
    $fixture = $this->fixture();
    $invalid = [];
    $invalid[] = array_replace($fixture, ['synthetic' => FALSE]);
    $invalid[] = array_replace($fixture, ['version' => 2]);
    $invalid[] = array_replace($fixture, ['fixture_id' => '../escape']);
    $invalid[] = array_replace($fixture, ['fixture_id' => []]);
    $invalid[] = array_replace($fixture, ['requests' => array_fill(0, 21, $fixture['requests'][0])]);
    $invalid[] = array_replace($fixture, ['requests' => [$fixture['requests'][0], $fixture['requests'][0]]]);
    $withoutHistory = $fixture;
    $withoutHistory['requests'][0]['status_history'] = [];
    $invalid[] = $withoutHistory;
    $unknown = $fixture;
    $unknown['requests'][0]['arbitrary_config'] = [];
    $invalid[] = $unknown;
    foreach ($invalid as $changed) {
      try {
        TenantDemoContent::validateFixture($changed);
        $this->fail('Invalid fixture contract was accepted.');
      }
      catch (\RuntimeException) {
        $this->addToAssertionCount(1);
      }
    }
  }

  /**
   * Identical names in different sites or jurisdictions cannot collide.
   */
  public function testDeterministicUuidIsBoundToInstallationAndJurisdiction(): void {
    $one = TenantDemoContent::fixtureUuid('site-one', 'jur-one', 'fixture:report');
    $this->assertSame($one, TenantDemoContent::fixtureUuid('site-one', 'jur-one', 'fixture:report'));
    $this->assertNotSame($one, TenantDemoContent::fixtureUuid('site-two', 'jur-one', 'fixture:report'));
    $this->assertNotSame($one, TenantDemoContent::fixtureUuid('site-one', 'jur-two', 'fixture:report'));
    $this->assertMatchesRegularExpression('/^[a-f0-9]{8}-[a-f0-9]{4}-5[a-f0-9]{3}-a[a-f0-9]{3}-[a-f0-9]{12}$/', $one);
  }

  /**
   * Production or absent confirmation cannot even query entity storage.
   */
  public function testProductionAndUnconfirmedCallsCannotMutate(): void {
    $entities = $this->createMock(EntityTypeManagerInterface::class);
    $entities->expects($this->never())->method('getStorage');
    $service = $this->service($entities);
    $oldContext = getenv('MARKASPOT_DEPLOY_CONTEXT');
    $oldMail = getenv('MARKASPOT_MAIL_MODE');
    try {
      foreach ([
        ['production', 'mailpit', TRUE],
        ['nonproduction', 'production', TRUE],
        ['nonproduction', 'mailpit', FALSE],
      ] as [$context, $mail, $confirmed]) {
        putenv('MARKASPOT_DEPLOY_CONTEXT=' . $context);
        putenv('MARKASPOT_MAIL_MODE=' . $mail);
        try {
          $service->seed($this->fixture(), '/unused', 'expected-site', 1, $confirmed, TRUE);
          $this->fail('Unsafe environment was accepted.');
        }
        catch (\RuntimeException $error) {
          $this->assertStringContainsString('test confirmation', $error->getMessage());
        }
      }
    }
    finally {
      putenv($oldContext === FALSE ? 'MARKASPOT_DEPLOY_CONTEXT' : 'MARKASPOT_DEPLOY_CONTEXT=' . $oldContext);
      putenv($oldMail === FALSE ? 'MARKASPOT_MAIL_MODE' : 'MARKASPOT_MAIL_MODE=' . $oldMail);
    }
  }

  /**
   * A mismatching site UUID is rejected before entity lookup or writes.
   */
  public function testWrongSiteFailsBeforeEntityLookup(): void {
    $entities = $this->createMock(EntityTypeManagerInterface::class);
    $entities->expects($this->never())->method('getStorage');
    $oldContext = getenv('MARKASPOT_DEPLOY_CONTEXT');
    $oldMail = getenv('MARKASPOT_MAIL_MODE');
    try {
      putenv('MARKASPOT_DEPLOY_CONTEXT=nonproduction');
      putenv('MARKASPOT_MAIL_MODE=mailpit');
      $this->expectExceptionMessage('site UUID does not match');
      $this->service($entities)->seed($this->fixture(), '/unused', 'another-site', 1, TRUE, TRUE);
    }
    finally {
      putenv($oldContext === FALSE ? 'MARKASPOT_DEPLOY_CONTEXT' : 'MARKASPOT_DEPLOY_CONTEXT=' . $oldContext);
      putenv($oldMail === FALSE ? 'MARKASPOT_MAIL_MODE' : 'MARKASPOT_MAIL_MODE=' . $oldMail);
    }
  }

  /**
   * Entity constraint violations, including boundaries, are never bypassed.
   */
  public function testBoundaryConstraintFailurePreservesDiagnosticWithoutValues(): void {
    $entity = $this->createMock(ContentEntityInterface::class);
    $entity->expects($this->never())->method('save');
    $entity->method('validate')->willReturn(new EntityConstraintViolationList($entity, [
      new ConstraintViolation('Outside New York boundary, private value', '', [], NULL, 'field_geolocation', 'private value'),
    ]));
    $method = new \ReflectionMethod(TenantDemoContent::class, 'validate');
    try {
      $method->invoke($this->service(), $entity);
      $this->fail('Boundary failure was ignored.');
    }
    catch (\RuntimeException $error) {
      $this->assertStringContainsString('field_geolocation', $error->getMessage());
      $this->assertStringNotContainsString('private value', $error->getMessage());
      $this->assertStringContainsString('not bypassed', $error->getMessage());
    }
  }

  /**
   * Computed display URLs do not falsely mark stored fixture content as edited.
   */
  public function testFingerprintIgnoresComputedPresentationFields(): void {
    $entity = $this->createMock(ContentEntityInterface::class);
    $entity->method('getEntityTypeId')->willReturn('node');
    $entity->method('id')->willReturn(9);
    $entity->method('uuid')->willReturn('uuid');
    $computed = $this->createMock(FieldDefinitionInterface::class);
    $computed->method('isComputed')->willReturn(TRUE);
    $entity->method('getFieldDefinitions')->willReturn(['dashboard_media' => $computed]);
    $values = ['title' => 'Synthetic', 'dashboard_media' => 'temporary-url-one'];
    $entity->method('toArray')->willReturnCallback(static function () use (&$values) {
      return $values;
    });
    $method = new \ReflectionMethod(TenantDemoContent::class, 'fingerprint');
    $service = $this->service();
    $before = $method->invoke($service, $entity);
    $values['dashboard_media'] = 'temporary-url-two';
    $this->assertSame($before, $method->invoke($service, $entity));
    $values['title'] = 'Edited';
    $this->assertNotSame($before, $method->invoke($service, $entity));
  }

  /**
   * Partial, modified or foreign fixtures can never be adopted or replayed.
   */
  public function testExistingFixtureMustMatchSavedContentExactly(): void {
    $entity = $this->createMock(ContentEntityInterface::class);
    $entity->method('getEntityTypeId')->willReturn('node');
    $entity->method('id')->willReturn(9);
    $entity->method('uuid')->willReturn('node-uuid');
    $entity->method('getFieldDefinitions')->willReturn([]);
    $entity->method('toArray')->willReturn(['title' => [['value' => '[DEMO] original']]]);
    $entity->expects($this->never())->method('save');
    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('loadUnchanged')->with('9')->willReturn($entity);
    $entities = $this->createMock(EntityTypeManagerInterface::class);
    $entities->method('getStorage')->with('node')->willReturn($storage);
    $service = $this->service($entities);
    $fingerprint = (new \ReflectionMethod(TenantDemoContent::class, 'fingerprint'))->invoke($service, $entity);
    $record = [
      'binding' => ['site_uuid' => 'site'],
      'stage' => 'complete',
      'entities' => [$fingerprint],
      'result' => ['action' => 'created', 'applied' => TRUE],
    ];
    $method = new \ReflectionMethod(TenantDemoContent::class, 'verifyExisting');
    $result = $method->invoke($service, $record, $record['binding']);
    $this->assertSame('unchanged', $result['action']);
    $this->assertFalse($result['applied']);
    foreach (['partial', 'foreign', 'edited'] as $case) {
      $changed = $record;
      if ($case === 'partial') {
        $changed['stage'] = 'started';
      }
      elseif ($case === 'foreign') {
        $changed['binding'] = ['site_uuid' => 'foreign'];
      }
      else {
        $changed['entities'][0]['sha256'] = 'edited';
      }
      try {
        $method->invoke($service, $changed, $record['binding']);
        $this->fail('Unsafe repeat was accepted.');
      }
      catch (\RuntimeException) {
        $this->addToAssertionCount(1);
      }
    }
  }

}
