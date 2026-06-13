<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_mail_inbound\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Utility\Token;
use Drupal\markaspot_mail_inbound\Service\InboundMailPromoter;
use Drupal\markaspot_mail_inbound\Service\InternalRemarkWriter;
use Drupal\taxonomy\TermInterface;
use Drupal\Tests\UnitTestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Unit tests for the promoter's pure request-data helpers (#467).
 *
 * Covers the three load-bearing pieces of the promotion contract that do not
 * need a saved entity:
 * - category tid -> service_code derivation (coded path: the processor maps
 *   the code back to the jurisdiction-scoped term for field_category; codeless
 *   path: resolveServiceCode returns NULL and the promoter sets field_category
 *   directly after the processor call),
 * - the description = subject + body assembly (subject must NOT become title),
 * - the trivial address extraction (simple regex, NO NER).
 *
 * @group markaspot_mail_inbound
 * @coversDefaultClass \Drupal\markaspot_mail_inbound\Service\InboundMailPromoter
 */
class InboundMailPromoterRequestDataTest extends UnitTestCase {

  /**
   * Builds a promoter with a term storage stubbed to return the given term.
   */
  protected function promoter(?TermInterface $term): TestableInboundMailPromoter {
    $termStorage = $this->createMock(EntityStorageInterface::class);
    $termStorage->method('load')->willReturn($term);

    $etm = $this->createMock(EntityTypeManagerInterface::class);
    $etm->method('getStorage')->with('taxonomy_term')->willReturn($termStorage);

    return new TestableInboundMailPromoter(
      $etm,
      $this->createMock(ModuleHandlerInterface::class),
      $this->createMock(EventDispatcherInterface::class),
      $this->createMock(LoggerChannelInterface::class),
      $this->createMock(EntityFieldManagerInterface::class),
      $this->createMock(ConfigFactoryInterface::class),
      $this->createMock(FileSystemInterface::class),
      $this->createMock(Token::class),
      $this->createMock(InternalRemarkWriter::class),
      NULL,
      NULL,
      $this->createMock(LanguageManagerInterface::class),
    );
  }

  /**
   * Builds a term mock whose field_service_code yields the given value.
   *
   * The field item list is a small stand-in exposing the magic `value`
   * property and isEmpty(), which a plain mock cannot do for a magic getter.
   */
  protected function termWithCode(?string $code): TermInterface {
    $term = $this->createMock(TermInterface::class);
    $hasCode = $code !== NULL;
    $term->method('hasField')->with('field_service_code')->willReturn($hasCode);

    $term->method('get')->with('field_service_code')->willReturn(new FakeFieldItemList($code));

    return $term;
  }

  /**
   * A term with a service code resolves to that code.
   *
   * @covers ::resolveServiceCode
   */
  public function testResolveServiceCodeReturnsTermCode(): void {
    $promoter = $this->promoter($this->termWithCode('1.3'));
    $this->assertSame('1.3', $promoter->exposeResolveServiceCode(42));
  }

  /**
   * A term without a service code resolves to NULL (codeless path is used).
   *
   * The promoter then sets field_category directly on the node after the
   * processor call rather than via the service_code mapping.
   *
   * @covers ::resolveServiceCode
   */
  public function testResolveServiceCodeNullWhenNoCode(): void {
    $promoter = $this->promoter($this->termWithCode(NULL));
    $this->assertNull($promoter->exposeResolveServiceCode(42));
  }

  /**
   * A missing term resolves to NULL.
   *
   * @covers ::resolveServiceCode
   */
  public function testResolveServiceCodeNullWhenTermMissing(): void {
    $promoter = $this->promoter(NULL);
    $this->assertNull($promoter->exposeResolveServiceCode(42));
    // A non-positive tid never hits storage.
    $this->assertNull($promoter->exposeResolveServiceCode(0));
  }

  /**
   * Description is subject + body; subject alone or body alone degrade.
   *
   * @covers ::buildDescription
   */
  public function testBuildDescription(): void {
    $promoter = $this->promoter(NULL);

    $this->assertSame(
      "Broken light\n\nThe one near the church.",
      $promoter->exposeBuildDescription('Broken light', 'The one near the church.')
    );
    $this->assertSame('Just a subject', $promoter->exposeBuildDescription('Just a subject', ''));
    $this->assertSame('Just a body', $promoter->exposeBuildDescription('', 'Just a body'));
  }

  /**
   * Trivial address extraction matches street + postcode, else NULL.
   *
   * @covers ::extractTrivialAddress
   * @dataProvider addressProvider
   */
  public function testExtractTrivialAddress(string $body, ?string $expected): void {
    $promoter = $this->promoter(NULL);
    $this->assertSame($expected, $promoter->exposeExtractTrivialAddress($body));
  }

  /**
   * Data provider for trivial address extraction.
   *
   * @return array<string, array{string, string|null}>
   *   Body text and the expected extracted address (or NULL).
   */
  public static function addressProvider(): array {
    return [
      'street and postcode' => [
        'Der Laternenmast in der Hauptstrasse 12 ist kaputt, 50667 Koeln.',
        'Hauptstrasse 12, 50667 Koeln',
      ],
      'street only' => [
        'Die Laterne am Lindenweg 5 flackert.',
        'Lindenweg 5',
      ],
      // A bare postcode + locality is NOT an address on its own (review LOW 7):
      // without a confident street token the report stays ungeolocated.
      'postcode and locality only is rejected' => [
        'Irgendwo in 50667 Koeln ist etwas kaputt.',
        NULL,
      ],
      // A 5-digit run that is not a postcode (a ticket / order number) plus a
      // capitalized word must not be mistaken for an address.
      'five-digit non-postcode does not geocode' => [
        'Ticket 12345 Spam, bitte bearbeiten.',
        NULL,
      ],
      'no address' => [
        'A streetlight is broken near the church.',
        NULL,
      ],
      'empty' => ['', NULL],
    ];
  }

}

/**
 * Test-only subclass exposing the promoter's protected helpers.
 */
class TestableInboundMailPromoter extends InboundMailPromoter {

  /**
   * Exposes resolveServiceCode().
   */
  public function exposeResolveServiceCode(int $categoryTid): ?string {
    return $this->resolveServiceCode($categoryTid);
  }

  /**
   * Exposes buildDescription() given subject and body directly.
   */
  public function exposeBuildDescription(string $subject, string $body): string {
    return $this->buildDescription($subject, $body);
  }

  /**
   * Exposes extractTrivialAddress().
   */
  public function exposeExtractTrivialAddress(string $body): ?string {
    return $this->extractTrivialAddress($body);
  }

}

/**
 * Minimal field-item-list stand-in exposing the magic value + isEmpty().
 *
 * A plain PHPUnit mock cannot expose the magic `->value` getter, so this tiny
 * fake stands in for the term's field_service_code item list.
 */
class FakeFieldItemList {

  /**
   * The field value.
   */
  public ?string $value;

  /**
   * Constructs the fake list.
   *
   * @param string|null $value
   *   The stored value.
   */
  public function __construct(?string $value) {
    $this->value = $value;
  }

  /**
   * Whether the field is empty.
   *
   * @return bool
   *   TRUE when the value is null or an empty string.
   */
  public function isEmpty(): bool {
    return $this->value === NULL || $this->value === '';
  }

}
