<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_geocoder\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\markaspot_geocoder\Service\MarkaspotGeocoderService;
use Drupal\Tests\UnitTestCase;
use Geocoder\Collection;
use Geocoder\Location;
use Geocoder\Model\AddressBuilder;
use Geocoder\Model\AddressCollection;
use Geocoder\Provider\Provider;
use Geocoder\Query\GeocodeQuery;
use Geocoder\Query\ReverseQuery;
use GuzzleHttp\ClientInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\LoggerInterface;

/**
 * Tests the Mark-a-Spot geocoder service.
 */
#[CoversClass(MarkaspotGeocoderService::class)]
#[Group('markaspot_geocoder')]
final class MarkaspotGeocoderServiceTest extends UnitTestCase {

  /**
   * Tests forward geocoding returns normalized coordinates and address data.
   */
  public function testGetCoordinatesFromAddressReturnsCoordinates(): void {
    $provider = new RecordingGeocoderProvider([
      $this->createAddress(51.4324, 6.7652),
    ]);

    $result = $this->createService($provider)->getCoordinatesFromAddress(' Friedrich-Ebert-Strasse 134, Duisburg ');

    $this->assertNotNull($result);
    $this->assertSame(51.4324, $result['lat']);
    $this->assertSame(6.7652, $result['lng']);
    $this->assertSame('DE', $result['country_code']);
    $this->assertSame('Duisburg', $result['locality']);
    $this->assertSame('47053', $result['postal_code']);
    $this->assertSame('Friedrich-Ebert-Strasse 134', $result['address_line1']);
    $this->assertSame('recording', $result['provider']);
    $this->assertSame('Friedrich-Ebert-Strasse 134, Duisburg', $provider->lastGeocodeQuery?->getText());
    $this->assertSame('de', $provider->lastGeocodeQuery?->getLocale());
  }

  /**
   * Tests provider-specific options are sanitized before provider calls.
   */
  public function testGetCoordinatesFromAddressSanitizesProviderOptions(): void {
    $provider = new RecordingGeocoderProvider([
      $this->createAddress(51.4324, 6.7652),
    ]);

    $this->createService($provider)->getCoordinatesFromAddress('Duisburg', [
      'country' => 'de',
      'location_type' => ['address', 'invalid_type', 'poi', 'address'],
      'fuzzy_match' => FALSE,
    ]);

    $query = $provider->lastGeocodeQuery;
    $this->assertInstanceOf(GeocodeQuery::class, $query);
    $this->assertSame(1, $query->getLimit());
    $this->assertSame('DE', $query->getData('country'));
    $this->assertSame(['address', 'poi'], $query->getData('location_type'));
    $this->assertFalse($query->getData('fuzzy_match'));
  }

  /**
   * Tests empty input does not call the provider.
   */
  public function testGetCoordinatesFromAddressReturnsNullForEmptyAddress(): void {
    $provider = new RecordingGeocoderProvider();

    $this->assertNull($this->createService($provider)->getCoordinatesFromAddress('   '));
    $this->assertSame(0, $provider->geocodeCalls);
  }

  /**
   * Tests empty provider results are treated as no coordinates.
   */
  public function testGetCoordinatesFromAddressReturnsNullWhenProviderFindsNothing(): void {
    $provider = new RecordingGeocoderProvider();

    $this->assertNull($this->createService($provider)->getCoordinatesFromAddress('Nowhere'));
    $this->assertSame(1, $provider->geocodeCalls);
  }

  /**
   * Tests provider failures do not log raw addresses or tokens.
   */
  public function testGetCoordinatesFromAddressDoesNotLogAddressOrTokenOnFailure(): void {
    $provider = new RecordingGeocoderProvider(
      exception: new \RuntimeException('Failed URL with Friedrich-Ebert-Strasse and access_token=pk.secret'),
    );
    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->once())
      ->method('error')
      ->with(
        $this->logicalNot($this->stringContains('Friedrich-Ebert-Strasse')),
        $this->callback(static function (array $context): bool {
          $payload = json_encode($context);
          return is_string($payload)
            && !str_contains($payload, 'Friedrich-Ebert-Strasse')
            && !str_contains($payload, 'pk.secret')
            && !array_key_exists('@trace', $context);
        }),
      );

