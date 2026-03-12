<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_fastmap\Unit;

use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\markaspot_fastmap\Plugin\Validation\Constraint\TierLimitConstraint;
use Drupal\markaspot_fastmap\Plugin\Validation\Constraint\TierLimitConstraintValidator;
use Drupal\markaspot_fastmap\Service\TierConfigService;
use Drupal\node\NodeInterface;
use Drupal\Tests\UnitTestCase;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Tests the TierLimitConstraintValidator.
 *
 * @group markaspot_fastmap
 * @coversDefaultClass \Drupal\markaspot_fastmap\Plugin\Validation\Constraint\TierLimitConstraintValidator
 */
class TierLimitConstraintValidatorTest extends UnitTestCase {

  protected AccountInterface $currentUser;

  protected TierConfigService $tierConfig;

  protected ExecutionContextInterface $executionContext;

  protected TierLimitConstraint $constraint;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->currentUser = $this->createMock(AccountInterface::class);
    $this->executionContext = $this->createMock(ExecutionContextInterface::class);
    $this->constraint = new TierLimitConstraint();

    // Mock TierConfigService with default limits.
    $this->tierConfig = $this->createMock(TierConfigService::class);
    $this->tierConfig->method('getLimits')
      ->willReturnCallback(fn(string $tier) => match ($tier) {
        'free' => ['limit' => 50, 'period' => 'monthly'],
        'starter' => ['limit' => 500, 'period' => 'monthly'],
        'pro' => ['limit' => 2000, 'period' => 'monthly'],
        'heart' => ['limit' => 500, 'period' => 'monthly'],
        default => NULL,
      });

