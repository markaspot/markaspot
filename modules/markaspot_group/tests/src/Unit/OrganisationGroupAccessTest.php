<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_group\Unit;

use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\markaspot_group\Service\OrganisationManagementAccess;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\DependencyInjection\ContainerBuilder;

require_once dirname(__DIR__, 3) . '/src/Service/OrganisationManagementAccess.php';
require_once dirname(__DIR__, 3) . '/markaspot_group.module';

/**
 * Tests organisation entity access and delete messaging.
 */
#[Group('markaspot_group')]
class OrganisationGroupAccessTest extends UnitTestCase {

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    \Drupal::unsetContainer();
    parent::tearDown();
  }

  /**
   * Tests a jurisdiction administrator receives the exact update grant.
   */
  public function testJurisdictionAdministratorMayUpdateOrganisation(): void {
    $access_checker = $this->createMock(OrganisationManagementAccess::class);
    $access_checker->method('hasBypass')->willReturn(FALSE);
    $access_checker->expects($this->once())
      ->method('canManageJurisdiction')
      ->with($this->isInstanceOf(AccountInterface::class), 6)
      ->willReturn(TRUE);
    $this->setContainer($access_checker);

    $result = markaspot_group_group_access(
      $this->organisation(6),
      'update',
      $this->createMock(AccountInterface::class),
    );

    $this->assertTrue($result->isAllowed());
    $this->assertSame(0, $result->getCacheMaxAge());
  }

  /**
   * Tests the hook stays neutral outside the administrator's jurisdiction.
   */
  public function testForeignOrganisationAccessRemainsNeutral(): void {
    $access_checker = $this->createMock(OrganisationManagementAccess::class);
    $access_checker->method('hasBypass')->willReturn(FALSE);
    $access_checker->method('canManageJurisdiction')->willReturn(FALSE);
    $this->setContainer($access_checker);

    $result = markaspot_group_group_access(
      $this->organisation(6),
      'update',
      $this->createMock(AccountInterface::class),
    );

    $this->assertTrue($result->isNeutral());
  }

  /**
   * Tests a jurisdiction administrator may create organisation groups.
   */
  public function testJurisdictionAdministratorMayCreateOrganisation(): void {
    $access_checker = $this->createMock(OrganisationManagementAccess::class);
    $access_checker->method('hasBypass')->willReturn(FALSE);
    $access_checker->method('managesAnyJurisdiction')->willReturn(TRUE);
    $this->setContainer($access_checker);
    $account = $this->createMock(AccountInterface::class);
    $account->method('id')->willReturn(9);
    $account->method('getRoles')->willReturn(['authenticated']);

    $result = markaspot_group_entity_create_access(
      $account,
      ['entity_type_id' => 'group'],
      'org',
    );

    $this->assertTrue($result->isAllowed());
    $this->assertSame(0, $result->getCacheMaxAge());
  }

  /**
   * Tests accounts without a management membership receive no extra grant.
   */
  public function testNonManagerOrganisationCreateAccessRemainsNeutral(): void {
    $access_checker = $this->createMock(OrganisationManagementAccess::class);
    $access_checker->method('hasBypass')->willReturn(FALSE);
    $access_checker->method('managesAnyJurisdiction')->willReturn(FALSE);
    $this->setContainer($access_checker);
    $account = $this->createMock(AccountInterface::class);
    $account->method('id')->willReturn(9);
    $account->method('getRoles')->willReturn(['authenticated']);

    $result = markaspot_group_entity_create_access(
      $account,
      ['entity_type_id' => 'group'],
      'org',
    );

    $this->assertTrue($result->isNeutral());
  }

  /**
   * Tests managers may view unpublished organisations in their jurisdiction.
   */
  public function testManagerMayViewUnpublishedOrganisation(): void {
    $access_checker = $this->createMock(OrganisationManagementAccess::class);
    $access_checker->method('hasBypass')->willReturn(FALSE);
    $access_checker->method('canManageJurisdiction')->with(
      $this->isInstanceOf(AccountInterface::class),
      6,
    )->willReturn(TRUE);
    $this->setContainer($access_checker);

    $result = markaspot_group_group_access(
      $this->organisation(6, FALSE),
      'view',
      $this->createMock(AccountInterface::class),
    );

    $this->assertTrue($result->isAllowed());
  }

  /**
   * Tests organisation mailboxes are visible only to jurisdiction managers.
   */
  public function testOrganisationMailboxViewAccessIsJurisdictionScoped(): void {
    $organisation = $this->organisation(6);
    $items = $this->createMock(FieldItemListInterface::class);
    $items->method('getEntity')->willReturn($organisation);

    $definition = $this->createMock(FieldDefinitionInterface::class);
    $definition->method('getTargetEntityTypeId')->willReturn('group');
    $definition->method('getTargetBundle')->willReturn('org');
    $definition->method('getName')->willReturn('field_head_organisation_e_mail');

    $access_checker = $this->createMock(OrganisationManagementAccess::class);
    $access_checker->method('hasBypass')->willReturn(FALSE);
    $access_checker->method('canManageJurisdiction')
      ->willReturnOnConsecutiveCalls(TRUE, FALSE);
    $this->setContainer($access_checker);
    $account = $this->createMock(AccountInterface::class);

    $allowed = markaspot_group_entity_field_access(
      'view',
      $definition,
      $account,
      $items,
    );
    $forbidden = markaspot_group_entity_field_access(
      'view',
      $definition,
      $account,
      $items,
    );

    $this->assertTrue($allowed->isAllowed());
    $this->assertTrue($forbidden->isForbidden());
    $this->assertSame(0, $allowed->getCacheMaxAge());
    $this->assertSame(0, $forbidden->getCacheMaxAge());
  }

  /**
   * Tests referenced organisations direct operators to deactivation.
   */
  public function testDeleteRefusalMessageDirectsToStatusDeactivation(): void {
    $this->setContainer($this->createMock(OrganisationManagementAccess::class));

    $message = _markaspot_group_delete_refusal_message(
      $this->organisation(6),
      ['2 service requests via field_organisation'],
    );

    $this->assertStringContainsString('Cannot delete', $message);
    $this->assertStringContainsString('Deactivate the organisation by setting status to false instead.', $message);
  }

  /**
   * Tests an authorized delete reaches the predelete conflict guard.
   */
  public function testReferencedOrganisationDeleteAccessIsAllowedForManager(): void {
    $access_checker = $this->createMock(OrganisationManagementAccess::class);
    $access_checker->method('hasBypass')->willReturn(FALSE);
    $access_checker->method('canManageJurisdiction')->willReturn(TRUE);
    $this->setContainer($access_checker);

    $result = markaspot_group_group_access(
      $this->organisation(6),
      'delete',
      $this->createMock(AccountInterface::class),
    );

    $this->assertTrue($result->isAllowed());
  }

  /**
   * Sets the reduced Drupal container used by the access hook.
   */
  private function setContainer(
    OrganisationManagementAccess $access_checker,
    ?Connection $database = NULL,
  ): void {
    $translation = $this->createMock(TranslationInterface::class);
    $translation->method('translateString')
      ->willReturnCallback(
        static fn(TranslatableMarkup $markup): string => $markup->getUntranslatedString(),
      );

    $container = new ContainerBuilder();
    $container->set('markaspot_group.organisation_management_access', $access_checker);
    $container->set('string_translation', $translation);
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->willReturn('jur');
    $config_factory = $this->createMock(ConfigFactoryInterface::class);
    $config_factory->method('get')->willReturn($config);
    $container->set('config.factory', $config_factory);
    if ($database !== NULL) {
      $container->set('database', $database);
    }
    \Drupal::setContainer($container);
  }

  /**
   * Creates an organisation group mock with a jurisdiction reference.
   */
  private function organisation(int $jurisdiction_id, bool $published = TRUE): GroupInterface {
    $field = $this->createMock(FieldItemListInterface::class);
    $field->method('isEmpty')->willReturn(FALSE);
    $field->method('__get')
      ->with('target_id')
      ->willReturn($jurisdiction_id);

    $group = $this->createMock(GroupInterface::class);
    $group->method('bundle')->willReturn('org');
    $group->method('id')->willReturn(21);
    $group->method('label')->willReturn('Roofing Ltd');
    $group->method('isPublished')->willReturn($published);
    $group->method('getCacheContexts')->willReturn([]);
    $group->method('getCacheTags')->willReturn([]);
    $group->method('getCacheMaxAge')->willReturn(-1);
    $group->method('hasField')
      ->with('field_jurisdiction')
      ->willReturn(TRUE);
    $group->method('get')
      ->with('field_jurisdiction')
      ->willReturn($field);
    return $group;
  }

}
