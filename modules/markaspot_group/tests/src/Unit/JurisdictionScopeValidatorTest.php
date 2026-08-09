<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_group\Unit;

use Drupal\Core\Database\Connection;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\UserSession;
use Drupal\markaspot_group\Service\JurisdictionScopeValidator;
use Drupal\Tests\UnitTestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

require_once dirname(__DIR__, 3) . '/src/Service/JurisdictionScopeValidator.php';

/**
 * Tests API-key jurisdiction scope reconciliation.
 *
 * @group markaspot_group
 * @coversDefaultClass \Drupal\markaspot_group\Service\JurisdictionScopeValidator
 */
class JurisdictionScopeValidatorTest extends UnitTestCase {

  /**
   * Tests that one membership allows implicit jurisdiction resolution.
   *
   * @covers ::resolveSubmissionJurisdiction
   */
  public function testSingleMembershipAllowsImplicitJurisdiction(): void {
    $validator = $this->validatorWithAllowed([11]);

    $this->assertSame(11, $validator->resolveSubmissionJurisdiction(NULL, $this->account(7)));
  }

  /**
   * Tests that multiple memberships require an explicit jurisdiction claim.
   *
   * @covers ::resolveSubmissionJurisdiction
   */
  public function testMultipleMembershipsRequireExplicitJurisdiction(): void {
    $validator = $this->validatorWithAllowed([11, 12]);

    try {
      $validator->resolveSubmissionJurisdiction(NULL, $this->account(7));
      $this->fail('Multiple jurisdiction memberships must require a claim.');
    }
    catch (BadRequestHttpException $exception) {
      $this->assertSame('jurisdiction_id required', $exception->getMessage());
      $this->assertStringNotContainsString('11', $exception->getMessage());
      $this->assertStringNotContainsString('12', $exception->getMessage());
    }
  }

  /**
   * Tests that a foreign claimed jurisdiction is denied.
   *
   * @covers ::resolveSubmissionJurisdiction
   */
  public function testForeignClaimIsDenied(): void {
    $validator = $this->validatorWithAllowed([11]);

    $this->expectException(AccessDeniedHttpException::class);
    $this->expectExceptionMessage('key not authorized for jur 12');
    $validator->resolveSubmissionJurisdiction(12, $this->account(7));
  }

  /**
   * Tests that uid 1 does not bypass API-key jurisdiction scope.
   *
   * @covers ::resolveSubmissionJurisdiction
   */
  public function testUidOneHasNoPlatformAdminBypass(): void {
    $validator = $this->validatorWithAllowed([11]);

    $this->expectException(AccessDeniedHttpException::class);
    $validator->resolveSubmissionJurisdiction(12, $this->account(1, ['administrator']));
  }

  /**
   * Tests that an unclaimed read gets the complete granted scope.
   *
   * @covers ::resolveReadScope
   */
  public function testReadWithoutClaimReturnsGrantedScopeWithoutLogging(): void {
    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->never())->method('warning');
    $validator = $this->validatorWithAllowed([11, 12], $logger);

    $this->assertSame([11, 12], $validator->resolveReadScope(NULL, $this->account(7)));
  }

  /**
   * Tests that a claimed read is narrowed to the allowed jurisdiction.
   *
   * @covers ::resolveReadScope
   */
  public function testReadClaimNarrowsGrantedScope(): void {
    $validator = $this->validatorWithAllowed([11, 12]);

    $this->assertSame([12], $validator->resolveReadScope(12, $this->account(7)));
  }

  /**
   * Tests that a foreign read claim remains forbidden.
   *
   * @covers ::resolveReadScope
   */
  public function testForeignReadClaimIsDenied(): void {
    $validator = $this->validatorWithAllowed([11, 12]);

    $this->expectException(AccessDeniedHttpException::class);
    $this->expectExceptionMessage('key not authorized for jur 13');
    $validator->resolveReadScope(13, $this->account(7));
  }

  /**
   * Tests that an API key with no read scope remains forbidden.
   *
   * @covers ::resolveReadScope
   */
  public function testEmptyReadScopeIsDenied(): void {
    $validator = $this->validatorWithAllowed([]);

    $this->expectException(AccessDeniedHttpException::class);
    $this->expectExceptionMessage('key has no jurisdiction scope');
    $validator->resolveReadScope(NULL, $this->account(7));
  }

  /**
   * Creates a validator with controlled allowed jurisdiction IDs.
   *
   * @param int[] $allowed
   *   Allowed jurisdiction IDs.
   * @param \Psr\Log\LoggerInterface|null $logger
   *   Optional logger mock.
   */
  protected function validatorWithAllowed(array $allowed, ?LoggerInterface $logger = NULL): JurisdictionScopeValidator {
    return new class($allowed, $this->createMock(Connection::class), $logger ?? new NullLogger()) extends JurisdictionScopeValidator {

      /**
       * Constructs the test validator.
       *
       * @param int[] $allowed
       *   Allowed jurisdiction IDs.
       * @param \Drupal\Core\Database\Connection $database
       *   The database connection mock.
       * @param \Psr\Log\LoggerInterface $logger
       *   Logger mock.
       */
      public function __construct(
        protected array $allowed,
        Connection $database,
        LoggerInterface $logger,
      ) {
        parent::__construct($database, $logger);
      }

      /**
       * {@inheritdoc}
       */
      public function getAllowedJurisdictionIds(AccountInterface $account): array {
        return $this->allowed;
      }

    };
  }

  /**
   * Creates a lightweight account.
   *
   * @param int $uid
   *   The account user ID.
   * @param string[] $roles
   *   Roles to attach.
   */
  protected function account(int $uid, array $roles = []): UserSession {
    return new UserSession([
      'uid' => $uid,
      'roles' => $roles,
    ]);
  }

}
