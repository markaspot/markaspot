<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_group\Unit;

use Drupal\Core\Database\Connection;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\UserSession;
use Drupal\markaspot_group\Service\JurisdictionScopeValidator;
use Drupal\Tests\UnitTestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

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

    $this->expectException(BadRequestHttpException::class);
    $this->expectExceptionMessage('jurisdiction_id required');
    $validator->resolveSubmissionJurisdiction(NULL, $this->account(7));
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
   * Creates a validator with controlled allowed jurisdiction IDs.
   *
   * @param int[] $allowed
   *   Allowed jurisdiction IDs.
   */
  protected function validatorWithAllowed(array $allowed): JurisdictionScopeValidator {
    return new class($allowed, $this->createMock(Connection::class)) extends JurisdictionScopeValidator {

      /**
       * Constructs the test validator.
       *
       * @param int[] $allowed
       *   Allowed jurisdiction IDs.
       * @param \Drupal\Core\Database\Connection $database
       *   The database connection mock.
       */
      public function __construct(
        protected array $allowed,
        Connection $database,
      ) {
        parent::__construct($database, new NullLogger());
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
