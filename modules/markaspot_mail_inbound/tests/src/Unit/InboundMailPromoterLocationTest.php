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
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Utility\Token;
use Drupal\markaspot_mail_inbound\Entity\InboundMail;
use Drupal\markaspot_mail_inbound\Exception\LocationOutsideJurisdictionException;
use Drupal\markaspot_mail_inbound\Exception\PromotionConflictException;
use Drupal\markaspot_mail_inbound\Service\InboundMailPromoter;
use Drupal\markaspot_mail_inbound\Service\InternalRemarkWriter;
use Drupal\markaspot_mail_inbound\Service\PromoteOptions;
use Drupal\markaspot_mail_inbound\Service\PromotionResult;
use Drupal\Tests\UnitTestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Unit tests for the promoter's location resolution and boundary check.
 *
 * Covers the decisions that do not need a saved entity:
 * - the jurisdiction check (child, then root, fail-open without validator),
 * - geocoded points outside the jurisdiction are dropped (hint and regex),
 *   and a hint that resolved outside ends geocoding (no regex fallback),
 * - the location modes (confirmed is boundary-checked and never geocoded,
 *   none never geocodes, auto uses the suggested address only on request),
 * - PromoteOptions defaults and validation,
 * - a held lock rejects the promotion before any storage access, and a lock
 *   lost during geocoding rejects it before anything is created.
 *
 * @group markaspot_mail_inbound
 * @coversDefaultClass \Drupal\markaspot_mail_inbound\Service\InboundMailPromoter
 */
class InboundMailPromoterLocationTest extends UnitTestCase {

  /**
   * Builds a promoter with the given geocoder, validator and resolver.
   */
  protected function promoter(?object $geocoder = NULL, ?object $validator = NULL, ?object $resolver = NULL, ?LockBackendInterface $lock = NULL): TestableLocationPromoter {
    return new TestableLocationPromoter(
      $this->createMock(EntityTypeManagerInterface::class),
      $this->createMock(ModuleHandlerInterface::class),
      $this->createMock(EventDispatcherInterface::class),
      $this->createMock(LoggerChannelInterface::class),
      $this->createMock(EntityFieldManagerInterface::class),
      $this->createMock(ConfigFactoryInterface::class),
      $this->createMock(FileSystemInterface::class),
      $this->createMock(Token::class),
      $this->createMock(InternalRemarkWriter::class),
      $lock ?? $this->createMock(LockBackendInterface::class),
      new \stdClass(),
      $geocoder,
      $this->createMock(LanguageManagerInterface::class),
      $validator,
      $resolver,
    );
  }

  /**
   * Builds a staged mail mock.
   */
  protected function mail(int $gid = 11, string $body = 'Light broken.', ?string $suggestedAddress = NULL): InboundMail {
    $mail = $this->createMock(InboundMail::class);
    $mail->method('id')->willReturn(5);
    $mail->method('getJurisdictionId')->willReturn($gid);
    $mail->method('getBody')->willReturn($body);
    $mail->method('getSuggestedAddress')->willReturn($suggestedAddress);
    return $mail;
  }

  /**
   * A geocoder double resolving addresses from a map.
   *
   * @param array<string, array{lat: float, lng: float}> $map
   *   Coordinates keyed by address.
   */
  protected function geocoder(array $map): object {
    return new class($map) {

      /**
       * Addresses the double was asked for.
       *
       * @var string[]
       */
      public array $queried = [];

      public function __construct(protected array $map) {}

      /**
       * Mirrors MarkaspotGeocoderService::getCoordinatesFromAddress().
       */
      public function getCoordinatesFromAddress(string $address): ?array {
        $this->queried[] = $address;
        return $this->map[$address] ?? NULL;
      }

    };
  }

  /**
   * A boundary validator double: inside when lat < $maxLat.
   */
  protected function validator(float $maxLat = 10.0): object {
    return new class($maxLat) {

      /**
       * The id lists the double was asked for.
       *
       * @var array<int, int[]>
       */
      public array $calls = [];

      public function __construct(protected float $maxLat) {}

      /**
       * Mirrors BoundaryValidator::isWithinFirstUsableBoundary().
       */
      public function isWithinFirstUsableBoundary(array $ids, float $lat, float $lng): bool {
        $this->calls[] = $ids;
        return $lat < $this->maxLat;
      }

    };
  }

