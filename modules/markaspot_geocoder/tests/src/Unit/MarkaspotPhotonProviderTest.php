<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_geocoder\Unit;

use Drupal\markaspot_geocoder\Geocoder\Provider\MarkaspotPhoton;
use Drupal\Tests\UnitTestCase;
use Geocoder\Exception\InvalidServerResponse;
use Geocoder\Exception\QuotaExceeded;
use Geocoder\Query\GeocodeQuery;
use Geocoder\Query\ReverseQuery;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;

/**
 * Tests the Photon provider against recorded Photon responses.
 *
 * The fixtures are real answers of the fleet Photon (Germany index,
 * 2026-10-16), reduced to the fields the provider reads.
 */
#[CoversClass(MarkaspotPhoton::class)]
#[Group('markaspot_geocoder')]
final class MarkaspotPhotonProviderTest extends UnitTestCase {

  /**
   * The last request the fake HTTP client received.
   */
  private ?RequestInterface $lastRequest = NULL;

  /**
   * Tests a reverse lookup on a house maps address, admin levels and raw keys.
   */
  public function testReverseMapsHouseFeature(): void {
    $provider = $this->provider(200, [
      'type' => 'FeatureCollection',
      'features' => [[
        'type' => 'Feature',
        'properties' => [
          'osm_key' => 'building', 'type' => 'house',
          'housenumber' => '13', 'street' => 'Feldstraße',
          'locality' => 'Altstadt', 'district' => 'Bleckede', 'city' => 'Bleckede',
          'county' => 'Landkreis Lüneburg', 'state' => 'Niedersachsen',
          'country' => 'Deutschland', 'postcode' => '21354', 'countrycode' => 'de',
          'extent' => [10.7340109, 53.2887178, 10.7342668, 53.2885852],
        ],
        'geometry' => ['type' => 'Point', 'coordinates' => [10.7341388, 53.2886515]],
      ]],
    ]);

    $result = $provider->reverseQuery(ReverseQuery::fromCoordinates(53.2886, 10.7342)->withLocale('de'));
    $address = $result->first();

    $this->assertSame('Feldstraße', $address->getStreetName());
    $this->assertSame('13', $address->getStreetNumber());
    $this->assertSame('21354', $address->getPostalCode());
    $this->assertSame('Bleckede', $address->getLocality());
    $this->assertSame('Bleckede', $address->getSubLocality());
    $this->assertSame('DE', $address->getCountry()?->getCode());
    $this->assertSame('Niedersachsen', $address->getAdminLevels()->first()->getName());
    $this->assertEqualsWithDelta(53.2886515, $address->getCoordinates()->getLatitude(), 1e-7);
    $this->assertEqualsWithDelta(10.7341388, $address->getCoordinates()->getLongitude(), 1e-7);
    $this->assertEqualsWithDelta(53.2885852, $address->getBounds()->getSouth(), 1e-7);
    $this->assertEqualsWithDelta(10.7342668, $address->getBounds()->getEast(), 1e-7);

    $this->assertSame([
      'district' => 'Bleckede',
      'locality' => 'Altstadt',
      'city' => 'Bleckede',
      'county' => 'Landkreis Lüneburg',
      'state' => 'Niedersachsen',
      'city_district' => 'Bleckede',
      'suburb' => 'Bleckede',
      'quarter' => 'Altstadt',
      'neighbourhood' => 'Altstadt',
    ], $provider->getLastRawProperties());

    $uri = $this->lastRequest->getUri();
    $this->assertSame('/reverse', $uri->getPath());
    parse_str($uri->getQuery(), $query);
    $this->assertSame('de', $query['lang']);
    $this->assertSame('1', $query['limit']);
    $this->assertSame(53.2886, round((float) $query['lat'], 4));
  }

  /**
   * Tests a street feature carries the street name in "name".
   */
  public function testReverseUsesNameOfStreetFeature(): void {
    $provider = $this->provider(200, [
      'features' => [[
        'properties' => [
          'osm_key' => 'highway', 'osm_value' => 'primary', 'type' => 'street',
          'name' => 'Oxfordstraße', 'locality' => 'Bonn-Zentrum',
          'district' => 'Stadtbezirk Bonn', 'city' => 'Bonn', 'postcode' => '53111', 'countrycode' => 'DE',
        ],
        'geometry' => ['coordinates' => [7.0981292, 50.7373456]],
      ]],
    ]);

    $address = $provider->reverseQuery(ReverseQuery::fromCoordinates(50.7374, 7.0982))->first();

    $this->assertSame('Oxfordstraße', $address->getStreetName());
    $this->assertNull($address->getStreetNumber());
    $this->assertSame('Stadtbezirk Bonn', $provider->getLastRawProperties()['district']);
    $this->assertSame('Bonn-Zentrum', $provider->getLastRawProperties()['locality']);
  }

