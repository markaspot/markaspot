<?php

declare(strict_types=1);

namespace Drupal\markaspot_geocoder\Geocoder\Provider;

use Geocoder\Collection;
use Geocoder\Exception\InvalidCredentials;
use Geocoder\Exception\InvalidServerResponse;
use Geocoder\Exception\QuotaExceeded;
use Geocoder\Http\Provider\AbstractHttpProvider;
use Geocoder\Location;
use Geocoder\Model\AddressBuilder;
use Geocoder\Model\AddressCollection;
use Geocoder\Provider\Provider;
use Geocoder\Query\GeocodeQuery;
use Geocoder\Query\ReverseQuery;
use Psr\Http\Client\ClientInterface;

/**
 * Photon geocoding provider (OpenStreetMap, komoot/photon API).
 *
 * Talks to a Photon server, typically the fleet's own instance on the
 * private network (GEOCODER_PHOTON_URL=http://10.10.1.3:2322) so report
 * coordinates never leave our infrastructure. photon.komoot.io works as a
 * public fallback with the same API.
 *
 * Raw properties for the district mapping carry Photon's own keys (see
 * RAW_PROPERTIES) plus Nominatim-compatible aliases (see RAW_ALIASES), the
 * same way the Mapbox provider does. The shipped default mappings look for
 * city_district/suburb and quarter/neighbourhood; without the aliases they
 * would never match and a pin move would clear the district fields. Term
 * names still differ between providers ("Stadtbezirk Bonn" vs "Bonn"), so a
 * tenant switching providers reviews its taxonomy against real reports.
 */
final class MarkaspotPhoton extends AbstractHttpProvider implements Provider {

  /**
   * Photon properties exposed for the district mapping.
   */
  public const RAW_PROPERTIES = ['district', 'locality', 'city', 'county', 'state'];

  /**
   * Nominatim-compatible aliases for Photon keys, used by default mappings.
   */
  public const RAW_ALIASES = [
    'district' => ['city_district', 'suburb'],
    'locality' => ['quarter', 'neighbourhood'],
  ];

  /**
   * Languages photon.komoot.io and the fleet index answer; others get none.
   */
  private const LANGUAGES = ['de', 'en', 'fr'];

  /**
   * Upper bound for forward results requested from Photon.
   */
  private const MAX_LIMIT = 20;

  /**
   * Root URL of the Photon server, without trailing slash.
   */
  private string $rootUrl;

  /**
   * Raw properties of the first feature of the last query.
   *
   * @var array<string, string>
   */
  private array $lastRawProperties = [];

  /**
   * Constructs the provider.
   *
   * @param \Psr\Http\Client\ClientInterface $client
   *   The HTTP client.
   * @param string $rootUrl
   *   Root URL of the Photon server, e.g. http://10.10.1.3:2322.
   */
  public function __construct(ClientInterface $client, string $rootUrl) {
    parent::__construct($client);
    $this->rootUrl = rtrim($rootUrl, '/');
  }

  /**
   * {@inheritdoc}
   */
  public function geocodeQuery(GeocodeQuery $query): Collection {
    $text = trim($query->getText());
    if ($text === '') {
      return new AddressCollection([]);
    }

    // Photon has no country filter. When the caller asks for one, fetch a few
    // more candidates and drop the foreign ones below.
    $country = $query->getData('country');
    $country = is_string($country) && preg_match('/^[A-Za-z]{2}$/', $country) ? strtoupper($country) : NULL;
    $limit = max(1, min(self::MAX_LIMIT, $query->getLimit()));
    $requestLimit = $country !== NULL ? min(self::MAX_LIMIT, max(5, $limit * 3)) : $limit;

    $params = ['q' => $text, 'limit' => $requestLimit];
    $this->addLanguage($params, $query->getLocale());
    $features = $this->fetchFeatures('/api', $params);

    if ($country !== NULL) {
      $features = array_values(array_filter(
        $features,
        static fn (array $f): bool => strtoupper((string) ($f['properties']['countrycode'] ?? '')) === $country,
      ));
    }

    return $this->toCollection(array_slice($features, 0, $limit));
  }

  /**
   * {@inheritdoc}
   */
  public function reverseQuery(ReverseQuery $query): Collection {
    $coordinates = $query->getCoordinates();
    $params = [
      'lat' => sprintf('%F', $coordinates->getLatitude()),
      'lon' => sprintf('%F', $coordinates->getLongitude()),
      'limit' => 1,
    ];
    $this->addLanguage($params, $query->getLocale());

    return $this->toCollection($this->fetchFeatures('/reverse', $params));
  }

  /**
   * Returns raw Photon properties of the first result of the last query.
   *
   * @return array<string, string>
   *   Keys from RAW_PROPERTIES that Photon returned, with string values.
   */
  public function getLastRawProperties(): array {
    return $this->lastRawProperties;
  }

  /**
   * {@inheritdoc}
   */
  public function getName(): string {
    return 'photon';
  }

