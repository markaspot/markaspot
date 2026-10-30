<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_mail\Unit\Builder;

use Drupal\Component\Serialization\Yaml;
use Drupal\Core\Field\EntityReferenceFieldItemListInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\file\FileInterface;
use Drupal\markaspot_mail\Mail\Builder\EcaActionEmailBuilder;
use Drupal\markaspot_mail\Mail\MailContext;
use Drupal\markaspot_mail\Mail\MailMessage;
use Drupal\markaspot_mail\Service\AttachmentResolver;
use Drupal\node\NodeInterface;
use Drupal\taxonomy\TermInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\NullLogger;

/**
 * Exercises provider recipient isolation through the real attachment resolver.
 */
#[CoversClass(EcaActionEmailBuilder::class)]
#[CoversClass(AttachmentResolver::class)]
#[Group('markaspot_mail')]
final class EcaProviderAttachmentTest extends UnitTestCase {

  /**
   * Only an exclusively assigned-provider audience gets the dedicated file.
   */
  #[DataProvider('recipientCases')]
  public function testRecipientIsolation(mixed $to, array $headers, bool $allowed, bool $citizen = TRUE): void {
    $message = $this->buildMail(to: $to, headers: $headers);
    $expected = $citizen ? ['citizen.jpg'] : [];
    if ($allowed) {
      $expected[] = 'provider.pdf';
    }
    $this->assertSame($expected, array_column($message->attachments, 'filename'));
  }

  /**
   * Recipient shapes and copy headers seen at the mail-alter boundary.
   */
  public static function recipientCases(): iterable {
    yield 'bare provider' => ['provider@example.test', [], TRUE];
    yield 'case and outer whitespace' => ['  PROVIDER@EXAMPLE.TEST  ', [], TRUE];
    yield 'display name' => ['Example Works <provider@example.test>', [], TRUE];
    yield 'quoted display name' => ['"Example Works" <provider@example.test>', [], TRUE];
    yield 'angle address' => ['<provider@example.test>', [], TRUE];
    yield 'second stored mailbox' => ['dispatch@example.test', [], TRUE];
    yield 'all stored mailboxes' => ['provider@example.test, dispatch@example.test', [], TRUE];
    yield 'array of mailboxes' => [['provider@example.test', 'dispatch@example.test'], [], TRUE];
    yield 'array containing list' => [['provider@example.test, dispatch@example.test'], [], TRUE];
    yield 'empty copy headers' => ['provider@example.test', ['Cc' => '', 'Bcc' => ' '], TRUE];
    yield 'reply-to is not a recipient' => ['provider@example.test', ['Reply-To' => 'staff@example.test'], TRUE];
    yield 'organisation' => ['org@example.test', [], FALSE];
    yield 'staff' => ['staff@example.test', [], FALSE];
    yield 'unassigned provider' => ['other-provider@example.test', [], FALSE];
    yield 'reporter' => ['reporter@example.test', [], FALSE, FALSE];
    yield 'provider plus reporter' => ['provider@example.test, reporter@example.test', [], FALSE, FALSE];
    yield 'provider plus staff' => ['provider@example.test, staff@example.test', [], FALSE];
    yield 'staff plus provider' => ['staff@example.test, provider@example.test', [], FALSE];
    yield 'array with outsider' => [['provider@example.test', 'staff@example.test'], [], FALSE];
    yield 'alias is not the mailbox' => ['provider+tag@example.test', [], FALSE];
    yield 'spoofed display address' => ['"provider@example.test" <staff@example.test>', [], FALSE];
    yield 'spoofed angle display address' => ['"Example <provider@example.test>" <staff@example.test>', [], FALSE];
    yield 'unquoted multiple angle addresses' => [
      'staff@example.test <staff@example.test> <provider@example.test>', [], FALSE,
    ];
    yield 'trailing outsider' => ['<provider@example.test> staff@example.test', [], FALSE];
    yield 'semicolon list' => ['provider@example.test; staff@example.test', [], FALSE];
    yield 'trailing empty mailbox' => ['provider@example.test,', [], FALSE];
    yield 'empty mailbox' => ['', [], FALSE];
    yield 'empty array' => [[], [], FALSE];
    yield 'null mailbox' => [NULL, [], FALSE];
    yield 'malformed mailbox' => ['not-an-address', [], FALSE];
    yield 'injected header' => ["provider@example.test\r\nBcc: staff@example.test", [], FALSE];
    yield 'Cc' => ['provider@example.test', ['Cc' => 'staff@example.test'], FALSE];
    yield 'Bcc' => ['provider@example.test', ['Bcc' => 'staff@example.test'], FALSE];
    yield 'mixed case Cc' => ['provider@example.test', ['cC' => 'staff@example.test'], FALSE];
    yield 'mixed case Bcc' => ['provider@example.test', ['bCC' => 'staff@example.test'], FALSE];
    yield 'provider copy also fails closed' => ['provider@example.test', ['cc' => 'dispatch@example.test'], FALSE];
    yield 'array copy fails closed' => ['provider@example.test', ['BCC' => ['staff@example.test']], FALSE];
    yield 'null copy fails closed' => ['provider@example.test', ['Cc' => NULL], FALSE];
  }