  /**
   * A hierarchy resolver double returning a fixed root, or throwing.
   */
  protected function resolver(?int $root, bool $throw = FALSE): object {
    return new class($root, $throw) {

      public function __construct(protected ?int $root, protected bool $throw) {}

      /**
       * Mirrors JurisdictionHierarchyResolver::getRootJurisdictionId().
       */
      public function getRootJurisdictionId(int $groupId): ?int {
        if ($this->throw) {
          throw new \RuntimeException('Broken hierarchy.');
        }
        return $this->root;
      }

    };
  }

  /**
   * The check asks child then root; no validator or no jurisdiction is open.
   *
   * @covers ::isInsideJurisdiction
   */
  public function testIsInsideJurisdictionChecksChildThenRoot(): void {
    $validator = $this->validator();
    $promoter = $this->promoter(NULL, $validator, $this->resolver(1));
    $this->assertTrue($promoter->isInsideJurisdiction(11, 5.0, 5.0));
    $this->assertFalse($promoter->isInsideJurisdiction(11, 50.0, 5.0));
    $this->assertSame([11, 1], $validator->calls[0]);

    // A root jurisdiction resolves to itself: asked once, not twice.
    $rootValidator = $this->validator();
    $this->promoter(NULL, $rootValidator, $this->resolver(11))->isInsideJurisdiction(11, 5.0, 5.0);
    $this->assertSame([11], $rootValidator->calls[0]);

    // A broken hierarchy still checks the child's own boundary.
    $childOnly = $this->validator();
    $this->assertFalse($this->promoter(NULL, $childOnly, $this->resolver(NULL, TRUE))->isInsideJurisdiction(11, 50.0, 5.0));
    $this->assertSame([11], $childOnly->calls[0]);

    // Fail-open: no validation module, or a mail without jurisdiction.
    $this->assertTrue($this->promoter()->isInsideJurisdiction(11, 50.0, 5.0));
    $unscoped = $this->validator();
    $this->assertTrue($this->promoter(NULL, $unscoped)->isInsideJurisdiction(0, 50.0, 5.0));
    $this->assertSame([], $unscoped->calls);
  }

  /**
   * A hint resolving outside ends geocoding: the regex match is not tried.
   *
   * Decision E: the stronger signal (this address is elsewhere) must not be
   * overridden by a bare regex street that also exists inside.
   *
   * @covers ::tryGeocode
   */
  public function testGeocodedHintOutsideEndsGeocoding(): void {
    $geocoder = $this->geocoder([
      'Elsewhere 1' => ['lat' => 50.0, 'lng' => 5.0],
      'Hauptstrasse 5' => ['lat' => 5.0, 'lng' => 5.0],
    ]);
    $promoter = $this->promoter($geocoder, $this->validator());

    $this->assertNull($promoter->exposeTryGeocode($this->mail(11, 'Lamp at Hauptstrasse 5 is out.'), 'Elsewhere 1'));
    $this->assertSame(['Elsewhere 1'], $geocoder->queried, 'The regex match is not geocoded after an outside hint.');
  }

  /**
   * A hint that does not resolve falls back to the regex match.
   *
   * @covers ::tryGeocode
   */
  public function testUnresolvedHintFallsBackToRegex(): void {
    $geocoder = $this->geocoder([
      'Hauptstrasse 5' => ['lat' => 5.0, 'lng' => 5.0],
    ]);
    $promoter = $this->promoter($geocoder, $this->validator());

    $result = $promoter->exposeTryGeocode($this->mail(11, 'Lamp at Hauptstrasse 5 is out.'), 'Nowhere 1');
    $this->assertSame(['lat' => 5.0, 'lng' => 5.0, 'source' => PromotionResult::LOCATION_REGEX], $result);
    $this->assertSame(['Nowhere 1', 'Hauptstrasse 5'], $geocoder->queried);
  }

  /**
   * Every geocoded point outside the jurisdiction leaves the report without.
   *
   * @covers ::tryGeocode
   */
  public function testGeocodedPointsOutsideLeaveReportUngeolocated(): void {
    $geocoder = $this->geocoder([
      'Elsewhere 1' => ['lat' => 50.0, 'lng' => 5.0],
      'Hauptstrasse 5' => ['lat' => 60.0, 'lng' => 5.0],
    ]);
    $promoter = $this->promoter($geocoder, $this->validator());

    // Without a hint, a regex match outside is dropped as well.
    $this->assertNull($promoter->exposeTryGeocode($this->mail(11, 'Lamp at Hauptstrasse 5 is out.'), NULL));
    $this->assertSame(['Hauptstrasse 5'], $geocoder->queried);

    // Inside: the hint wins and is reported as the suggested source.
    $inside = $this->promoter($this->geocoder(['Marktplatz 1' => ['lat' => 1.0, 'lng' => 2.0]]), $this->validator());
    $this->assertSame(['lat' => 1.0, 'lng' => 2.0, 'source' => PromotionResult::LOCATION_SUGGESTED], $inside->exposeTryGeocode($this->mail(), 'Marktplatz 1'));
  }