  /**
   * Tests the default district mappings find the Nominatim-compatible keys.
   *
   * The shipped defaults look for city_district|suburb|borough and
   * quarter|neighbourhood. Without the aliases a Photon tenant would never
   * match and a pin move would clear the district fields.
   */
  public function testRawPropertiesServeDefaultDistrictMappings(): void {
    $provider = $this->provider(200, [
      'features' => [[
        'properties' => [
          'type' => 'street',
          'name' => 'Oxfordstraße',
          'district' => 'Stadtbezirk Bonn',
          'locality' => 'Bonn-Zentrum',
        ],
        'geometry' => ['coordinates' => [7.0981, 50.7373]],
      ]],
    ]);
    $provider->reverseQuery(ReverseQuery::fromCoordinates(50.7374, 7.0982));
    $raw = $provider->getLastRawProperties();

    $firstMatch = static function (array $chain) use ($raw): ?string {
      foreach ($chain as $key) {
        if (isset($raw[$key])) {
          return $raw[$key];
        }
      }
      return NULL;
    };
    $this->assertSame('Stadtbezirk Bonn', $firstMatch(['city_district', 'suburb', 'borough']));
    $this->assertSame('Bonn-Zentrum', $firstMatch(['quarter', 'neighbourhood']));
  }

  /**
   * Tests a POI on a highway (bus stop) does not become the street.
   */
  public function testHighwayPoiNameIsNotStreet(): void {
    $provider = $this->provider(200, [
      'features' => [[
        'properties' => [
          'osm_key' => 'highway',
          'osm_value' => 'bus_stop',
          'type' => 'house',
          'name' => 'Hauptbahnhof',
          'city' => 'Erfurt',
        ],
        'geometry' => ['coordinates' => [11.0386, 50.9724]],
      ]],
    ]);

    $address = $provider->reverseQuery(ReverseQuery::fromCoordinates(50.9724, 11.0386))->first();

    $this->assertNull($address->getStreetName());
  }

  /**
   * Tests a POI name is never taken as the street name.
   */
  public function testReverseDoesNotUsePoiNameAsStreet(): void {
    $provider = $this->provider(200, [
      'features' => [[
        'properties' => ['osm_key' => 'amenity', 'type' => 'house', 'name' => 'Takumi', 'city' => 'Erfurt'],
        'geometry' => ['coordinates' => [11.0294, 50.9782]],
      ]],
    ]);

    $address = $provider->reverseQuery(ReverseQuery::fromCoordinates(50.9782, 11.0294))->first();

    $this->assertNull($address->getStreetName());
    $this->assertSame('Erfurt', $address->getLocality());
  }

  /**
   * Tests an empty FeatureCollection is no result and clears raw properties.
   */
  public function testEmptyResultClearsRawProperties(): void {
    $provider = $this->provider(
      200,
      [
        'features' => [[
          'properties' => ['district' => 'Altstadt'],
          'geometry' => ['coordinates' => [11.0, 50.9]],
        ]],
      ],
      ['type' => 'FeatureCollection', 'features' => []],
    );

    $provider->reverseQuery(ReverseQuery::fromCoordinates(50.9, 11.0));
    $this->assertNotSame([], $provider->getLastRawProperties());

    // Same provider instance, second response is empty.
    $this->assertSame(0, $provider->reverseQuery(ReverseQuery::fromCoordinates(52.37, 4.89))->count());
    $this->assertSame([], $provider->getLastRawProperties());
  }

  /**
   * Tests features without point coordinates are skipped.
   */
  public function testFeaturesWithoutCoordinatesAreSkipped(): void {
    $provider = $this->provider(200, [
      'features' => [
        ['properties' => ['city' => 'Broken'], 'geometry' => ['coordinates' => ['x']]],
        ['properties' => ['city' => 'Erfurt'], 'geometry' => ['coordinates' => [11.03, 50.98]]],
      ],
    ]);

    $result = $provider->geocodeQuery(GeocodeQuery::create('Erfurt')->withLimit(5));

    $this->assertSame(1, $result->count());
    $this->assertSame('Erfurt', $result->first()->getLocality());
  }

