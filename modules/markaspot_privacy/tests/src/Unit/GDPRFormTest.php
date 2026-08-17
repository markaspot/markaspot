<?php

namespace Drupal\Tests\markaspot_privacy\Unit;

use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Transaction;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\Field\EntityReferenceFieldItemListInterface;
use Drupal\Core\Form\FormState;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\markaspot_privacy\Form\GDPRForm;
use Drupal\node\NodeInterface;
use Drupal\node\NodeStorageInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\LoggerInterface;
use Symfony\Component\Yaml\Yaml;

/**
 * Tests privacy deletion access and PII scrubbing.
 */
#[Group('markaspot_privacy')]
class GDPRFormTest extends UnitTestCase {

  /**
   * The deletion route is restricted to node administrators.
   */
  public function testRouteRequiresNodeAdministration(): void {
    $routes = Yaml::parseFile(dirname(__DIR__, 3) . '/markaspot_privacy.routing.yml');
    $requirements = $routes['markaspot_privacy.gdpr_form']['requirements'];
    $this->assertSame('administer nodes', $requirements['_permission']);
    $this->assertArrayNotHasKey('_access', $requirements);
  }

  /**
   * An authorized deletion clears every structured citizen PII field.
   */
  public function testDeletionClearsStructuredPiiAcrossTranslations(): void {
    $uuid = '9ac79ba2-07a3-4f8d-9a22-4dbb9c309b77';
    $node = $this->createMock(NodeInterface::class);
    $node->method('bundle')->willReturn('service_request');
    $node->method('access')->with('update')->willReturn(TRUE);
    $node->method('getTranslationLanguages')->willReturn([
      'de' => $this->createMock(LanguageInterface::class),
      'en' => $this->createMock(LanguageInterface::class),
    ]);
    $translations = [];
    $citizen = $this->createMock(EntityInterface::class);
    $citizen->method('getEntityTypeId')->willReturn('citizen_entity');
    $citizen->method('id')->willReturn(27);
    foreach (['de', 'en'] as $langcode) {
      $translation = $this->createMock(NodeInterface::class);
      $translation->expects($this->once())->method('setUnpublished');
      $translation->method('hasField')->willReturnMap([
        ['field_e_mail', TRUE],
        ['field_first_name', TRUE],
        ['field_last_name', TRUE],
        ['field_phone', TRUE],
        ['field_citizen', TRUE],
      ]);
      $citizen_field = $this->createMock(EntityReferenceFieldItemListInterface::class);
      $citizen_field->method('referencedEntities')->willReturn([$citizen]);
      $translation->method('get')->with('field_citizen')->willReturn($citizen_field);
      $translation->expects($this->exactly(5))->method('set')->willReturnMap([
        ['field_e_mail', NULL, $translation],
        ['field_first_name', NULL, $translation],
        ['field_last_name', NULL, $translation],
        ['field_phone', NULL, $translation],
        ['field_citizen', NULL, $translation],
      ]);
      $translations[$langcode] = $translation;
    }
    $node->method('getTranslation')->willReturnCallback(
      static fn(string $langcode): NodeInterface => $translations[$langcode],
    );
    $node->expects($this->once())->method('setNewRevision')->with(FALSE);
    $node->expects($this->once())->method('save');
    $node->method('getRevisionId')->willReturn(5);
    $node->method('id')->willReturn(1);
    $node->method('label')->willReturn('Test request');

    $storage = $this->createMock(NodeStorageInterface::class);
    $storage->method('loadByProperties')->with(['uuid' => $uuid])->willReturn([$node]);
    $query = $this->createRevisionQuery([3 => 1, 4 => 1, 5 => 1]);
    $storage->method('getQuery')->willReturn($query);
    $deleted_revisions = [];
    $storage->expects($this->exactly(2))->method('deleteRevision')
      ->willReturnCallback(static function (int $revision_id) use (&$deleted_revisions): void {
        $deleted_revisions[] = $revision_id;
      });
    $citizen_storage = $this->createMock(EntityStorageInterface::class);
    $citizen_storage->expects($this->once())->method('delete')->with([$citizen]);
    $manager = $this->createMock(EntityTypeManagerInterface::class);
    $manager->method('getStorage')->willReturnMap([
      ['node', $storage],
      ['citizen_entity', $citizen_storage],
    ]);

    $transaction = $this->createTransactionSpy();
    $database = $this->createMock(Connection::class);
    $database->method('startTransaction')->willReturn($transaction);

    $state = (new FormState())->setValue('uuid', $uuid);
    $form_array = [];
    $this->createForm($manager, $database)->submitForm($form_array, $state);

    $this->assertSame([3, 4], $deleted_revisions);
    $this->assertSame(0, $transaction->rollbacks);
  }