  /**
   * Confirmed points are boundary-checked and never geocoded.
   *
   * @covers ::resolveLocation
   */
  public function testConfirmedLocationIsCheckedNotGeocoded(): void {
    $geocoder = $this->geocoder(['Marktplatz 1' => ['lat' => 1.0, 'lng' => 2.0]]);
    $promoter = $this->promoter($geocoder, $this->validator());
    $mail = $this->mail(11, 'Lamp at Hauptstrasse 5 is out.', 'Marktplatz 1');

    $confirmed = $promoter->exposeResolveLocation($mail, NULL, new PromoteOptions(TRUE, TRUE, PromoteOptions::LOCATION_CONFIRMED, 3.5, 4.5));
    $this->assertSame(['lat' => 3.5, 'lng' => 4.5, 'source' => PromotionResult::LOCATION_CONFIRMED], $confirmed);
    $this->assertSame([], $geocoder->queried, 'A confirmed point is never geocoded again.');

    $this->expectException(LocationOutsideJurisdictionException::class);
    $promoter->exposeResolveLocation($mail, NULL, new PromoteOptions(TRUE, TRUE, PromoteOptions::LOCATION_CONFIRMED, 50.0, 4.5));
  }

  /**
   * Mode none never geocodes; auto uses the suggestion only on request.
   *
   * @covers ::resolveLocation
   */
  public function testNoneAndAutoModes(): void {
    $geocoder = $this->geocoder(['Marktplatz 1' => ['lat' => 1.0, 'lng' => 2.0]]);
    $promoter = $this->promoter($geocoder, $this->validator());
    $mail = $this->mail(11, 'No street here.', 'Marktplatz 1');

    $this->assertNull($promoter->exposeResolveLocation($mail, NULL, new PromoteOptions(TRUE, TRUE, PromoteOptions::LOCATION_NONE)));
    $this->assertSame([], $geocoder->queried);

    // Default options (admin form, legacy payload): the suggestion is unused.
    $this->assertNull($promoter->exposeResolveLocation($mail, NULL, new PromoteOptions()));
    $this->assertSame([], $geocoder->queried);

    $suggested = $promoter->exposeResolveLocation($mail, NULL, new PromoteOptions(TRUE, TRUE));
    $this->assertSame(PromotionResult::LOCATION_SUGGESTED, $suggested['source']);
    $this->assertSame(['Marktplatz 1'], $geocoder->queried);
  }

  /**
   * PromoteOptions defaults reproduce the pre-dialog behavior.
   *
   * @covers \Drupal\markaspot_mail_inbound\Service\PromoteOptions::__construct
   * @covers \Drupal\markaspot_mail_inbound\Service\PromoteOptions::isValidCoordinate
   */
  public function testPromoteOptions(): void {
    $defaults = new PromoteOptions();
    $this->assertTrue($defaults->useSuggestedDescription);
    $this->assertFalse($defaults->useSuggestedAddress);
    $this->assertSame(PromoteOptions::LOCATION_AUTO, $defaults->locationMode);

    $this->assertTrue(PromoteOptions::isValidCoordinate(-90.0, 180.0));
    $this->assertFalse(PromoteOptions::isValidCoordinate(90.1, 0.0));
    $this->assertFalse(PromoteOptions::isValidCoordinate(0.0, -180.1));
    $this->assertFalse(PromoteOptions::isValidCoordinate(INF, 0.0));
    $this->assertFalse(PromoteOptions::isValidCoordinate(NAN, 0.0));
    $this->assertFalse(PromoteOptions::isValidCoordinate(NULL, 0.0));

    $this->expectException(\InvalidArgumentException::class);
    new PromoteOptions(TRUE, FALSE, PromoteOptions::LOCATION_CONFIRMED);
  }

