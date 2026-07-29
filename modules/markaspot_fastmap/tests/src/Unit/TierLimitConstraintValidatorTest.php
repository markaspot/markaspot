<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_fastmap\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\markaspot_fastmap\Plugin\Validation\Constraint\TierLimitConstraint;
use Drupal\markaspot_fastmap\Plugin\Validation\Constraint\TierLimitConstraintValidator;
use Drupal\markaspot_fastmap\Service\TierConfigService;
use Drupal\markaspot_group\Service\JurisdictionHierarchyResolverInterface;
use Drupal\markaspot_nuxt\Service\CitizenWordingResolver;
use Drupal\markaspot_nuxt\Service\FeatureScopeResolver;
use Drupal\node\NodeInterface;
use Drupal\taxonomy\TermInterface;
use Drupal\Tests\UnitTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

require_once dirname(__DIR__, 3) . '/src/Plugin/Validation/Constraint/TierLimitConstraintValidator.php';

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
   * Uses the configured citizen plural in a visible tier-limit violation.
   *
   * @covers ::resolveLimitMessage
   */
  public function testPublishLimitUsesConfiguredCitizenPlural(): void {
    $node = $this->createPublishTransitionNode(1, wording: 'entry', langcode: 'de');
    $this->mockRequestCount(50);

    $this->executionContext->expects($this->once())
      ->method('addViolation')
      ->with(
        'Das Veröffentlichungskontingent ist erreicht. Einträge: @limit. Heben Sie die Veröffentlichung bestehender Inhalte auf oder wählen Sie einen Tarif.',
        ['@limit' => 50],
      );

    $this->createValidator(citizenWordingResolver: new CitizenWordingResolver())
      ->validate($node, $this->constraint);
  }

  /**
   * Uses the active content locale rather than an unstamped node locale.
   *
   * @covers ::resolveResponseLangcode
   */
  public function testPublishLimitUsesCurrentContentLanguage(): void {
    $node = $this->createPublishTransitionNode(1, wording: 'entry', langcode: 'en');
    $this->mockRequestCount(50);

    $this->executionContext->expects($this->once())
      ->method('addViolation')
      ->with(
        'La limite de publication est atteinte. saisies : @limit. Dépubliez des éléments existants ou choisissez une formule.',
        ['@limit' => 50],
      );

    $this->createValidator(
      citizenWordingResolver: new CitizenWordingResolver(),
      languageManager: $this->createLanguageManager('fr'),
    )->validate($node, $this->constraint);
  }

  /**
   * Tests configured jurisdiction bundle is accepted for tier validation.
   *
   * @covers ::validate
   */
  public function testConfiguredJurisdictionBundleIsAccepted(): void {
    $node = $this->createPublishTransitionNode(1, 'free', 'jurisdiction');
    $this->mockRequestCount(30);

    $this->executionContext->expects($this->never())
      ->method('addViolation');

    $this->createValidator(jurisdictionGroupType: 'jurisdiction')
      ->validate($node, $this->constraint);
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
   * Tests incomplete field configuration is skipped defensively.
   *
   * @covers ::validate
   */
  public function testMissingTierFieldSkipsValidation(): void {
    $node = $this->createNode('service_request', TRUE, 1, NULL, FALSE);

    $this->tierConfig->expects($this->never())
      ->method('countRequests');

    $this->executionContext->expects($this->never())
      ->method('addViolation');

    $this->createValidator()->validate($node, $this->constraint);
  }

  /**
   * Tests self-hosted platform scope skips SaaS tier enforcement.
   *
   * @covers ::validate
   */
  public function testSelfHostedPlatformSkipsTierLimits(): void {
    $this->tierConfig = $this->createMock(TierConfigService::class);
    $this->tierConfig->expects($this->never())->method('getLimits');
    $resolver = $this->createMock(FeatureScopeResolver::class);
    $resolver->method('isSelfServicePlatform')->willReturn(FALSE);
    $node = $this->createPublishTransitionNode(1, 'free');

    $this->executionContext->expects($this->never())
      ->method('addViolation');

    $this->createValidator(featureScopeResolver: $resolver)
      ->validate($node, $this->constraint);
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
      ->willReturnCallback(fn(string $field) => $field === 'field_jurisdiction');
    $node->method('get')
      ->with('field_jurisdiction')
      ->willReturn($jurisdictionField);

    $this->executionContext->expects($this->never())
      ->method('addViolation');

    $this->createValidator()->validate($node, $this->constraint);
  }

  /**
   * Tests that JSON:API-style creates use category jurisdiction before insert.
   *
   * @covers ::validate
   */
  public function testCategoryJurisdictionFallbackBlocksMonthlyCreation(): void {
    $this->tierConfig = $this->createMock(TierConfigService::class);
    $this->tierConfig->method('getLimits')
      ->with('starter')
      ->willReturn(['limit' => 500, 'period' => 'monthly']);
    $this->tierConfig->expects($this->once())
      ->method('countRequests')
      ->with(9, 'monthly')
      ->willReturn(500);

    $node = $this->createNodeWithEmptyJurisdictionAndCategory(9);
    $group = $this->createGroupMock(9, 'starter');

    $this->executionContext->expects($this->once())
      ->method('addViolation')
      ->with(
        $this->constraint->monthlyLimitMessage,
        ['@limit' => 500]
      );

    $this->createValidator($this->createEntityTypeManagerWithGroup($group))
      ->validate($node, $this->constraint);
  }

  /**
   * Tests that API request jurisdiction_id is validated before insert.
   *
   * @covers ::validate
   */
  public function testRequestJurisdictionFallbackBlocksMonthlyCreation(): void {
    $this->tierConfig = $this->createMock(TierConfigService::class);
    $this->tierConfig->method('getLimits')
      ->with('pro')
      ->willReturn(['limit' => 2000, 'period' => 'monthly']);
    $this->tierConfig->expects($this->once())
      ->method('countRequests')
      ->with(10, 'monthly')
      ->willReturn(2000);

    $node = $this->createNodeWithEmptyJurisdictionAndCategory(NULL);
    $group = $this->createGroupMock(10, 'pro');

    $request = new Request([], ['jurisdiction_id' => 10]);
    $requestStack = new RequestStack();
    $requestStack->push($request);

    $this->executionContext->expects($this->once())
      ->method('addViolation')
      ->with(
        $this->constraint->monthlyLimitMessage,
        ['@limit' => 2000]
      );

    $this->createValidator($this->createEntityTypeManagerWithGroup($group), $requestStack)
      ->validate($node, $this->constraint);
  }

  /**
   * Tests that JSON request bodies are used for jurisdiction resolution.
   *
   * @covers ::validate
   */
  public function testJsonBodyJurisdictionFallbackBlocksMonthlyCreation(): void {
    $this->tierConfig = $this->createMock(TierConfigService::class);
    $this->tierConfig->method('getLimits')
      ->with('starter')
      ->willReturn(['limit' => 500, 'period' => 'monthly']);
    $this->tierConfig->expects($this->once())
      ->method('countRequests')
      ->with(11, 'monthly')
      ->willReturn(500);

    $node = $this->createNodeWithEmptyJurisdictionAndCategory(NULL);
    $group = $this->createGroupMock(11, 'starter');

    $request = new Request([], [], [], [], [], [], json_encode(['jurisdiction_id' => 11]));
    $requestStack = new RequestStack();
    $requestStack->push($request);

    $this->executionContext->expects($this->once())
      ->method('addViolation')
      ->with(
        $this->constraint->monthlyLimitMessage,
        ['@limit' => 500]
      );

    $this->createValidator($this->createEntityTypeManagerWithGroup($group), $requestStack)
      ->validate($node, $this->constraint);
  }

  /**
   * Tests that child jurisdictions are matched to the category root.
   *
   * @covers ::validate
   */
  public function testChildRequestJurisdictionMatchesCategoryRoot(): void {
    $this->tierConfig = $this->createMock(TierConfigService::class);
    $this->tierConfig->method('getLimits')
      ->with('starter')
      ->willReturn(['limit' => 500, 'period' => 'monthly']);
    $this->tierConfig->expects($this->once())
      ->method('countRequests')
      ->with(10, 'monthly')
      ->willReturn(500);

    $node = $this->createNodeWithEmptyJurisdictionAndCategory(8);
    $group = $this->createGroupMock(10, 'starter');

    $resolver = $this->createMock(JurisdictionHierarchyResolverInterface::class);
    $resolver->expects($this->exactly(2))
      ->method('getRootJurisdictionId')
      ->willReturnMap([
        [8, 8],
        [10, 8],
      ]);
    $request = new Request([], ['jurisdiction_id' => 10]);
    $requestStack = new RequestStack();
    $requestStack->push($request);

    $this->executionContext->expects($this->once())
      ->method('addViolation')
      ->with(
        $this->constraint->monthlyLimitMessage,
        ['@limit' => 500]
      );

    $this->createValidator($this->createEntityTypeManagerWithGroup($group), $requestStack, $resolver)
      ->validate($node, $this->constraint);
  }

  /**
   * Tests that client-supplied cross-tree jurisdiction is rejected.
   *
   * @covers ::validate
   */
  public function testClientSuppliedJurisdictionMustMatchCategoryRoot(): void {
    $this->tierConfig->expects($this->never())
      ->method('countRequests');

    $fieldGroup = $this->createGroupMock(99, 'heart');
    $node = $this->createNodeWithJurisdictionAndCategory($fieldGroup, 8);

    $resolver = $this->createMock(JurisdictionHierarchyResolverInterface::class);
    $resolver->expects($this->exactly(2))
      ->method('getRootJurisdictionId')
      ->willReturnMap([
        [8, 8],
        [99, 99],
      ]);
    $this->executionContext->expects($this->once())
      ->method('addViolation')
      ->with(TierLimitConstraint::JURISDICTION_MISMATCH_MESSAGE);

    $this->createValidator(NULL, NULL, $resolver)->validate($node, $this->constraint);
  }

  /**
   * Tests that invalid category hierarchy fails closed.
   *
   * @covers ::validate
   */
  public function testInvalidCategoryHierarchyRejectsValidation(): void {
    $this->tierConfig->expects($this->never())
      ->method('countRequests');

    $node = $this->createNodeWithEmptyJurisdictionAndCategory(8);

    $resolver = $this->createMock(JurisdictionHierarchyResolverInterface::class);
    $resolver->expects($this->once())
      ->method('getRootJurisdictionId')
      ->with(8)
      ->willReturn(NULL);

    $request = new Request([], ['jurisdiction_id' => 10]);
    $requestStack = new RequestStack();
    $requestStack->push($request);

    $this->executionContext->expects($this->once())
      ->method('addViolation')
      ->with(TierLimitConstraint::JURISDICTION_MISMATCH_MESSAGE);

    $this->createValidator(NULL, $requestStack, $resolver)->validate($node, $this->constraint);
  }

  /**
   * Tests that same-root sibling request jurisdiction falls back to root.
   *
   * @covers ::validate
   */
  public function testBoundaryMatchOverridesSiblingRequestJurisdiction(): void {
    $this->tierConfig = $this->createMock(TierConfigService::class);
    $this->tierConfig->method('getLimits')
      ->with('free')
      ->willReturn(['limit' => 500, 'period' => 'monthly']);
    $this->tierConfig->expects($this->once())
      ->method('countRequests')
      ->with(10, 'monthly')
      ->willReturn(500);

    $node = $this->createNodeWithEmptyJurisdictionAndCategory(8);
    $rootGroup = $this->createGroupMock(8, 'starter');
    $siblingGroup = $this->createGroupMock(9, 'pro');
    $boundaryGroup = $this->createGroupMock(10, 'free');

    $resolver = $this->createMock(JurisdictionHierarchyResolverInterface::class);
    $resolver->method('getRootJurisdictionId')
      ->willReturnMap([
        [8, 8],
        [9, 8],
        [10, 8],
      ]);

    $request = new Request([], ['jurisdiction_id' => 9]);
    $requestStack = new RequestStack();
    $requestStack->push($request);

    $this->executionContext->expects($this->once())
      ->method('addViolation')
      ->with(
        $this->constraint->monthlyLimitMessage,
        ['@limit' => 500]
      );

    $this->createValidator($this->createEntityTypeManagerWithGroup($rootGroup, $siblingGroup, $boundaryGroup), $requestStack, $resolver, 10)
      ->validate($node, $this->constraint);
  }

  /**
   * Tests that boundary matches must stay in the category root.
   *
   * @covers ::validate
   */
  public function testBoundaryMatchMustMatchCategoryRoot(): void {
    $this->tierConfig->expects($this->never())
      ->method('countRequests');

    $node = $this->createNodeWithEmptyJurisdictionAndCategory(8);

    $resolver = $this->createMock(JurisdictionHierarchyResolverInterface::class);
    $resolver->method('getRootJurisdictionId')
      ->willReturnMap([
        [8, 8],
        [10, 99],
      ]);

    $this->executionContext->expects($this->once())
      ->method('addViolation')
      ->with(TierLimitConstraint::JURISDICTION_MISMATCH_MESSAGE);

    $this->createValidator(NULL, NULL, $resolver, 10)->validate($node, $this->constraint);
  }

  /**
   * Tests that invalid boundary hierarchies fail closed.
   *
   * @covers ::validate
   */
  public function testBoundaryMatchRejectsInvalidHierarchy(): void {
    $this->tierConfig->expects($this->never())
      ->method('countRequests');

    $node = $this->createNodeWithEmptyJurisdictionAndCategory(8);

    $resolver = $this->createMock(JurisdictionHierarchyResolverInterface::class);
    $resolver->method('getRootJurisdictionId')
      ->willReturnMap([
        [8, 8],
        [10, NULL],
      ]);

    $this->executionContext->expects($this->once())
      ->method('addViolation')
      ->with(TierLimitConstraint::JURISDICTION_MISMATCH_MESSAGE);

    $this->createValidator(NULL, NULL, $resolver, 10)->validate($node, $this->constraint);
  }

  /**
   * Creates the validator with mocked context.
   */
  protected function createValidator(
    ?EntityTypeManagerInterface $entityTypeManager = NULL,
    ?RequestStack $requestStack = NULL,
    ?JurisdictionHierarchyResolverInterface $hierarchyResolver = NULL,
    int|false|null $boundaryJurisdictionId = NULL,
    string $jurisdictionGroupType = 'jur',
    ?CitizenWordingResolver $citizenWordingResolver = NULL,
    ?LanguageManagerInterface $languageManager = NULL,
    ?FeatureScopeResolver $featureScopeResolver = NULL,
  ): TierLimitConstraintValidator {
    $configFactory = $this->createConfigFactory($jurisdictionGroupType);
    if ($boundaryJurisdictionId !== NULL) {
      $validator = new class(
        $this->currentUser,
        $this->tierConfig,
        $entityTypeManager,
        $requestStack,
        $hierarchyResolver,
        $configFactory,
        $boundaryJurisdictionId,
      ) extends TierLimitConstraintValidator {

        /**
         * Constructs a test validator with a fixed boundary jurisdiction.
         */
        public function __construct(
          AccountInterface $currentUser,
          TierConfigService $tierConfig,
          ?EntityTypeManagerInterface $entityTypeManager,
          ?RequestStack $requestStack,
          ?JurisdictionHierarchyResolverInterface $hierarchyResolver,
          ?ConfigFactoryInterface $configFactory,
          private readonly int|false $testBoundaryJurisdictionId,
        ) {
          parent::__construct(
            $currentUser,
            $tierConfig,
            $entityTypeManager,
            $requestStack,
            $hierarchyResolver,
            $configFactory,
          );
        }

        /**
         * {@inheritdoc}
         */
        protected function resolveBoundaryJurisdictionId(NodeInterface $node): int|false {
          return $this->testBoundaryJurisdictionId;
        }

      };
    }
    else {
      $validator = new TierLimitConstraintValidator(
        $this->currentUser,
        $this->tierConfig,
        $entityTypeManager,
        $requestStack,
        $hierarchyResolver,
        $configFactory,
        $citizenWordingResolver,
        $languageManager,
        $featureScopeResolver,
      );
    }
    $validator->initialize($this->executionContext);
    return $validator;
  }

  /**
   * Creates a config factory mock for jurisdiction group type.
   */
  protected function createConfigFactory(string $jurisdictionGroupType): ConfigFactoryInterface {
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')
      ->with('jurisdiction_group_type')
      ->willReturn($jurisdictionGroupType);

    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')
      ->with('markaspot_open311.settings')
      ->willReturn($config);

    return $configFactory;
  }

  /**
   * Creates a language manager with a fixed active content locale.
   */
  protected function createLanguageManager(string $langcode): LanguageManagerInterface {
    $language = $this->createMock(LanguageInterface::class);
    $language->method('getId')->willReturn($langcode);

    $languageManager = $this->createMock(LanguageManagerInterface::class);
    $languageManager->method('getCurrentLanguage')
      ->with(LanguageInterface::TYPE_CONTENT)
      ->willReturn($language);

    return $languageManager;
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
    $group->method('bundle')->willReturn('jur');
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

    $jurisdictionField = $this->createJurisdictionField($group);

    // Mock the node.
    $node = $this->createMock(NodeInterface::class);
    $node->method('bundle')->willReturn($bundle);
    $node->method('isNew')->willReturn($isNew);
    $node->method('hasField')
      ->willReturnCallback(fn(string $field) => $field === 'field_jurisdiction');
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
   * @param string $groupBundle
   *   The jurisdiction group bundle.
   * @param string|null $wording
   *   Optional tenant wording preset for the jurisdiction group.
   * @param string $langcode
   *   Content language used for the citizen-facing error detail.
   */
  protected function createPublishTransitionNode(
    int $groupId,
    string $tier = 'free',
    string $groupBundle = 'jur',
    ?string $wording = NULL,
    string $langcode = 'en',
  ): NodeInterface {
    $group = $this->createGroupMock($groupId, $tier, $groupBundle, $wording);
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
      ->willReturnCallback(fn(string $field) => $field === 'field_jurisdiction');
    $node->method('get')
      ->with('field_jurisdiction')
      ->willReturn($jurisdictionField);

    $node->method('getOriginal')->willReturn($original);
    $language = $this->createMock(LanguageInterface::class);
    $language->method('getId')->willReturn($langcode);
    $node->method('language')->willReturn($language);

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
      ->willReturnCallback(fn(string $field) => $field === 'field_jurisdiction');
    $node->method('get')
      ->with('field_jurisdiction')
      ->willReturn($jurisdictionField);

    $node->method('getOriginal')->willReturn($original);

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
      ->willReturnCallback(fn(string $field) => $field === 'field_jurisdiction');
    $node->method('get')
      ->with('field_jurisdiction')
      ->willReturn($jurisdictionField);

    $node->method('getOriginal')->willReturn($original);

    return $node;
  }

  /**
   * Creates a mocked group entity.
   */
  protected function createGroupMock(int $groupId, string $tier = 'free', string $bundle = 'jur', ?string $wording = NULL): GroupInterface {
    $group = $this->createMock(GroupInterface::class);
    $group->method('id')->willReturn($groupId);
    $group->method('bundle')->willReturn($bundle);
    $group->method('hasField')
      ->willReturnCallback(fn(string $name) => $name === 'field_tier' || ($name === 'field_nuxt_config' && $wording !== NULL));

    $tierField = $this->createMock(FieldItemListInterface::class);
    $tierField->method('isEmpty')->willReturn(FALSE);
    $tierField->method('__get')
      ->with('value')
      ->willReturn($tier);

    $configField = $this->createMock(FieldItemListInterface::class);
    $configField->method('isEmpty')->willReturn($wording === NULL);
    $configField->method('__get')
      ->with('value')
      ->willReturn($wording === NULL ? NULL : json_encode(['i18n' => ['wording' => $wording]]));

    $group->method('get')
      ->willReturnCallback(fn(string $name) => match ($name) {
        'field_tier' => $tierField,
        'field_nuxt_config' => $configField,
        default => NULL,
      });
    $group->method('getUntranslated')->willReturnSelf();

    return $group;
  }

  /**
   * Creates a mocked jurisdiction field pointing to a group.
   */
  protected function createJurisdictionField(GroupInterface $group): FieldItemListInterface {
    $field = $this->createMock(FieldItemListInterface::class);
    $field->method('isEmpty')->willReturn(FALSE);
    $field->method('__get')
      ->willReturnCallback(fn(string $name) => match ($name) {
        'entity' => $group,
        'target_id' => $group->id(),
        default => NULL,
      });
    return $field;
  }

  /**
   * Creates a service request node with empty field_jurisdiction.
   */
  protected function createNodeWithEmptyJurisdictionAndCategory(?int $categoryJurisdictionId): NodeInterface {
    $jurisdictionField = $this->createMock(FieldItemListInterface::class);
    $jurisdictionField->method('isEmpty')->willReturn(TRUE);

    $node = $this->createMock(NodeInterface::class);
    $node->method('bundle')->willReturn('service_request');
    $node->method('isNew')->willReturn(TRUE);
    $node->method('hasField')
      ->willReturnCallback(fn(string $field) => in_array($field, ['field_jurisdiction', 'field_category'], TRUE));
    $node->method('get')
      ->willReturnCallback(function (string $field) use ($jurisdictionField, $categoryJurisdictionId): FieldItemListInterface {
        if ($field === 'field_jurisdiction') {
          return $jurisdictionField;
        }
        if ($field === 'field_category' && $categoryJurisdictionId !== NULL) {
          return $this->createCategoryField($categoryJurisdictionId);
        }

        $emptyField = $this->createMock(FieldItemListInterface::class);
        $emptyField->method('isEmpty')->willReturn(TRUE);
        return $emptyField;
      });

    return $node;
  }

  /**
   * Creates a service request node with submitted field_jurisdiction.
   */
  protected function createNodeWithJurisdictionAndCategory(GroupInterface $group, int $categoryJurisdictionId): NodeInterface {
    $jurisdictionField = $this->createJurisdictionField($group);

    $node = $this->createMock(NodeInterface::class);
    $node->method('bundle')->willReturn('service_request');
    $node->method('isNew')->willReturn(TRUE);
    $node->method('hasField')
      ->willReturnCallback(fn(string $field) => in_array($field, ['field_jurisdiction', 'field_category'], TRUE));
    $node->method('get')
      ->willReturnCallback(function (string $field) use ($jurisdictionField, $categoryJurisdictionId): FieldItemListInterface {
        if ($field === 'field_jurisdiction') {
          return $jurisdictionField;
        }
        if ($field === 'field_category') {
          return $this->createCategoryField($categoryJurisdictionId);
        }

        $emptyField = $this->createMock(FieldItemListInterface::class);
        $emptyField->method('isEmpty')->willReturn(TRUE);
        return $emptyField;
      });

    return $node;
  }

  /**
   * Creates a category field pointing to a jurisdiction-scoped term.
   */
  protected function createCategoryField(int $jurisdictionId): FieldItemListInterface {
    $termJurisdictionField = $this->createMock(FieldItemListInterface::class);
    $termJurisdictionField->method('isEmpty')->willReturn(FALSE);
    $termJurisdictionField->method('__get')
      ->with('target_id')
      ->willReturn($jurisdictionId);

    $term = $this->createMock(TermInterface::class);
    $term->method('hasField')
      ->with('field_jurisdiction')
      ->willReturn(TRUE);
    $term->method('get')
      ->with('field_jurisdiction')
      ->willReturn($termJurisdictionField);

    $categoryField = $this->createMock(FieldItemListInterface::class);
    $categoryField->method('isEmpty')->willReturn(FALSE);
    $categoryField->method('__get')
      ->with('entity')
      ->willReturn($term);

    return $categoryField;
  }

  /**
   * Creates an entity type manager that can load one jurisdiction group.
   */
  protected function createEntityTypeManagerWithGroup(GroupInterface ...$groups): EntityTypeManagerInterface {
    $groupsById = [];
    foreach ($groups as $group) {
      $groupsById[(int) $group->id()] = $group;
    }

    $groupStorage = $this->createMock(EntityStorageInterface::class);
    $groupStorage->method('load')
      ->willReturnCallback(fn(int|string $id) => $groupsById[(int) $id] ?? NULL);

    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->method('getStorage')
      ->with('group')
      ->willReturn($groupStorage);

    return $entityTypeManager;
  }

  /**
   * Mocks TierConfigService::countRequests() to return a specific count.
   */
  protected function mockRequestCount(int $count): void {
    $this->tierConfig->method('countRequests')
      ->willReturn($count);
  }

}
