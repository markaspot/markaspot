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

  /**
   * The mocked current user.
   *
   * @var \Drupal\Core\Session\AccountInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected AccountInterface $currentUser;

  /**
   * The mocked tier config service.
   *
   * @var \Drupal\markaspot_fastmap\Service\TierConfigService|\PHPUnit\Framework\MockObject\MockObject
   */
  protected TierConfigService $tierConfig;

  /**
   * The mocked execution context.
   *
   * @var \Symfony\Component\Validator\Context\ExecutionContextInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected ExecutionContextInterface $executionContext;

  /**
   * The constraint being tested.
   *
   * @var \Drupal\markaspot_fastmap\Plugin\Validation\Constraint\TierLimitConstraint
   */
  protected TierLimitConstraint $constraint;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->currentUser = $this->createMock(AccountInterface::class);
    $this->executionContext = $this->createMock(ExecutionContextInterface::class);
    $this->constraint = new TierLimitConstraint();

    // Default: published period limits (new model).
    $this->tierConfig = $this->createMock(TierConfigService::class);
    $this->tierConfig->method('getLimits')
      ->willReturnCallback(fn(string $tier) => match ($tier) {
        'free' => ['limit' => 50, 'period' => 'published'],
        'starter' => ['limit' => 500, 'period' => 'monthly'],
        'pro' => ['limit' => 2000, 'period' => 'monthly'],
        'heart' => ['limit' => NULL, 'period' => 'published', 'unlimited' => TRUE],
        default => NULL,
      });

    // Default: no admin permissions.
    $this->currentUser->method('hasPermission')
      ->willReturn(FALSE);
  }

  // ---------------------------------------------------------------
  // Published period: new node creation is NOT blocked by validator.
  // (presave hook handles silent unpublish instead.)
  // ---------------------------------------------------------------

  /**
   * Tests that new nodes are NOT rejected for published period.
   *
   * The presave hook handles silent unpublish, not the validator.
   *
   * @covers ::validate
   */
  public function testPublishedPeriodDoesNotBlockNewNodes(): void {
    $node = $this->createNode('service_request', TRUE, 1);
    $this->mockRequestCount(60);

    $this->executionContext->expects($this->never())
      ->method('addViolation');

    $this->createValidator()->validate($node, $this->constraint);
  }

  /**
   * Tests that new nodes under limit are also not blocked.
   *
   * @covers ::validate
   */
  public function testPublishedPeriodAllowsNewNodesUnderLimit(): void {
    $node = $this->createNode('service_request', TRUE, 1);
    $this->mockRequestCount(30);

    $this->executionContext->expects($this->never())
      ->method('addViolation');

    $this->createValidator()->validate($node, $this->constraint);
  }

  // ---------------------------------------------------------------
  // Published period: unpublished -> published transition.
  // ---------------------------------------------------------------

  /**
   * Tests that publish transition is blocked when at limit.
   *
   * @covers ::validate
   */
  public function testPublishTransitionBlockedAtLimit(): void {
    $node = $this->createPublishTransitionNode(1);
    $this->mockRequestCount(50);

    $this->executionContext->expects($this->once())
      ->method('addViolation')
      ->with(
        $this->constraint->publishedLimitMessage,
        ['@limit' => 50]
      );

    $this->createValidator()->validate($node, $this->constraint);
  }

  /**
   * Tests that publish transition is blocked when over limit.
   *
   * @covers ::validate
   */
  public function testPublishTransitionBlockedOverLimit(): void {
    $node = $this->createPublishTransitionNode(1);
    $this->mockRequestCount(60);

    $this->executionContext->expects($this->once())
      ->method('addViolation')
      ->with(
        $this->constraint->publishedLimitMessage,
        ['@limit' => 50]
      );

    $this->createValidator()->validate($node, $this->constraint);
  }

  /**
   * Tests that publish transition is allowed under limit.
   *
   * @covers ::validate
   */
  public function testPublishTransitionAllowedUnderLimit(): void {
    $node = $this->createPublishTransitionNode(1);
    $this->mockRequestCount(30);

    $this->executionContext->expects($this->never())
      ->method('addViolation');

    $this->createValidator()->validate($node, $this->constraint);
  }

  /**
   * Tests that unpublishing is always allowed (not a publish transition).
   *
   * @covers ::validate
   */
  public function testUnpublishAlwaysAllowed(): void {
    $node = $this->createUnpublishTransitionNode(1);
    $this->mockRequestCount(50);

    $this->executionContext->expects($this->never())
      ->method('addViolation');

    $this->createValidator()->validate($node, $this->constraint);
  }

  /**
   * Tests that saving an already-published node (no status change) is allowed.
   *
   * @covers ::validate
   */
  public function testAlreadyPublishedUpdateAllowed(): void {
    $node = $this->createAlreadyPublishedNode(1);
    $this->mockRequestCount(50);

    $this->executionContext->expects($this->never())
      ->method('addViolation');

    $this->createValidator()->validate($node, $this->constraint);
  }

  // ---------------------------------------------------------------
  // Unlimited tiers (heart only).
  // ---------------------------------------------------------------

  /**
   * Tests that unlimited tiers skip validation entirely.
   *
   * @covers ::validate
   */
  public function testUnlimitedTierSkipsValidation(): void {
    $node = $this->createPublishTransitionNode(1, 'heart');

    $this->tierConfig->expects($this->never())
      ->method('countRequests');

    $this->executionContext->expects($this->never())
      ->method('addViolation');

    $this->createValidator()->validate($node, $this->constraint);
  }

  // ---------------------------------------------------------------
  // Starter/Pro monthly limits.
  // ---------------------------------------------------------------

  /**
   * Tests that starter tier blocks at 500 monthly limit.
   *
   * @covers ::validate
   */
  public function testStarterBlocksAtMonthlyLimit(): void {
    $this->tierConfig = $this->createMock(TierConfigService::class);
    $this->tierConfig->method('getLimits')
      ->with('starter')
      ->willReturn(['limit' => 500, 'period' => 'monthly']);
    $this->tierConfig->method('countRequests')
      ->willReturn(500);

    $node = $this->createNode('service_request', TRUE, 1, 'starter');

    $this->executionContext->expects($this->once())
      ->method('addViolation')
      ->with(
        $this->constraint->monthlyLimitMessage,
        ['@limit' => 500]
      );

    $this->createValidator()->validate($node, $this->constraint);
  }

  /**
   * Tests that starter tier allows under 500 monthly limit.
   *
   * @covers ::validate
   */
  public function testStarterAllowsUnderMonthlyLimit(): void {
    $this->tierConfig = $this->createMock(TierConfigService::class);
    $this->tierConfig->method('getLimits')
      ->with('starter')
      ->willReturn(['limit' => 500, 'period' => 'monthly']);
    $this->tierConfig->method('countRequests')
      ->willReturn(200);

    $node = $this->createNode('service_request', TRUE, 1, 'starter');

    $this->executionContext->expects($this->never())
      ->method('addViolation');

    $this->createValidator()->validate($node, $this->constraint);
  }

  /**
   * Tests that pro tier blocks at 2000 monthly limit.
   *
   * @covers ::validate
   */
  public function testProBlocksAtMonthlyLimit(): void {
    $this->tierConfig = $this->createMock(TierConfigService::class);
    $this->tierConfig->method('getLimits')
      ->with('pro')
      ->willReturn(['limit' => 2000, 'period' => 'monthly']);
    $this->tierConfig->method('countRequests')
      ->willReturn(2000);

    $node = $this->createNode('service_request', TRUE, 1, 'pro');

    $this->executionContext->expects($this->once())
      ->method('addViolation')
      ->with(
        $this->constraint->monthlyLimitMessage,
        ['@limit' => 2000]
      );

    $this->createValidator()->validate($node, $this->constraint);
  }

  /**
   * Tests that pro tier allows under 2000 monthly limit.
   *
   * @covers ::validate
   */
  public function testProAllowsUnderMonthlyLimit(): void {
    $this->tierConfig = $this->createMock(TierConfigService::class);
    $this->tierConfig->method('getLimits')
      ->with('pro')
      ->willReturn(['limit' => 2000, 'period' => 'monthly']);
    $this->tierConfig->method('countRequests')
      ->willReturn(500);

    $node = $this->createNode('service_request', TRUE, 1, 'pro');

    $this->executionContext->expects($this->never())
      ->method('addViolation');

    $this->createValidator()->validate($node, $this->constraint);
  }

  // ---------------------------------------------------------------
  // Legacy monthly/total periods (backwards compatibility).
  // ---------------------------------------------------------------

  /**
   * Tests that monthly period blocks new node creation at limit.
   *
   * @covers ::validate
   */
  public function testMonthlyPeriodBlocksNewCreation(): void {
    $this->tierConfig = $this->createMock(TierConfigService::class);
    $this->tierConfig->method('getLimits')
      ->willReturn(['limit' => 50, 'period' => 'monthly']);
    $this->tierConfig->method('countRequests')
      ->willReturn(50);

    $node = $this->createNode('service_request', TRUE, 1);

    $this->executionContext->expects($this->once())
      ->method('addViolation')
      ->with(
        $this->constraint->monthlyLimitMessage,
        ['@limit' => 50]
      );

    $this->createValidator()->validate($node, $this->constraint);
  }

  /**
   * Tests that monthly period allows new creation under limit.
   *
   * @covers ::validate
   */
  public function testMonthlyPeriodAllowsUnderLimit(): void {
    $this->tierConfig = $this->createMock(TierConfigService::class);
    $this->tierConfig->method('getLimits')
      ->willReturn(['limit' => 50, 'period' => 'monthly']);
    $this->tierConfig->method('countRequests')
      ->willReturn(30);

    $node = $this->createNode('service_request', TRUE, 1);

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
   * Tests published period uses publishedLimitMessage.
   *
   * @covers ::validate
   */
  public function testPublishedPeriodUsesCorrectMessage(): void {
    $node = $this->createPublishTransitionNode(1);
    $this->mockRequestCount(50);

    $this->executionContext->expects($this->once())
      ->method('addViolation')
      ->with(
        $this->constraint->publishedLimitMessage,
        ['@limit' => 50]
      );

    $this->createValidator()->validate($node, $this->constraint);
  }

  // ---------------------------------------------------------------
  // Edge cases and bypass.
  // ---------------------------------------------------------------

  /**
   * Tests that unknown tier falls back to free limits (fail-closed).
   *
   * @covers ::validate
   */
  public function testUnknownTierFallsBackToFreeLimits(): void {
    $this->tierConfig = $this->createMock(TierConfigService::class);
    $this->tierConfig->method('getLimits')
      ->with('bogus_tier')
      ->willReturn(['limit' => 50, 'period' => 'published']);
    $this->tierConfig->method('countRequests')
      ->willReturn(50);

    $node = $this->createPublishTransitionNode(1, 'bogus_tier');

    $this->executionContext->expects($this->once())
      ->method('addViolation')
      ->with(
        $this->constraint->publishedLimitMessage,
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

    $node = $this->createPublishTransitionNode(1);
    $this->mockRequestCount(50);

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

    $node = $this->createPublishTransitionNode(1);
    $this->mockRequestCount(50);

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
   * Creates a node simulating unpublished -> published transition.
   *
   * @param int $groupId
   *   The jurisdiction group ID.
   * @param string $tier
   *   The tier value.
   */
  protected function createPublishTransitionNode(int $groupId, string $tier = 'free'): NodeInterface {
    $group = $this->createGroupMock($groupId, $tier);
    $jurisdictionField = $this->createJurisdictionField($group);

    // Original node was unpublished.
    $original = $this->createMock(NodeInterface::class);
    $original->method('isPublished')->willReturn(FALSE);

    // Current node is published (transition).
    $node = $this->createMock(NodeInterface::class);
    $node->method('bundle')->willReturn('service_request');
    $node->method('isNew')->willReturn(FALSE);
    $node->method('isPublished')->willReturn(TRUE);
    $node->method('hasField')
      ->with('field_jurisdiction')
      ->willReturn(TRUE);
    $node->method('get')
      ->with('field_jurisdiction')
      ->willReturn($jurisdictionField);

    // PHP 8.4 deprecation: dynamic property on mock. Acceptable in test code
    // until PHPUnit mocks support declared properties natively.
    @$node->original = $original;

    return $node;
  }

  /**
   * Creates a node simulating published -> unpublished transition.
   *
   * @param int $groupId
   *   The jurisdiction group ID.
   * @param string $tier
   *   The tier value.
   */
  protected function createUnpublishTransitionNode(int $groupId, string $tier = 'free'): NodeInterface {
    $group = $this->createGroupMock($groupId, $tier);
    $jurisdictionField = $this->createJurisdictionField($group);

    // Original node was published.
    $original = $this->createMock(NodeInterface::class);
    $original->method('isPublished')->willReturn(TRUE);

    // Current node is unpublished (transition).
    $node = $this->createMock(NodeInterface::class);
    $node->method('bundle')->willReturn('service_request');
    $node->method('isNew')->willReturn(FALSE);
    $node->method('isPublished')->willReturn(FALSE);
    $node->method('hasField')
      ->with('field_jurisdiction')
      ->willReturn(TRUE);
    $node->method('get')
      ->with('field_jurisdiction')
      ->willReturn($jurisdictionField);

    @$node->original = $original;

    return $node;
  }

  /**
   * Creates a node that is already published (no status change).
   *
   * @param int $groupId
   *   The jurisdiction group ID.
   * @param string $tier
   *   The tier value.
   */
  protected function createAlreadyPublishedNode(int $groupId, string $tier = 'free'): NodeInterface {
    $group = $this->createGroupMock($groupId, $tier);
    $jurisdictionField = $this->createJurisdictionField($group);

    // Original was also published.
    $original = $this->createMock(NodeInterface::class);
    $original->method('isPublished')->willReturn(TRUE);

    $node = $this->createMock(NodeInterface::class);
    $node->method('bundle')->willReturn('service_request');
    $node->method('isNew')->willReturn(FALSE);
    $node->method('isPublished')->willReturn(TRUE);
    $node->method('hasField')
      ->with('field_jurisdiction')
      ->willReturn(TRUE);
    $node->method('get')
      ->with('field_jurisdiction')
      ->willReturn($jurisdictionField);

    @$node->original = $original;

    return $node;
  }

  /**
   * Creates a mocked group entity.
   */
  protected function createGroupMock(int $groupId, string $tier = 'free'): GroupInterface {
    $group = $this->createMock(GroupInterface::class);
    $group->method('id')->willReturn($groupId);
    $group->method('hasField')
      ->willReturnCallback(fn(string $name) => $name === 'field_tier');

    $tierField = $this->createMock(FieldItemListInterface::class);
    $tierField->method('isEmpty')->willReturn(FALSE);
    $tierField->method('__get')
      ->with('value')
      ->willReturn($tier);

    $group->method('get')
      ->with('field_tier')
      ->willReturn($tierField);

    return $group;
  }

  /**
   * Creates a mocked jurisdiction field pointing to a group.
   */
  protected function createJurisdictionField(GroupInterface $group): FieldItemListInterface {
    $field = $this->createMock(FieldItemListInterface::class);
    $field->method('isEmpty')->willReturn(FALSE);
    $field->method('__get')
      ->with('entity')
      ->willReturn($group);
    return $field;
  }

  /**
   * Mocks TierConfigService::countRequests() to return a specific count.
   */
  protected function mockRequestCount(int $count): void {
    $this->tierConfig->method('countRequests')
      ->willReturn($count);
  }

}