  /**
   * A revision cleanup failure rolls back the complete privacy operation.
   */
  public function testRevisionFailureRollsBack(): void {
    $uuid = '9ac79ba2-07a3-4f8d-9a22-4dbb9c309b77';
    $node = $this->createMock(NodeInterface::class);
    $node->method('bundle')->willReturn('service_request');
    $node->method('access')->with('update')->willReturn(TRUE);
    $node->method('getTranslationLanguages')->willReturn([
      'de' => $this->createMock(LanguageInterface::class),
    ]);
    $node->method('getTranslation')->with('de')->willReturnSelf();
    $node->expects($this->once())->method('setUnpublished');
    $node->method('hasField')->willReturn(FALSE);
    $node->expects($this->once())->method('setNewRevision')->with(FALSE);
    $node->expects($this->once())->method('save');
    $node->method('getRevisionId')->willReturn(5);
    $node->method('id')->willReturn(1);
    $node->expects($this->never())->method('label');

    $storage = $this->createMock(NodeStorageInterface::class);
    $storage->method('loadByProperties')->willReturn([$node]);
    $storage->method('getQuery')->willReturn($this->createRevisionQuery([3 => 1, 5 => 1]));
    $storage->method('deleteRevision')->with(3)->willThrowException(new \RuntimeException('Delete failed.'));
    $storage->expects($this->once())->method('resetCache')->with([1]);
    $manager = $this->createMock(EntityTypeManagerInterface::class);
    $manager->method('getStorage')->with('node')->willReturn($storage);

    $transaction = $this->createTransactionSpy();
    $database = $this->createMock(Connection::class);
    $database->method('startTransaction')->willReturn($transaction);
    $messenger = $this->createMock(MessengerInterface::class);
    $messenger->expects($this->once())->method('addError');
    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->once())->method('error');