    // Default: no admin permissions.
    $this->currentUser->method('hasPermission')
      ->willReturn(FALSE);
  }

  /**
   * Tests that free tier blocks creation when over limit.
   *
   * @covers ::validate
   */
  public function testFreeTierBlocksWhenOverLimit(): void {
    $node = $this->createNode('service_request', TRUE, 1);
    $this->mockRequestCount(60);

    $this->executionContext->expects($this->once())
      ->method('addViolation')
      ->with(
        $this->constraint->monthlyLimitMessage,
        ['@limit' => 50]
      );

    $this->createValidator()->validate($node, $this->constraint);
  }

  /**
   * Tests that free tier allows creation when under monthly limit.
   *
   * @covers ::validate
   */
  public function testFreeTierAllowsWhenUnderLimit(): void {
    $node = $this->createNode('service_request', TRUE, 1);
    $this->mockRequestCount(30);

    $this->executionContext->expects($this->never())
      ->method('addViolation');

    $this->createValidator()->validate($node, $this->constraint);
  }

  /**
   * Tests that free tier blocks at exactly the monthly limit.
   *
   * @covers ::validate
   */
  public function testFreeTierBlocksAtExactLimit(): void {
    $node = $this->createNode('service_request', TRUE, 1);
    $this->mockRequestCount(50);

    $this->executionContext->expects($this->once())
      ->method('addViolation');

    $this->createValidator()->validate($node, $this->constraint);
  }

  /**
   * Tests that starter tier uses monthly counting.
   *
   * @covers ::validate
   */
  public function testStarterTierBlocksMonthlyLimit(): void {
    $node = $this->createNode('service_request', TRUE, 1, 'starter');
    $this->mockRequestCount(500);

    $this->executionContext->expects($this->once())
      ->method('addViolation')
      ->with(
        $this->constraint->monthlyLimitMessage,
        ['@limit' => 500]
      );

    $this->createValidator()->validate($node, $this->constraint);
  }

  /**
   * Tests that starter tier allows when under monthly limit.
   *
   * @covers ::validate
   */
  public function testStarterTierAllowsUnderMonthlyLimit(): void {
    $node = $this->createNode('service_request', TRUE, 1, 'starter');
    $this->mockRequestCount(200);

    $this->executionContext->expects($this->never())
      ->method('addViolation');

    $this->createValidator()->validate($node, $this->constraint);
  }

  /**
   * Tests that pro tier enforces 2000/month limit.
   *
   * @covers ::validate
   */
  public function testProTierBlocksMonthlyLimit(): void {
    $node = $this->createNode('service_request', TRUE, 1, 'pro');
    $this->mockRequestCount(2000);

    $this->executionContext->expects($this->once())
      ->method('addViolation')
      ->with(
        $this->constraint->monthlyLimitMessage,
        ['@limit' => 2000]
      );

    $this->createValidator()->validate($node, $this->constraint);
  }

  /**
   * Tests that pro tier allows under limit.
   *
   * @covers ::validate
   */
  public function testProTierAllowsUnderLimit(): void {
    $node = $this->createNode('service_request', TRUE, 1, 'pro');
    $this->mockRequestCount(1500);

    $this->executionContext->expects($this->never())
      ->method('addViolation');

    $this->createValidator()->validate($node, $this->constraint);
  }

  /**
   * Tests that heart tier enforces 500/month (same as starter).
   *
   * @covers ::validate
   */
  public function testHeartTierBlocksMonthlyLimit(): void {
    $node = $this->createNode('service_request', TRUE, 1, 'heart');
    $this->mockRequestCount(500);

    $this->executionContext->expects($this->once())
      ->method('addViolation')
      ->with(
        $this->constraint->monthlyLimitMessage,
        ['@limit' => 500]
      );

    $this->createValidator()->validate($node, $this->constraint);
  }

  /**
   * Tests that heart tier allows under limit.
   *
   * @covers ::validate
   */
  public function testHeartTierAllowsUnderLimit(): void {
    $node = $this->createNode('service_request', TRUE, 1, 'heart');
    $this->mockRequestCount(200);

    $this->executionContext->expects($this->never())
      ->method('addViolation');

    $this->createValidator()->validate($node, $this->constraint);
  }

  /**
   * Tests that total period uses totalLimitMessage.
   *
   * @covers ::validate
   */
  public function testTotalPeriodUsesTotalMessage(): void {
    // Override getLimits for this test to return 'total' period.
    $this->tierConfig = $this->createMock(TierConfigService::class);
    $this->tierConfig->method('getLimits')
      ->willReturn(['limit' => 100, 'period' => 'total']);
    $this->tierConfig->method('countRequests')
      ->willReturn(100);

    $node = $this->createNode('service_request', TRUE, 1);

    $this->executionContext->expects($this->once())
      ->method('addViolation')
      ->with(
        $this->constraint->totalLimitMessage,
        ['@limit' => 100]
      );

    $this->createValidator()->validate($node, $this->constraint);
  }

  /**
   * Tests that total period allows under limit.
   *
   * @covers ::validate
   */
  public function testTotalPeriodAllowsUnderLimit(): void {
    $this->tierConfig = $this->createMock(TierConfigService::class);
    $this->tierConfig->method('getLimits')
      ->willReturn(['limit' => 100, 'period' => 'total']);
    $this->tierConfig->method('countRequests')
      ->willReturn(50);

    $node = $this->createNode('service_request', TRUE, 1);

    $this->executionContext->expects($this->never())
      ->method('addViolation');

    $this->createValidator()->validate($node, $this->constraint);
  }

  /**
   * Tests that unknown tier falls back to free limits (fail-closed).
   *
   * @covers ::validate
   */
  public function testUnknownTierFallsBackToFreeLimits(): void {
    // TierConfigService with fail-closed behavior: unknown tier returns free limits.
    $this->tierConfig = $this->createMock(TierConfigService::class);
    $this->tierConfig->method('getLimits')
      ->with('bogus_tier')
      ->willReturn(['limit' => 50, 'period' => 'monthly']);
    $this->tierConfig->method('countRequests')
      ->willReturn(50);

    $node = $this->createNode('service_request', TRUE, 1, 'bogus_tier');

    $this->executionContext->expects($this->once())
      ->method('addViolation')
      ->with(
        $this->constraint->monthlyLimitMessage,
        ['@limit' => 50]
      );

    $this->createValidator()->validate($node, $this->constraint);
  }

  /**
   * Tests that no field_tier (on-premise) means no limits.
   *
   * @covers ::validate
   */
  public function testNoTierFieldMeansUnlimited(): void {
    $node = $this->createNode('service_request', TRUE, 1, NULL, FALSE);

    $this->tierConfig->expects($this->never())
      ->method('countRequests');

    $this->executionContext->expects($this->never())
      ->method('addViolation');

    $this->createValidator()->validate($node, $this->constraint);
  }

  /**
   * Tests that existing nodes (updates) are not checked.
   *
   * @covers ::validate
   */
  public function testUpdatesAreSkipped(): void {
    $node = $this->createNode('service_request', FALSE, 1);

    $this->executionContext->expects($this->never())
      ->method('addViolation');

    $this->createValidator()->validate($node, $this->constraint);
  }

  /**
   * Tests that non-service-request nodes are skipped.
   *
   * @covers ::validate
   */
  public function testNonServiceRequestSkipped(): void {
    $node = $this->createMock(NodeInterface::class);
    $node->method('bundle')->willReturn('page');

    $this->executionContext->expects($this->never())
      ->method('addViolation');

    $this->createValidator()->validate($node, $this->constraint);
  }

  /**
   * Tests that non-NodeInterface values are skipped.
   *
   * @covers ::validate
   */
  public function testNonNodeSkipped(): void {
    $this->executionContext->expects($this->never())
      ->method('addViolation');

    $this->createValidator()->validate('not a node', $this->constraint);
  }

  /**
   * Tests that admin users bypass tier limits.
   *
   * @covers ::validate
   */
  public function testAdminBypassesLimit(): void {
    $this->currentUser = $this->createMock(AccountInterface::class);
    $this->currentUser->method('hasPermission')
      ->willReturnCallback(fn(string $perm) => $perm === 'administer nodes');

    $node = $this->createNode('service_request', TRUE, 1);

    $this->executionContext->expects($this->never())
      ->method('addViolation');

    $this->createValidator()->validate($node, $this->constraint);
  }

  /**
   * Tests that bypass mas validation permission skips tier check.
   *
   * @covers ::validate
   */
  public function testBypassValidationPermission(): void {
    $this->currentUser = $this->createMock(AccountInterface::class);
    $this->currentUser->method('hasPermission')
      ->willReturnCallback(fn(string $perm) => $perm === 'bypass mas validation');

    $node = $this->createNode('service_request', TRUE, 1);

    $this->executionContext->expects($this->never())
      ->method('addViolation');

    $this->createValidator()->validate($node, $this->constraint);
  }

  /**
   * Tests node without jurisdiction field.
   *
   * @covers ::validate
   */
  public function testNoJurisdictionFieldSkips(): void {
    $node = $this->createMock(NodeInterface::class);
    $node->method('bundle')->willReturn('service_request');
    $node->method('isNew')->willReturn(TRUE);
    $node->method('hasField')
      ->with('field_jurisdiction')
      ->willReturn(FALSE);

    $this->executionContext->expects($this->never())
      ->method('addViolation');

    $this->createValidator()->validate($node, $this->constraint);
  }

  /**
   * Tests node with empty jurisdiction field.
   *
   * @covers ::validate
   */
  public function testEmptyJurisdictionFieldSkips(): void {
    $jurisdictionField = $this->createMock(FieldItemListInterface::class);
    $jurisdictionField->method('isEmpty')->willReturn(TRUE);

    $node = $this->createMock(NodeInterface::class);
    $node->method('bundle')->willReturn('service_request');
    $node->method('isNew')->willReturn(TRUE);
    $node->method('hasField')
      ->with('field_jurisdiction')
      ->willReturn(TRUE);
    $node->method('get')
      ->with('field_jurisdiction')
      ->willReturn($jurisdictionField);

    $this->executionContext->expects($this->never())
      ->method('addViolation');

    $this->createValidator()->validate($node, $this->constraint);
  }

  /**
   * Creates the validator with mocked context.
   */
  protected function createValidator(): TierLimitConstraintValidator {
    $validator = new TierLimitConstraintValidator(
      $this->currentUser,
      $this->tierConfig,
    );
    $validator->initialize($this->executionContext);
    return $validator;
  }

  /**
   * Creates a mocked node with jurisdiction group.
   *
   * @param string $bundle
   *   The node bundle.
   * @param bool $isNew
   *   Whether the node is new.
   * @param int $groupId
   *   The jurisdiction group ID.
   * @param string|null $tier
   *   The tier value.
   * @param bool $hasTierField
   *   Whether the group has field_tier.
   */
  protected function createNode(
    string $bundle,
    bool $isNew,
    int $groupId,
    ?string $tier = 'free',
    bool $hasTierField = TRUE,
  ): NodeInterface {
    // Mock the group entity.
    $group = $this->createMock(GroupInterface::class);
    $group->method('id')->willReturn($groupId);
    $group->method('hasField')
      ->willReturnCallback(fn(string $name) => $name === 'field_tier' && $hasTierField);

    if ($hasTierField) {
      $tierField = $this->createMock(FieldItemListInterface::class);
      $tierField->method('isEmpty')->willReturn(FALSE);
      $tierField->method('__get')
        ->with('value')
        ->willReturn($tier);

      $group->method('get')
        ->with('field_tier')
        ->willReturn($tierField);
    }

    // Mock the jurisdiction field on the node.
    $jurisdictionField = $this->createMock(FieldItemListInterface::class);
    $jurisdictionField->method('isEmpty')->willReturn(FALSE);
    $jurisdictionField->method('__get')
      ->with('entity')
      ->willReturn($group);

    // Mock the node.
    $node = $this->createMock(NodeInterface::class);
    $node->method('bundle')->willReturn($bundle);
    $node->method('isNew')->willReturn($isNew);
    $node->method('hasField')
      ->with('field_jurisdiction')
      ->willReturn(TRUE);
    $node->method('get')
      ->with('field_jurisdiction')
      ->willReturn($jurisdictionField);

    return $node;
  }

  /**
   * Mocks TierConfigService::countRequests() to return a specific count.
   */
  protected function mockRequestCount(int $count): void {
    $this->tierConfig->method('countRequests')
      ->willReturn($count);
  }

}