  /**
   * Missing, broken and unrelated assignments cannot release the provider file.
   */
  #[DataProvider('assignmentCases')]
  public function testAssignmentMustResolve(string $assignment, array $emails): void {
    $message = $this->buildMail(assignment: $assignment, emails: $emails);
    $this->assertSame(['citizen.jpg'], array_column($message->attachments, 'filename'));
  }

  /**
   * Invalid assignment and mailbox configurations.
   */
  public static function assignmentCases(): iterable {
    yield 'missing field' => ['missing', ['provider@example.test']];
    yield 'empty field' => ['empty', ['provider@example.test']];
    yield 'deleted target' => ['deleted', ['provider@example.test']];
    yield 'wrong field type' => ['scalar', ['provider@example.test']];
    yield 'wrong entity type' => ['node', ['provider@example.test']];
    yield 'wrong vocabulary' => ['category', ['provider@example.test']];
    yield 'ambiguous assignment' => ['multiple', ['provider@example.test']];
    yield 'missing email field' => ['no_email', ['provider@example.test']];
    yield 'empty emails' => ['valid', []];
    yield 'invalid email' => ['valid', ['not-an-address']];
    yield 'different mailbox' => ['valid', ['other-provider@example.test']];
  }

  /**
   * The existing policy applies to the provider file and the combined budget.
   */
  #[DataProvider('policyCases')]
  public function testResolverPolicies(array $settings, string $mime, int $size, bool $permanent, array $expected): void {
    $message = $this->buildMail(settings: $settings, mime: $mime, size: $size, permanent: $permanent);
    $this->assertSame($expected, array_column($message->attachments, 'filename'));
    if (in_array('provider.pdf', $expected, TRUE)) {
      $attachment = $message->attachments[array_key_last($message->attachments)];
      $this->assertSame('private://provider.pdf', $attachment->filepath);
      $this->assertSame('application/pdf', $attachment->filemime);
      $this->assertSame($size, $attachment->filesize);
    }
  }

  /**
   * Uses the shipped whitelist without widening it.
   */
  public static function policyCases(): iterable {
    yield 'private PDF opt-in' => [[], 'application/pdf', 1024, TRUE, ['citizen.jpg', 'provider.pdf']];
    yield 'master switch off' => [['enabled' => FALSE], 'application/pdf', 1024, TRUE, []];
    yield 'oversized' => [[], 'application/pdf', 5242881, TRUE, ['citizen.jpg']];
    yield 'at per-file cap' => [[], 'application/pdf', 5242880, TRUE, ['citizen.jpg', 'provider.pdf']];
    yield 'disallowed executable' => [[], 'application/x-executable', 1024, TRUE, ['citizen.jpg']];
    yield 'XLS stays disallowed' => [[], 'application/vnd.ms-excel', 1024, TRUE, ['citizen.jpg']];
    yield 'XLSX stays disallowed' => [
      [], 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
      1024, TRUE, ['citizen.jpg'],
    ];
    yield 'combined budget exceeded' => [['max_total_bytes' => 2047], 'application/pdf', 1024, TRUE, ['citizen.jpg']];
    yield 'combined budget exactly met' => [
      ['max_total_bytes' => 2048], 'application/pdf', 1024, TRUE,
      ['citizen.jpg', 'provider.pdf'],
    ];
    yield 'temporary file' => [[], 'application/pdf', 1024, FALSE, ['citizen.jpg']];
  }

  /**
   * Both supported ECA context entity keys use the same provider gate.
   */
  public function testEntityContextFallback(): void {
    $message = $this->buildMail(contextKey: 'entity');
    $this->assertSame(['citizen.jpg', 'provider.pdf'], array_column($message->attachments, 'filename'));
  }