  /**
   * Adds the lang parameter when the locale is one Photon indexes.
   */
  private function addLanguage(array &$params, ?string $locale): void {
    $language = strtolower(substr((string) $locale, 0, 2));
    if (in_array($language, self::LANGUAGES, TRUE)) {
      $params['lang'] = $language;
    }
  }

  /**
   * Fetches and validates the GeoJSON features of a Photon endpoint.
   *
   * @return array<int, array>
   *   Features that carry point coordinates.
   */
  private function fetchFeatures(string $path, array $params): array {
    $url = $this->rootUrl . $path . '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);

    // The URL carries the queried address or coordinates (personal data).
    // The base provider and the HTTP client put it into their exception
    // messages, so every failure is rethrown with the exception type only.
    try {
      $content = $this->getUrlContents($url);
    }
    catch (InvalidCredentials | QuotaExceeded $e) {
      throw $e;
    }
    catch (\Throwable $e) {
      throw new InvalidServerResponse('Photon request failed (' . get_class($e) . ').');
    }

    $payload = json_decode($content, TRUE);
    if (!is_array($payload) || !isset($payload['features']) || !is_array($payload['features'])) {
      throw new InvalidServerResponse('Invalid Photon response (no FeatureCollection).');
    }

    return array_values(array_filter($payload['features'], static function ($feature): bool {
      $point = $feature['geometry']['coordinates'] ?? NULL;
      return is_array($feature)
        && is_array($feature['properties'] ?? NULL)
        && is_array($point) && isset($point[0], $point[1])
        && is_numeric($point[0]) && is_numeric($point[1]);
    }));
  }

  /**
   * Converts features to an address collection and records raw properties.
   */
  private function toCollection(array $features): AddressCollection {
    $this->lastRawProperties = [];
    if ($features === []) {
      return new AddressCollection([]);
    }

    $this->lastRawProperties = $this->rawProperties($features[0]['properties']);

    return new AddressCollection(array_map(fn (array $feature): Location => $this->toLocation($feature), $features));
  }

  /**
   * Builds a Location from one Photon feature.
   */
  private function toLocation(array $feature): Location {
    $p = $feature['properties'];
    [$lng, $lat] = $feature['geometry']['coordinates'];
    $builder = new AddressBuilder($this->getName());
    $builder->setCoordinates((float) $lat, (float) $lng);

    foreach (['state', 'county'] as $i => $key) {
      $name = $this->string($p, $key);
      if ($name !== NULL) {
        $builder->addAdminLevel($i + 1, $name, NULL);
      }
    }

    // A street feature carries its name in "name", not in "street". Photon's
    // normalized type keeps POIs on a highway (bus stops) out of this.
    $street = $this->string($p, 'street');
    if ($street === NULL && ($p['type'] ?? NULL) === 'street') {
      $street = $this->string($p, 'name');
    }
    $builder->setStreetName($street);
    $builder->setStreetNumber($this->string($p, 'housenumber'));

    $postcode = $this->string($p, 'postcode');
    $builder->setPostalCode($postcode !== NULL ? trim(explode(';', $postcode)[0]) : NULL);

    // A place feature (city, town, village) carries its name in "name".
    $locality = $this->string($p, 'city');
    if ($locality === NULL && ($p['type'] ?? NULL) === 'city') {
      $locality = $this->string($p, 'name');
    }
    $builder->setLocality($locality);
    $builder->setSubLocality($this->string($p, 'district'));

    $builder->setCountry($this->string($p, 'country'));
    $countryCode = $this->string($p, 'countrycode');
    $builder->setCountryCode($countryCode !== NULL ? strtoupper($countryCode) : NULL);

    // Photon extent: [west, north, east, south].
    $extent = $p['extent'] ?? NULL;
    if (is_array($extent) && count($extent) === 4 && count(array_filter($extent, 'is_numeric')) === 4) {
      [$west, $north, $east, $south] = array_map('floatval', $extent);
      $builder->setBounds($south, $west, $north, $east);
    }

    return $builder->build();
  }

  /**
   * Picks the district-mapping properties of one feature.
   *
   * @return array<string, string>
   *   Non-empty string values keyed by Photon property name, plus the
   *   Nominatim-compatible aliases of RAW_ALIASES.
   */
  private function rawProperties(array $properties): array {
    $raw = [];
    foreach (self::RAW_PROPERTIES as $key) {
      $value = $this->string($properties, $key);
      if ($value !== NULL) {
        $raw[$key] = $value;
      }
    }
    foreach (self::RAW_ALIASES as $key => $aliases) {
      if (isset($raw[$key])) {
        foreach ($aliases as $alias) {
          $raw[$alias] ??= $raw[$key];
        }
      }
    }
    return $raw;
  }

  /**
   * Returns a trimmed, non-empty string property or NULL.
   */
  private function string(array $properties, string $key): ?string {
    $value = $properties[$key] ?? NULL;
    if (!is_scalar($value)) {
      return NULL;
    }
    $value = trim((string) $value);
    return $value === '' ? NULL : $value;
  }

}