  /**
   * A held lock rejects the promotion before the mail is even reloaded.
   *
   * @covers ::promote
   */
  public function testHeldLockRejectsPromotion(): void {
    $lock = $this->createMock(LockBackendInterface::class);
    $lock->expects($this->once())
      ->method('acquire')
      ->with('markaspot_mail_inbound.promote.5')
      ->willReturn(FALSE);
    $lock->expects($this->never())->method('release');

    try {
      $this->promoter(NULL, NULL, NULL, $lock)->promote($this->mail(), 7);
      $this->fail('A held lock must reject the promotion.');
    }
    catch (PromotionConflictException $e) {
      $this->assertSame(PromotionConflictException::IN_PROGRESS, $e->reason);
    }
  }

  /**
   * A lock lost while geocoding rejects the promotion before any write.
   *
   * The first acquire succeeds; the re-check after geocoding finds the lock
   * taken over by another request (expired during a hung geocoder).
   *
   * @covers ::promote
   * @covers ::doPromote
   */
  public function testLockLostDuringGeocodingRejectsBeforeWrite(): void {
    $lock = $this->createMock(LockBackendInterface::class);
    $lock->expects($this->exactly(2))
      ->method('acquire')
      ->with('markaspot_mail_inbound.promote.5')
      ->willReturnOnConsecutiveCalls(TRUE, FALSE);
    $lock->expects($this->once())->method('release')->with('markaspot_mail_inbound.promote.5');

    $stored = $this->createMock(InboundMail::class);
    $stored->method('id')->willReturn(5);
    $stored->method('getState')->willReturn(InboundMail::STATE_STAGED);
    $stored->method('getJurisdictionId')->willReturn(11);
    $stored->method('getBody')->willReturn('Lamp at Hauptstrasse 5 is out.');
    $stored->expects($this->never())->method('save');

    $mailStorage = $this->createMock(EntityStorageInterface::class);
    $mailStorage->method('loadUnchanged')->with(5)->willReturn($stored);
    $termStorage = $this->createMock(EntityStorageInterface::class);
    $termStorage->method('load')->willReturn(NULL);
    $nodeStorage = $this->createMock(EntityStorageInterface::class);
    $nodeStorage->expects($this->never())->method('create');

    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->method('getStorage')->willReturnMap([
      ['inbound_mail', $mailStorage],
      ['taxonomy_term', $termStorage],
      ['node', $nodeStorage],
    ]);

    $processor = new class() {

      /**
       * Counts processor calls; the loser must not reach node creation.
       */
      public int $calls = 0;

      /**
       * Mirrors GeoreportProcessorService::prepareNodeProperties().
       */
      public function prepareNodeProperties(array $requestData, string $operation): array {
        $this->calls++;
        return [];
      }

    };

    $promoter = new TestableLocationPromoter(
      $entityTypeManager,
      $this->createMock(ModuleHandlerInterface::class),
      $this->createMock(EventDispatcherInterface::class),
      $this->createMock(LoggerChannelInterface::class),
      $this->createMock(EntityFieldManagerInterface::class),
      $this->createMock(ConfigFactoryInterface::class),
      $this->createMock(FileSystemInterface::class),
      $this->createMock(Token::class),
      $this->createMock(InternalRemarkWriter::class),
      $lock,
      $processor,
      $this->geocoder(['Hauptstrasse 5' => ['lat' => 5.0, 'lng' => 5.0]]),
      $this->createMock(LanguageManagerInterface::class),
      $this->validator(),
      NULL,
    );

    try {
      $promoter->promote($this->mail(), 7);
      $this->fail('A lock lost during geocoding must reject the promotion.');
    }
    catch (PromotionConflictException $e) {
      $this->assertSame(PromotionConflictException::IN_PROGRESS, $e->reason);
    }
    $this->assertSame(0, $processor->calls, 'Nothing is prepared or created after the lock was lost.');
  }

}

/**
 * Test-only subclass exposing the promoter's location helpers.
 */
class TestableLocationPromoter extends InboundMailPromoter {

  /**
   * Exposes tryGeocode().
   */
  public function exposeTryGeocode(InboundMail $mail, ?string $addressHint): ?array {
    return $this->tryGeocode($mail, $addressHint);
  }

  /**
   * Exposes resolveLocation().
   */
  public function exposeResolveLocation(InboundMail $mail, ?string $addressHint, PromoteOptions $options): ?array {
    return $this->resolveLocation($mail, $addressHint, $options);
  }

}
