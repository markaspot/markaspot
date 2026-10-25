<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_mail_inbound\Unit;

use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\StorageInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Site\Settings;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Drupal\markaspot_mail_inbound\Dto\InboundMessage;
use Drupal\markaspot_mail_inbound\Service\MailboxResolver;
use Drupal\Tests\UnitTestCase;

/**
 * Tests mailbox resolution and normalization.
 *
 * @group markaspot_mail_inbound
 * @coversDefaultClass \Drupal\markaspot_mail_inbound\Service\MailboxResolver
 */
class MailboxResolverTest extends UnitTestCase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    new Settings([]);
  }

  /**
   * Builds a resolver backed by the given settings array.
   */
  protected function createResolver(array $settings): MailboxResolver {
    $configFactory = $this->getConfigFactoryStub([
      MailboxResolver::CONFIG_NAME => $settings,
    ]);
    return new MailboxResolver($configFactory);
  }

  /**
   * @covers ::getMailboxes
   * @covers ::getMailbox
   * @covers ::resolveForMessage
   */
  public function testRuntimeMailboxesExcludeClonedProductionConfiguration(): void {
    $saved = Settings::getAll();
    try {
      $config = new Config(self::class,
        $this->createMock(StorageInterface::class),
        $this->createMock(EventDispatcherInterface::class),
        $this->createMock(TypedConfigManagerInterface::class),
      );
      $production = [
        ['id' => 'production_one', 'enabled' => TRUE, 'imap' => ['host' => 'real.example', 'password' => 'old-secret']],
        ['id' => 'production_two', 'enabled' => TRUE,
          'imap' => ['host' => 'other.example', 'password' => 'other-secret'],
        ],
      ];
      $config->initWithData(['mailboxes' => $production]);
      // Real Drupal merging preserves extra numeric keys for an array override.
      $config->setSettingsOverride(['mailboxes' => [['id' => 'cp2_test']]]);
      $this->assertCount(2, $config->get('mailboxes'));
      // A scalar mask is also fail-closed for older resolver versions.
      $config->setSettingsOverride(['mailboxes' => FALSE]);
      $this->assertFalse($config->get('mailboxes'));
      $factory = $this->createMock(ConfigFactoryInterface::class);
      $factory->method('get')->willReturn($config);
      $resolver = new MailboxResolver($factory);
      $test = ['id' => 'cp2_test', 'enabled' => TRUE,
        'imap' => ['host' => 'mail-inbound', 'password' => 'test-secret'],
        'recipient_addresses' => ['town@test.mark-a-spot.com'],
        'sender_allowlist' => ['tester@example.org'],
        'jurisdiction_gid' => 7,
      ];
      new Settings(array_replace($saved, ['markaspot_mail_inbound_mailboxes' => [$test]]));
      $mailboxes = $resolver->getMailboxes();
      $this->assertCount(1, $mailboxes);
      $this->assertSame('mail-inbound', $mailboxes[0]['imap']['host']);
      $this->assertSame(['tester@example.org'], $mailboxes[0]['sender_allowlist']);
      $this->assertNull($resolver->getMailbox('production_two'));
      $this->assertNull($resolver->resolveForMessage($this->createMessage(['real@example.org'])));
      $this->assertSame('cp2_test', $resolver->resolveForMessage($this->createMessage(['town@test.mark-a-spot.com']))['id']);
      foreach ([[], FALSE, 'malformed'] as $disabled) {
        new Settings(array_replace($saved, ['markaspot_mail_inbound_mailboxes' => $disabled]));
        $this->assertSame([], $resolver->getMailboxes());
      }
      $this->assertSame($production, $config->getRawData()['mailboxes']);
    }
    finally {
      new Settings($saved);
    }
  }

  /**
   * Builds a minimal InboundMessage addressed to the given recipients.
   */
  protected function createMessage(array $toAddresses): InboundMessage {
    return new InboundMessage(
      fromAddress: 'citizen@example.org',
      fromName: 'Citizen',
      toAddresses: $toAddresses,
      subject: 'Subject',
      textBody: 'Body',
      htmlBody: '',
      messageId: 'id@example.org',
      inReplyTo: '',
      references: [],
      date: NULL,
      attachments: [],
    );
  }

  /**
   * @covers ::getMailboxes
   * @covers ::getMailbox
   */
  public function testNormalizationAndEnabledFilter(): void {
    $resolver = $this->createResolver([
      'mailboxes' => [
        [
          'id' => 'city_main',
          'label' => 'City',
          'enabled' => TRUE,
          'recipient_addresses' => ['Report@City.Example'],
          'jurisdiction_gid' => '7',
          'default_category_tid' => '12',
        ],
        [
          'id' => 'disabled_box',
          'enabled' => FALSE,
          'recipient_addresses' => ['other@city.example'],
        ],
        [
          // No id: ignored entirely.
          'enabled' => TRUE,
        ],
      ],
    ]);

    $enabled = $resolver->getMailboxes();
    $this->assertCount(1, $enabled);
    $this->assertSame('city_main', $enabled[0]['id']);
    // Recipient addresses are lowercased, scalars are cast.
    $this->assertSame(['report@city.example'], $enabled[0]['recipient_addresses']);
    $this->assertSame(7, $enabled[0]['jurisdiction_gid']);
    $this->assertSame(12, $enabled[0]['default_category_tid']);
    // IMAP defaults applied.
    $this->assertSame(993, $enabled[0]['imap']['port']);
    $this->assertSame('INBOX', $enabled[0]['imap']['folder']);

    // Disabled mailbox is loadable by id when asked for.
    $this->assertNotNull($resolver->getMailbox('disabled_box'));
    $this->assertNull($resolver->getMailbox('disabled_box', TRUE));
    $this->assertNull($resolver->getMailbox('missing'));
  }

  /**
   * @covers ::resolveForMessage
   */
  public function testResolveForMessage(): void {
    $resolver = $this->createResolver([
      'mailboxes' => [
        [
          'id' => 'north',
          'enabled' => TRUE,
          'recipient_addresses' => ['north@city.example'],
        ],
        [
          'id' => 'south',
          'enabled' => TRUE,
          'recipient_addresses' => ['south@city.example', 'sued@city.example'],
        ],
      ],
    ]);

    $south = $resolver->resolveForMessage($this->createMessage(['sued@city.example']));
    $this->assertNotNull($south);
    $this->assertSame('south', $south['id']);

    // A Cc/secondary recipient matches too (all recipients are candidates).
    $north = $resolver->resolveForMessage($this->createMessage(['someone@else.example', 'north@city.example']));
    $this->assertNotNull($north);
    $this->assertSame('north', $north['id']);

    $this->assertNull($resolver->resolveForMessage($this->createMessage(['unknown@city.example'])));
  }

  /**
   * @covers ::getGlobalSettings
   */
  public function testGlobalSettingsDefaults(): void {
    $resolver = $this->createResolver(['mailboxes' => []]);
    $settings = $resolver->getGlobalSettings();

    $this->assertSame(5, $settings['max_attachments']);
    $this->assertSame(8, $settings['max_attachment_size_mb']);
    $this->assertSame(['image/jpeg', 'image/png', 'image/webp', 'image/heic'], $settings['allowed_mime_types']);
    $this->assertSame(10000, $settings['max_body_length']);
    $this->assertSame(10, $settings['flood_limit']);
    $this->assertSame(3600, $settings['flood_window']);
    $this->assertArrayNotHasKey('store_raw', $settings);
  }

  /**
   * @covers ::getMailboxes
   */
  public function testPasswordFallsBackToEnvironmentVariable(): void {
    $resolver = $this->createResolver([
      'mailboxes' => [
        [
          'id' => 'city_main',
          'enabled' => TRUE,
          'recipient_addresses' => ['report@city.example'],
          'imap' => [
            'host' => 'imap.example',
            'username' => 'user',
            // No password in config: must come from the environment.
          ],
        ],
      ],
    ]);

    putenv('MARKASPOT_MAIL_INBOUND_PASSWORD_CITY_MAIN=from-env');
    try {
      $mailboxes = $resolver->getMailboxes();
      $this->assertSame('from-env', $mailboxes[0]['imap']['password']);
    }
    finally {
      putenv('MARKASPOT_MAIL_INBOUND_PASSWORD_CITY_MAIN');
    }
  }

  /**
   * @covers ::getMailboxes
   */
  public function testConfigPasswordWinsOverEnvironment(): void {
    $resolver = $this->createResolver([
      'mailboxes' => [
        [
          'id' => 'city_main',
          'enabled' => TRUE,
          'recipient_addresses' => ['report@city.example'],
          'imap' => [
            'host' => 'imap.example',
            'username' => 'user',
            'password' => 'from-config',
          ],
        ],
      ],
    ]);

    putenv('MARKASPOT_MAIL_INBOUND_PASSWORD_CITY_MAIN=from-env');
    try {
      $mailboxes = $resolver->getMailboxes();
      $this->assertSame('from-config', $mailboxes[0]['imap']['password']);
    }
    finally {
      putenv('MARKASPOT_MAIL_INBOUND_PASSWORD_CITY_MAIN');
    }
  }

}