    $result = $this->createService($provider, $logger)
      ->getCoordinatesFromAddress('Friedrich-Ebert-Strasse 134, Duisburg');

    $this->assertNull($result);
  }

  /**
   * Tests address-only node saves do not trigger forward geocoding.
   */
  public function testPresaveReturnsBeforeGeocoderServiceWhenGeolocationIsEmpty(): void {
    if (!function_exists('markaspot_geocoder_node_presave')) {
      require_once dirname(__DIR__, 3) . '/markaspot_geocoder.module';
    }

    $geolocation = $this->createMock(FieldItemListInterface::class);
    $geolocation->expects($this->once())
      ->method('isEmpty')
      ->willReturn(TRUE);

    $node = $this->createMock(ContentEntityInterface::class);
    $node->expects($this->once())
      ->method('bundle')
      ->willReturn('service_request');
    $node->expects($this->once())
      ->method('hasField')
      ->with('field_geolocation')
      ->willReturn(TRUE);
    $node->expects($this->once())
      ->method('get')
      ->with('field_geolocation')
      ->willReturn($geolocation);

    \markaspot_geocoder_node_presave($node);
  }

  /**
   * Creates the service with a controlled provider.
   */
  private function createService(Provider $provider, ?LoggerInterface $logger = NULL): MarkaspotGeocoderService {
    return new TestableMarkaspotGeocoderService(
      $this->createMock(ConfigFactoryInterface::class),
      $this->createMock(ClientInterface::class),
      $logger ?? $this->createMock(LoggerInterface::class),
      $provider,
    );
  }

  /**
   * Creates a test geocoder address.
   */
  private function createAddress(float $lat, float $lng): Location {
    return (new AddressBuilder('recording'))
      ->setCoordinates($lat, $lng)
      ->setStreetName('Friedrich-Ebert-Strasse')
      ->setStreetNumber('134')
      ->setPostalCode('47053')
      ->setLocality('Duisburg')
      ->setCountryCode('DE')
      ->build();
  }

}

/**
 * Test double that exposes a controlled provider.
 */
final class TestableMarkaspotGeocoderService extends MarkaspotGeocoderService {

  public function __construct(
    ConfigFactoryInterface $config_factory,
    ClientInterface $http_client,
    LoggerInterface $logger,
    private readonly Provider $provider,
  ) {
    parent::__construct($config_factory, $http_client, $logger);
  }

  /**
   * {@inheritdoc}
   */
  protected function createProvider(): Provider {
    return $this->provider;
  }

  /**
   * {@inheritdoc}
   */
  protected function getLanguage(): string {
    return 'de';
  }

}

/**
 * Test geocoder provider that records forward-geocoding calls.
 */
final class RecordingGeocoderProvider implements Provider {

  /**
   * Last recorded forward geocoding query.
   */
  public ?GeocodeQuery $lastGeocodeQuery = NULL;

  /**
   * Number of forward geocoding calls.
   */
  public int $geocodeCalls = 0;

  /**
   * Constructs a recording geocoder provider.
   *
   * @param \Geocoder\Location[] $locations
   *   Locations returned by forward geocoding.
   * @param \Throwable|null $exception
   *   Optional exception thrown during forward geocoding.
   */
  public function __construct(
    private readonly array $locations = [],
    private readonly ?\Throwable $exception = NULL,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public function geocodeQuery(GeocodeQuery $query): Collection {
    $this->lastGeocodeQuery = $query;
    $this->geocodeCalls++;

    if ($this->exception) {
      throw $this->exception;
    }

    return new AddressCollection($this->locations);
  }

  /**
   * {@inheritdoc}
   */
  public function reverseQuery(ReverseQuery $query): Collection {
    return new AddressCollection();
  }

  /**
   * {@inheritdoc}
   */
  public function getName(): string {
    return 'recording';
  }

}