  /**
   * Unrelated bundles never enter attachment resolution.
   */
  public function testOtherNodeBundleDoesNotAttach(): void {
    $this->assertSame([], $this->buildMail(bundle: 'page')->attachments);
  }

  /**
   * Builds a real builder and resolver around fictional entity fixtures.
   */
  private function buildMail(
    mixed $to = 'provider@example.test',
    array $headers = [],
    string $assignment = 'valid',
    array $emails = ['provider@example.test', 'dispatch@example.test'],
    array $settings = [],
    string $mime = 'application/pdf',
    int $size = 1024,
    bool $permanent = TRUE,
    string $contextKey = 'node',
    string $bundle = 'service_request',
  ): MailMessage {
    $emailField = $this->createMock(FieldItemListInterface::class);
    $emailField->method('getValue')->willReturn(array_map(static fn(string $email): array => ['value' => $email], $emails));
    $provider = $this->createMock($assignment === 'node' ? NodeInterface::class : TermInterface::class);
    $provider->method('bundle')->willReturn($assignment === 'category' ? 'service_category' : 'service_provider');
    $provider->method('hasField')->willReturn($assignment !== 'no_email');
    $provider->method('get')->with('field_sp_email')->willReturn($emailField);

    $providerField = $this->createMock($assignment === 'scalar' ? FieldItemListInterface::class : EntityReferenceFieldItemListInterface::class);
    $providerField->method('isEmpty')->willReturn($assignment === 'empty');
    if ($providerField instanceof EntityReferenceFieldItemListInterface) {
      $providerField->method('referencedEntities')->willReturn(match ($assignment) {
        'empty', 'deleted' => [],
        'multiple' => [$provider, $provider],
        default => [$provider],
      });
    }
    $reporterField = $this->createMock(FieldItemListInterface::class);
    $reporterField->method('isEmpty')->willReturn(FALSE);
    $reporterField->method('getString')->willReturn('reporter@example.test');
    $fields = [
      'field_e_mail' => $reporterField,
      'field_request_image' => $this->fileField(1, 'citizen.jpg', 'image/jpeg', 1024, TRUE),
      'field_sp_attachment' => $this->fileField(2, 'provider.pdf', $mime, $size, $permanent),
    ];
    if ($assignment !== 'missing') {
      $fields['field_service_provider'] = $providerField;
    }
    $node = $this->createMock(NodeInterface::class);
    $node->method('getEntityTypeId')->willReturn('node');
    $node->method('bundle')->willReturn($bundle);
    $node->method('hasField')->willReturnCallback(static fn(string $name): bool => isset($fields[$name]));
    $node->method('get')->willReturnCallback(static fn(string $name): FieldItemListInterface => $fields[$name]);

    $config = Yaml::decode(file_get_contents(dirname(__DIR__, 4) . '/config/install/markaspot_mail.settings.yml'));
    $config['attachments'] = array_replace($config['attachments'], $settings);
    $resolver = new AttachmentResolver($this->getConfigFactoryStub(['markaspot_mail.settings' => $config]), new NullLogger());
    $builder = new EcaActionEmailBuilder(new NullLogger(), $resolver);
    $context = new MailContext(
      module: 'system',
      key: 'action_send_email',
      langcode: 'en',
      params: ['context' => ['subject' => 'Commissioning', $contextKey => $node]],
      to: $to,
      subject: 'Commissioning',
      body: ['Please handle this request.'],
      headers: $headers,
    );
    $message = $builder->build($context);
    $this->assertNotNull($message);
    return $message;
  }

  /**
   * Makes a permanent private file reference without filesystem or mail I/O.
   */
  private function fileField(int $id, string $name, string $mime, int $size, bool $permanent): EntityReferenceFieldItemListInterface {
    $file = $this->createMock(FileInterface::class);
    $file->method('id')->willReturn($id);
    $file->method('getFilename')->willReturn($name);
    $file->method('getFileUri')->willReturn('private://' . $name);
    $file->method('getMimeType')->willReturn($mime);
    $file->method('getSize')->willReturn($size);
    $file->method('isPermanent')->willReturn($permanent);
    $field = $this->createMock(EntityReferenceFieldItemListInterface::class);
    $field->method('isEmpty')->willReturn(FALSE);
    $field->method('referencedEntities')->willReturn([$file]);
    return $field;
  }

}
