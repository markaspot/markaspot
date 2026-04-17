<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_mail\Unit;

use Drupal\markaspot_mail\Enum\MailType;
use Drupal\markaspot_mail\Mail\MailBuilderRegistry;
use Drupal\Tests\markaspot_mail\Unit\Stub\SupportingStubBuilder;
use Drupal\Tests\UnitTestCase;

/**
 * @coversDefaultClass \Drupal\markaspot_mail\Mail\MailBuilderRegistry
 * @group markaspot_mail
 */
final class MailBuilderRegistryTest extends UnitTestCase {

  /**
   * @covers ::findForMessage
   */
  public function testFindForMessageReturnsFirstSupportingBuilder(): void {
    $eca = new SupportingStubBuilder(MailType::ECA_ESCALATION, 'markaspot_escalation', 'escalation_notice');
    $otp = new SupportingStubBuilder(MailType::PASSWORDLESS_OTP, 'markaspot_passwordless', 'login_code');

    $registry = new MailBuilderRegistry([$eca, $otp]);

    $this->assertSame($otp, $registry->findForMessage('markaspot_passwordless', 'login_code'));
    $this->assertSame($eca, $registry->findForMessage('markaspot_escalation', 'escalation_notice'));
  }

  /**
   * @covers ::findForMessage
   */
  public function testFindForMessageReturnsNullWhenNobodyClaims(): void {
    $registry = new MailBuilderRegistry([
      new SupportingStubBuilder(MailType::PASSWORDLESS_OTP, 'markaspot_passwordless', 'login_code'),
    ]);

    $this->assertNull($registry->findForMessage('user', 'password_reset'));
    $this->assertNull($registry->findForMessage('markaspot_fastmap', 'unknown_key'));
  }

  /**
   * @covers ::findForMessage
   */
  public function testFindForMessageReturnsNullOnEmptyRegistry(): void {
    $registry = new MailBuilderRegistry([]);
    $this->assertNull($registry->findForMessage('anything', 'anything'));
  }

  /**
   * @covers ::findForMessage
   */
  public function testFirstMatchWins(): void {
    $a = new SupportingStubBuilder(MailType::ECA_ESCALATION, 'markaspot_escalation', 'escalation_notice');
    $b = new SupportingStubBuilder(MailType::ECA_ESCALATION, 'markaspot_escalation', 'escalation_notice');

    $registry = new MailBuilderRegistry([$a, $b]);
    $this->assertSame($a, $registry->findForMessage('markaspot_escalation', 'escalation_notice'));
  }

  /**
   * @covers ::findByType
   */
  public function testFindByTypeReturnsBuilderWithMatchingType(): void {
    $eca = new SupportingStubBuilder(MailType::ECA_ESCALATION, 'markaspot_escalation', 'escalation_notice');
    $otp = new SupportingStubBuilder(MailType::PASSWORDLESS_OTP, 'markaspot_passwordless', 'login_code');

    $registry = new MailBuilderRegistry([$eca, $otp]);
    $this->assertSame($otp, $registry->findByType(MailType::PASSWORDLESS_OTP));
    $this->assertSame($eca, $registry->findByType(MailType::ECA_ESCALATION));
  }

  /**
   * @covers ::findByType
   */
  public function testFindByTypeReturnsNullWhenTypeUnregistered(): void {
    $registry = new MailBuilderRegistry([
      new SupportingStubBuilder(MailType::ECA_ESCALATION, 'markaspot_escalation', 'escalation_notice'),
    ]);
    $this->assertNull($registry->findByType(MailType::FASTMAP_DEMO_EXPIRY));
  }

  /**
   * @covers ::all
   */
  public function testAllReturnsBuildersInRegistrationOrder(): void {
    $first = new SupportingStubBuilder(MailType::ECA_ESCALATION, 'markaspot_escalation', 'escalation_notice');
    $second = new SupportingStubBuilder(MailType::PASSWORDLESS_OTP, 'markaspot_passwordless', 'login_code');

    $registry = new MailBuilderRegistry([$first, $second]);
    $this->assertSame([$first, $second], $registry->all());
  }

  /**
   * @covers ::__construct
   */
  public function testConstructorFiltersNonBuilderEntries(): void {
    $builder = new SupportingStubBuilder(MailType::ECA_ESCALATION, 'markaspot_escalation', 'escalation_notice');

    // A misconfigured tagged iterator could deliver a non-builder value.
    // The registry must not choke on it — it drops silently.
    $registry = new MailBuilderRegistry([$builder, 'not-a-builder', new \stdClass()]);
    $this->assertSame([$builder], $registry->all());
  }

}