    $state = (new FormState())->setValue('uuid', $uuid);
    $form_array = [];
    $this->createForm($manager, $database, $messenger, $logger)
      ->submitForm($form_array, $state);
    $this->assertSame(1, $transaction->rollbacks);
  }

  /**
   * A citizen entity deletion failure rolls back and resets both caches.
   */
  public function testCitizenDeletionFailureRollsBack(): void {
    $uuid = '9ac79ba2-07a3-4f8d-9a22-4dbb9c309b77';
    $citizen = $this->createMock(EntityInterface::class);
    $citizen->method('getEntityTypeId')->willReturn('citizen_entity');
    $citizen->method('id')->willReturn(27);
    $citizen_field = $this->createMock(EntityReferenceFieldItemListInterface::class);
    $citizen_field->method('referencedEntities')->willReturn([$citizen]);

    $node = $this->createMock(NodeInterface::class);
    $node->method('bundle')->willReturn('service_request');
    $node->method('access')->with('update')->willReturn(TRUE);
    $node->method('getTranslationLanguages')->willReturn([
      'de' => $this->createMock(LanguageInterface::class),
    ]);
    $node->method('getTranslation')->with('de')->willReturnSelf();
    $node->expects($this->once())->method('setUnpublished');
    $node->method('hasField')->willReturnCallback(
      static fn(string $field_name): bool => $field_name === 'field_citizen',
    );
    $node->method('get')->with('field_citizen')->willReturn($citizen_field);
    $node->expects($this->once())->method('set')->with('field_citizen', NULL);
    $node->expects($this->once())->method('setNewRevision')->with(FALSE);
    $node->expects($this->once())->method('save');
    $node->method('getRevisionId')->willReturn(5);
    $node->method('id')->willReturn(1);
    $node->expects($this->never())->method('label');

    $node_storage = $this->createMock(NodeStorageInterface::class);
    $node_storage->method('loadByProperties')->willReturn([$node]);
    $node_storage->method('getQuery')->willReturn($this->createRevisionQuery([5 => 1]));
    $node_storage->expects($this->once())->method('resetCache')->with([1]);
    $citizen_storage = $this->createMock(EntityStorageInterface::class);
    $citizen_storage->expects($this->once())->method('delete')->with([$citizen])
      ->willThrowException(new \RuntimeException('Delete failed.'));
    $citizen_storage->expects($this->once())->method('resetCache')->with([27]);
    $manager = $this->createMock(EntityTypeManagerInterface::class);
    $manager->method('getStorage')->willReturnMap([
      ['node', $node_storage],
      ['citizen_entity', $citizen_storage],
    ]);

    $transaction = $this->createTransactionSpy();
    $database = $this->createMock(Connection::class);
    $database->method('startTransaction')->willReturn($transaction);
    $messenger = $this->createMock(MessengerInterface::class);
    $messenger->expects($this->once())->method('addError');
    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->once())->method('error');

    $state = (new FormState())->setValue('uuid', $uuid);
    $form_array = [];
    $this->createForm($manager, $database, $messenger, $logger)
      ->submitForm($form_array, $state);
    $this->assertSame(1, $transaction->rollbacks);
  }

  /**
   * A commit failure is reported without touching a destroyed transaction.
   */
  public function testCommitFailureIsHandled(): void {
    $uuid = '9ac79ba2-07a3-4f8d-9a22-4dbb9c309b77';
    $node = $this->createMock(NodeInterface::class);
    $node->method('bundle')->willReturn('service_request');
    $node->method('access')->with('update')->willReturn(TRUE);
    $node->method('getTranslationLanguages')->willReturn([
      'de' => $this->createMock(LanguageInterface::class),
    ]);
    $node->method('getTranslation')->with('de')->willReturnSelf();
    $node->expects($this->once())->method('setUnpublished');
    $node->method('hasField')->willReturn(FALSE);
    $node->expects($this->once())->method('setNewRevision')->with(FALSE);
    $node->expects($this->once())->method('save');
    $node->method('getRevisionId')->willReturn(5);
    $node->method('id')->willReturn(1);
    $node->expects($this->never())->method('label');

    $storage = $this->createMock(NodeStorageInterface::class);
    $storage->method('loadByProperties')->willReturn([$node]);
    $storage->method('getQuery')->willReturn($this->createRevisionQuery([5 => 1]));
    $storage->expects($this->once())->method('resetCache')->with([1]);
    $manager = $this->createMock(EntityTypeManagerInterface::class);
    $manager->method('getStorage')->with('node')->willReturn($storage);

    $database = $this->createMock(Connection::class);
    $database->method('startTransaction')->willReturnCallback(
      static fn(): Transaction => new class extends Transaction {

        public function __construct() {
        }

        /**
         * Simulates a database failure while committing on scope exit.
         */
        public function __destruct() {
          throw new \RuntimeException('Commit failed.');
        }

      },
    );
    $messenger = $this->createMock(MessengerInterface::class);
    $messenger->expects($this->once())->method('addError');
    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->once())->method('error');

    $state = (new FormState())->setValue('uuid', $uuid);
    $form_array = [];
    $this->createForm($manager, $database, $messenger, $logger)
      ->submitForm($form_array, $state);
  }

  /**
   * Invalid identifiers stop before storage or a transaction is touched.
   */
  public function testInvalidUuidFailsClosed(): void {
    $manager = $this->createMock(EntityTypeManagerInterface::class);
    $manager->expects($this->never())->method('getStorage');
    $database = $this->createMock(Connection::class);
    $database->expects($this->never())->method('startTransaction');
    $messenger = $this->createMock(MessengerInterface::class);
    $messenger->expects($this->once())->method('addError');

    $state = (new FormState())->setValue('uuid', 'not-a-uuid');
    $form_array = [];
    $this->createForm($manager, $database, $messenger)
      ->submitForm($form_array, $state);
  }

  /**
   * Creates a query that returns fixed revision IDs.
   */
  private function createRevisionQuery(array $revision_ids): QueryInterface {
    $query = $this->createMock(QueryInterface::class);
    $query->method('allRevisions')->willReturnSelf();
    $query->method('accessCheck')->with(FALSE)->willReturnSelf();
    $query->method('condition')->with('nid', 1)->willReturnSelf();
    $query->method('execute')->willReturn($revision_ids);
    return $query;
  }

  /**
   * Creates a transaction double without invoking the core destructor.
   */
  private function createTransactionSpy(): Transaction {
    return new class extends Transaction {

      /** Number of rollbacks. */
      public int $rollbacks = 0;

      public function __construct() {
      }

      /**
       * Avoids the production transaction manager in this unit double.
       */
      public function __destruct() {
      }

      /**
       * {@inheritdoc}
       */
      public function rollBack() {
        $this->rollbacks++;
      }

    };
  }

  /**
   * Creates the form with controlled infrastructure services.
   */
  private function createForm(
    EntityTypeManagerInterface $manager,
    Connection $database,
    ?MessengerInterface $messenger = NULL,
    ?LoggerInterface $logger = NULL,
  ): GDPRForm {
    $form = new class(
      $messenger ?? $this->createMock(MessengerInterface::class),
      $manager,
      $database,
      $logger ?? $this->createMock(LoggerInterface::class),
    ) extends GDPRForm {

      public function __construct(
        MessengerInterface $messenger,
        EntityTypeManagerInterface $manager,
        Connection $database,
        private readonly LoggerInterface $test_logger,
      ) {
        parent::__construct($messenger, $manager, $database);
      }

      /**
       * {@inheritdoc}
       */
      protected function logger($channel) {
        return $this->test_logger;
      }

    };
    $form->setStringTranslation($this->getStringTranslationStub());
    return $form;
  }

}