  /**
   * Tests the country filter drops foreign hits and asks for more candidates.
   */
  public function testForwardCountryFilter(): void {
    $provider = $this->provider(200, [
      'features' => [
        [
          'properties' => ['city' => 'Basel', 'countrycode' => 'CH'],
          'geometry' => ['coordinates' => [7.59, 47.56]],
        ],
        [
          'properties' => ['city' => 'Herzogenaurach', 'countrycode' => 'DE'],
          'geometry' => ['coordinates' => [10.88, 49.57]],
        ],
      ],
    ]);

    $result = $provider->geocodeQuery(GeocodeQuery::create('Marktplatz')->withLimit(1)->withData('country', 'de'));

    $this->assertSame(1, $result->count());
    $this->assertSame('Herzogenaurach', $result->first()->getLocality());
    parse_str($this->lastRequest->getUri()->getQuery(), $query);
    $this->assertSame('/api', $this->lastRequest->getUri()->getPath());
    $this->assertSame('Marktplatz', $query['q']);
    $this->assertGreaterThanOrEqual(5, (int) $query['limit']);
  }

  /**
   * Tests unsupported locales send no lang parameter.
   */
  public function testUnsupportedLocaleSendsNoLanguage(): void {
    $provider = $this->provider(200, ['features' => []]);
    $provider->geocodeQuery(GeocodeQuery::create('Damrak')->withLocale('nl'));

    parse_str($this->lastRequest->getUri()->getQuery(), $query);
    $this->assertArrayNotHasKey('lang', $query);
  }

  /**
   * Tests a non-GeoJSON body is a server error without the query in it.
   */
  public function testInvalidPayloadThrowsWithoutQueryInMessage(): void {
    $provider = $this->provider(200, ['error' => 'nope']);

    try {
      $provider->reverseQuery(ReverseQuery::fromCoordinates(50.123456, 11.654321));
      $this->fail('Expected InvalidServerResponse.');
    }
    catch (InvalidServerResponse $exception) {
      $this->assertStringNotContainsString('50.123456', $exception->getMessage());
      $this->assertStringNotContainsString('lat=', $exception->getMessage());
    }
  }

  /**
   * Tests HTTP errors surface as server errors.
   */
  public function testHttpErrorThrowsWithoutQueryInMessage(): void {
    $provider = $this->provider(502, 'Bad Gateway');

    try {
      $provider->reverseQuery(ReverseQuery::fromCoordinates(50.123456, 11.654321));
      $this->fail('Expected InvalidServerResponse.');
    }
    catch (InvalidServerResponse $exception) {
      $this->assertStringNotContainsString('lat=', $exception->getMessage());
      $this->assertStringNotContainsString('50.123456', $exception->getMessage());
      $this->assertStringNotContainsString('10.10.1.3', $exception->getMessage());
    }
  }

  /**
   * Tests transport errors (timeouts, refused) do not leak the query either.
   */
  public function testTransportErrorThrowsWithoutQueryInMessage(): void {
    $client = $this->createMock(ClientInterface::class);
    $client->method('sendRequest')->willThrowException(
      new \RuntimeException('cURL error 28: timeout for http://10.10.1.3:2322/api?q=Fischmarkt+1'),
    );
    $provider = new MarkaspotPhoton($client, 'http://10.10.1.3:2322');

    try {
      $provider->geocodeQuery(GeocodeQuery::create('Fischmarkt 1'));
      $this->fail('Expected InvalidServerResponse.');
    }
    catch (InvalidServerResponse $exception) {
      $this->assertStringNotContainsString('Fischmarkt', $exception->getMessage());
      $this->assertStringContainsString('RuntimeException', $exception->getMessage());
    }
  }

  /**
   * Tests rate limiting keeps its own exception type.
   */
  public function testQuotaExceededIsRethrown(): void {
    $provider = $this->provider(429, 'Too Many Requests');

    $this->expectException(QuotaExceeded::class);
    $provider->reverseQuery(ReverseQuery::fromCoordinates(50.9, 11.0));
  }

  /**
   * Creates a provider whose HTTP client answers with the given bodies.
   *
   * Each call consumes the next body; the last one repeats.
   */
  private function provider(int $status, array|string ...$bodies): MarkaspotPhoton {
    $client = $this->createMock(ClientInterface::class);
    $client->method('sendRequest')->willReturnCallback(function (RequestInterface $request) use ($status, &$bodies) {
      $this->lastRequest = $request;
      $body = count($bodies) > 1 ? array_shift($bodies) : $bodies[0];
      return new Response($status, ['Content-Type' => 'application/json'], is_string($body) ? $body : json_encode($body));
    });
    return new MarkaspotPhoton($client, 'http://10.10.1.3:2322/');
  }

}
