<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_dashboard\Unit;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Field\EntityReferenceFieldItemListInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\markaspot_dashboard\Service\SplitRequestContext;
use Drupal\markaspot_nuxt\Service\FeatureFlagChecker;
use Drupal\markaspot_nuxt\Service\FeatureScopeResolver;
use Drupal\media\MediaInterface;
use Drupal\node\NodeInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Tests consent enforcement during internal splits and ordinary creates.
 */
#[Group('markaspot_dashboard')]
#[Group('markaspot_nuxt')]
class SplitRequestContextTest extends UnitTestCase {

  /**
   * The internal split save context.
   */
  private SplitRequestContext $context;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    require_once dirname(__DIR__, 4) . '/markaspot_nuxt/markaspot_nuxt.module';
    $this->context = new SplitRequestContext();
    $checker = $this->createMock(FeatureFlagChecker::class);
    $checker->method('isEnabled')->with('fields.field_gdpr.required', NULL, FALSE)->willReturn(TRUE);
    $container = new ContainerBuilder();
    $container->set('request_stack', new RequestStack());
    $container->set('markaspot_nuxt.feature_flag_checker', $checker);
    $container->set('markaspot_dashboard.split_context', $this->context);
    \Drupal::setContainer($container);
  }

  /**
   * Ordinary new submissions still require consent.
   */
  public function testOrdinaryCreateStillRequiresConsent(): void {
    $this->expectException(UnprocessableEntityHttpException::class);
    markaspot_nuxt_node_presave($this->newReportWithoutConsent());
  }

  /**
   * A split child is saved without fabricating consent.
   */
  public function testInternalChildKeepsConsentUnchanged(): void {
    $child = $this->newReportWithoutConsent();
    $child->expects($this->once())->method('save')->willReturnCallback(function () use ($child): int {
      $this->assertTrue($this->context->isSavingChild($child));
      markaspot_nuxt_node_presave($child);
      return 1;
    });
    $this->context->saveChild($child);
    $this->assertFalse($this->context->isSavingChild($child));
  }

  /**
   * An explicitly declined consent value is neither accepted nor overwritten.
   */
  public function testExplicitFalseConsentRemainsFalse(): void {
    $child = $this->newReportWithoutConsent(FALSE);
    $child->method('save')->willReturnCallback(static function () use ($child): int {
      markaspot_nuxt_node_presave($child);
      return 1;
    });
    $this->context->saveChild($child);
    $this->assertFalse($child->get('field_gdpr')->value);
    $this->expectException(UnprocessableEntityHttpException::class);
    markaspot_nuxt_node_presave($child);
  }

  /**
   * Consent remains required when the optional dashboard module is absent.
   */
  public function testAbsentSplitContextStillRequiresConsent(): void {
    \Drupal::getContainer()->set('markaspot_dashboard.split_context', NULL);
    $this->assertFalse(\Drupal::hasService('markaspot_dashboard.split_context'));
    $this->expectException(UnprocessableEntityHttpException::class);
    markaspot_nuxt_node_presave($this->newReportWithoutConsent());
  }

  /**
   * Split consent exemptions never bypass the anonymous image-privacy gate.
   */
  public function testActiveSplitContextStillRejectsFlaggedAnonymousImage(): void {
    $child = $this->createMock(NodeInterface::class);
    $child->method('bundle')->willReturn('service_request');
    $child->method('isNew')->willReturn(TRUE);
    $child->method('hasField')->with('field_request_media')->willReturn(TRUE);

    $flag = $this->createMock(FieldItemListInterface::class);
    $flag->method('isEmpty')->willReturn(FALSE);
    $flag->method('__get')->with('value')->willReturn(TRUE);
    $media = $this->createMock(MediaInterface::class);
    $media->method('hasField')->with('field_ai_privacy_flag')->willReturn(TRUE);
    $media->method('get')->with('field_ai_privacy_flag')->willReturn($flag);
    $mediaField = $this->createMock(EntityReferenceFieldItemListInterface::class);
    $mediaField->method('isEmpty')->willReturn(FALSE);
    $mediaField->method('referencedEntities')->willReturn([$media]);
    $child->method('get')->with('field_request_media')->willReturn($mediaField);
    $child->expects($this->never())->method('set');

    $jurisdiction = $this->createMock(GroupInterface::class);
    $checker = $this->createMock(FeatureFlagChecker::class);
    $checker->method('resolveJurisdictionForNode')->with($child)->willReturn($jurisdiction);
    $resolver = $this->createMock(FeatureScopeResolver::class);
    $resolver->method('isEnabledEffective')->with('privacyBlockOnFlag', $jurisdiction, FALSE)->willReturn(TRUE);
    $account = $this->createMock(AccountInterface::class);
    $account->method('isAnonymous')->willReturn(TRUE);
    $request = new Request();
    $request->attributes->set('_route', 'jsonapi.node--service_request.collection.post');
    $container = \Drupal::getContainer();
    $container->get('request_stack')->push($request);
    $container->set('current_user', $account);
    $container->set('markaspot_nuxt.feature_flag_checker', $checker);
    $container->set('markaspot_nuxt.feature_scope_resolver', $resolver);

    $child->expects($this->once())->method('save')->willReturnCallback(function () use ($child): int {
      $this->assertTrue($this->context->isSavingChild($child));
      markaspot_nuxt_node_presave($child);
      return 1;
    });
    try {
      $this->context->saveChild($child);
      $this->fail('Flagged anonymous images must remain blocked during a split save.');
    }
    catch (BadRequestHttpException $e) {
      $this->assertSame(400, $e->getStatusCode());
      $this->assertStringContainsString('flagged for privacy review', $e->getMessage());
      $this->assertFalse($this->context->isSavingChild($child));
    }
  }

  /**
   * A second new node cannot borrow the split child's context.
   */
  public function testOtherNodeCannotBorrowContext(): void {
    $child = $this->newReportWithoutConsent();
    $other = $this->newReportWithoutConsent();
    $child->method('save')->willReturnCallback(static function () use ($other): int {
      markaspot_nuxt_node_presave($other);
      return 1;
    });
    try {
      $this->context->saveChild($child);
      $this->fail('The unrelated submission must require consent.');
    }
    catch (UnprocessableEntityHttpException) {
      $this->assertFalse($this->context->isSavingChild($child));
      $this->assertFalse($this->context->isSavingChild($other));
    }
  }

  /**
   * Failed saves do not leave a consent exemption behind.
   */
  public function testFailedSaveClearsContext(): void {
    $child = $this->newReportWithoutConsent();
    $child->method('save')->willThrowException(new \RuntimeException('Storage failed'));
    try {
      $this->context->saveChild($child);
      $this->fail('The storage exception must propagate.');
    }
    catch (\RuntimeException $e) {
      $this->assertSame('Storage failed', $e->getMessage());
      $this->assertFalse($this->context->isSavingChild($child));
    }
    $this->expectException(UnprocessableEntityHttpException::class);
    markaspot_nuxt_node_presave($child);
  }

  /**
   * Builds a new report without consent and forbids fabricated field values.
   */
  private function newReportWithoutConsent(bool $empty = TRUE): NodeInterface {
    $node = $this->createMock(NodeInterface::class);
    $node->method('bundle')->willReturn('service_request');
    $node->method('isNew')->willReturn(TRUE);
    $node->method('hasField')->with('field_gdpr')->willReturn(TRUE);
    $field = $this->createMock(FieldItemListInterface::class);
    $field->method('isEmpty')->willReturn($empty);
    $field->method('__get')->with('value')->willReturn(FALSE);
    $node->method('get')->with('field_gdpr')->willReturn($field);
    $node->expects($this->never())->method('set');
    return $node;
  }

}
