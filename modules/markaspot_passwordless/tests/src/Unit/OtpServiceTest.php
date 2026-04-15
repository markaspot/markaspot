<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_passwordless\Unit;

use Drupal\Core\Database\Query\Delete;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Query\Insert;
use Drupal\Core\Database\Query\Select;
use Drupal\Core\Database\Query\Update;
use Drupal\Core\Database\StatementInterface;
use Drupal\Core\Database\Transaction;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\markaspot_passwordless\Service\OtpService;
use Drupal\Tests\UnitTestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests OTP jurisdiction binding.
 *
 * Guards against cross-tenant OTP reuse: a code issued by tenant A must
 * not be consumable on tenant B even when both tenants enable passwordless
 * auth and share an account by email.
 *
 * @group markaspot_passwordless
 * @coversDefaultClass \Drupal\markaspot_passwordless\Service\OtpService
 */
class OtpServiceTest extends UnitTestCase {

  /**
   * Mocked database connection.
   *
   * @var \Drupal\Core\Database\Connection|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $database;

  /**
   * Builds an OtpService with a database mock and sensible stubs.
   */
  protected function buildService(Connection $database): OtpService {
    $mail = $this->createMock(MailManagerInterface::class);
    $mail->method('mail')->willReturn(['result' => TRUE]);

    $currentUser = $this->createMock(AccountProxyInterface::class);
    $logger = $this->createMock(LoggerInterface::class);

    $settings = $this->createMock(ImmutableConfig::class);
    $settings->method('get')->willReturnMap([
      ['code_lifetime', 600],
      ['max_attempts', 3],
      ['auto_register', FALSE],
    ]);

    $siteConfig = $this->createMock(ImmutableConfig::class);
    $siteConfig->method('get')->willReturnMap([
      ['name', 'Mark-a-Spot'],
    ]);

    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')->willReturnMap([
      ['markaspot_passwordless.settings', $settings],
      ['system.site', $siteConfig],
    ]);

    // Group storage: load() returns NULL so sendCode falls back to the
    // site name for the email platform string — the jurisdiction label
    // is not what these tests assert on.
    $groupStorage = $this->createMock(EntityStorageInterface::class);
    $groupStorage->method('load')->willReturn(NULL);
    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->method('getStorage')->willReturn($groupStorage);

    $language = $this->createMock(LanguageInterface::class);
    $language->method('getId')->willReturn('en');
    $languageManager = $this->createMock(LanguageManagerInterface::class);
    $languageManager->method('getCurrentLanguage')->willReturn($language);

    $moduleHandler = $this->createMock(ModuleHandlerInterface::class);

    return new OtpService(
      $database,
      $mail,
      $currentUser,
      $logger,
      $configFactory,
      $entityTypeManager,
      $languageManager,
      $moduleHandler,
    );
  }

  /**
   * Builds a Transaction stand-in without entering the DB lifecycle.
   */
  protected function fakeTransaction(): Transaction {
    return new class() extends Transaction {

      // phpcs:ignore Drupal.Commenting.FunctionComment.Missing
      public function __construct() {
        // Skip parent constructor to avoid readonly property init and
        // Database::commitAllOnShutdown() registration.
      }

      // phpcs:ignore Drupal.Commenting.FunctionComment.Missing
      public function __destruct() {
        // No-op — parent destructor dereferences the uninitialized
        // connection property.
      }

    };
  }

  /**
   * Creates an Update mock that records and chains condition() calls.
   *
   * @param array $recordedConditions
   *   Output bag, populated with [field => value] pairs as the code
   *   calls ->condition() on the returned mock.
   */
  protected function recordingUpdate(array &$recordedConditions): Update {
    $update = $this->createMock(Update::class);
    $update->method('fields')->willReturnSelf();
    $update->method('condition')->willReturnCallback(
      function ($field, $value = NULL) use ($update, &$recordedConditions) {
        $recordedConditions[$field] = $value;
        return $update;
      }
    );
    $update->method('execute')->willReturn(0);
    return $update;
  }

  /**
   * Creates a Select mock that records condition() calls and returns rows.
   */
  protected function recordingSelect(array &$recordedConditions, array $rows = []): Select {
    $statement = $this->createMock(StatementInterface::class);
    $statement->method('fetchAll')->willReturn($rows);

    $select = $this->createMock(Select::class);
    $select->method('fields')->willReturnSelf();
    $select->method('orderBy')->willReturnSelf();
    $select->method('condition')->willReturnCallback(
      function ($field, $value = NULL) use ($select, &$recordedConditions) {
        $recordedConditions[$field] = $value;
        return $select;
      }
    );
    $select->method('execute')->willReturn($statement);
    return $select;
  }

  /**
   * RequestCode binds the inserted row to the issuing jurisdiction.
   *
   * @covers ::requestCode
   */
  public function testRequestCodeInsertsJurisdictionId(): void {
    $insertedFields = NULL;

    $insert = $this->createMock(Insert::class);
    $insert->method('fields')->willReturnCallback(
      function (array $fields) use ($insert, &$insertedFields) {
        $insertedFields = $fields;
        return $insert;
      }
    );
    $insert->method('execute')->willReturn(1);

    $updateConditions = [];
    $update = $this->recordingUpdate($updateConditions);
    $delete = $this->createMock(Delete::class);
    $delete->method('condition')->willReturnSelf();
    $delete->method('execute')->willReturn(0);

    $database = $this->createMock(Connection::class);
    $database->method('delete')->willReturn($delete);
    $database->method('update')->willReturn($update);
    $database->method('insert')->willReturn($insert);

    $service = $this->buildService($database);
    $service->requestCode('user@example.com', 42);

    $this->assertIsArray($insertedFields);
    $this->assertArrayHasKey('jurisdiction_id', $insertedFields);
    $this->assertSame(42, $insertedFields['jurisdiction_id']);
    $this->assertSame('user@example.com', $insertedFields['email']);

    $this->assertSame(42, $updateConditions['jurisdiction_id']);
    $this->assertSame('user@example.com', $updateConditions['email']);
  }

  /**
   * VerifyCode filters the lookup by jurisdiction_id.
   *
   * @covers ::verifyCode
   */
  public function testVerifyCodeFiltersByJurisdictionId(): void {
    $selectConditions = [];
    $select = $this->recordingSelect($selectConditions, []);

    // Transaction has readonly typed $connection via constructor promotion
    // and a __destruct() that dereferences it. Instead of mocking the full
    // lifecycle we return a subclass that short-circuits construction and
    // destruction — the service only stores the reference, it never reads it.
    $transaction = $this->fakeTransaction();
    $database = $this->createMock(Connection::class);
    $database->method('startTransaction')->willReturn($transaction);
    $database->method('select')->willReturn($select);

    $service = $this->buildService($database);
    $service->verifyCode('user@example.com', '123456', 42);

    $this->assertArrayHasKey('jurisdiction_id', $selectConditions);
    $this->assertSame(42, $selectConditions['jurisdiction_id']);
    $this->assertSame('user@example.com', $selectConditions['email']);
    $this->assertSame(0, $selectConditions['verified']);
  }

  /**
   * Cross-tenant verify finds nothing and returns an invalid-code error.
   *
   * Simulates the issue's scenario: OTP issued for jurisdiction 42,
   * verification attempted against jurisdiction 5. The SQL filter yields
   * zero rows (the record exists but is bound to 42), so verification
   * fails with the same generic error as any other invalid code.
   *
   * @covers ::verifyCode
   */
  public function testVerifyCodeCrossTenantRejected(): void {
    $selectConditions = [];
    // Empty row set models the DB filter rejecting the mismatched tenant.
    $select = $this->recordingSelect($selectConditions, []);

    // Transaction has readonly typed $connection via constructor promotion
    // and a __destruct() that dereferences it. Instead of mocking the full
    // lifecycle we return a subclass that short-circuits construction and
    // destruction — the service only stores the reference, it never reads it.
    $transaction = $this->fakeTransaction();
    $database = $this->createMock(Connection::class);
    $database->method('startTransaction')->willReturn($transaction);
    $database->method('select')->willReturn($select);

    $service = $this->buildService($database);
    $result = $service->verifyCode('user@example.com', '123456', 5);

    $this->assertFalse($result['success']);
    $this->assertSame('Invalid verification code', $result['error']);
    $this->assertSame(5, $selectConditions['jurisdiction_id']);
  }

}
