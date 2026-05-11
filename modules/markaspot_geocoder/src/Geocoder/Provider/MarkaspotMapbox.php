<?php

declare(strict_types=1);

/*
 * This file is part of the Geocoder package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */

namespace Drupal\markaspot_geocoder\Geocoder\Provider;

use Geocoder\Collection;
use Geocoder\Exception\InvalidArgument;
use Geocoder\Exception\InvalidServerResponse;
use Geocoder\Exception\UnsupportedOperation;
use Geocoder\Model\AddressCollection;
use Geocoder\Model\AddressBuilder;
use Geocoder\Query\GeocodeQuery;
use Geocoder\Query\ReverseQuery;
use Geocoder\Http\Provider\AbstractHttpProvider;
use Geocoder\Provider\Mapbox\Model\MapboxAddress;
use Geocoder\Provider\Provider;
use Psr\Http\Client\ClientInterface;

/**
 *
 */
final class MarkaspotMapbox extends AbstractHttpProvider implements Provider {
  /**
   * @var string
   */
  const GEOCODE_ENDPOINT_URL_SSL = 'https://api.mapbox.com/geocoding/v5/%s/%s.json';

  /**
   * @var string
   */
  const REVERSE_ENDPOINT_URL_SSL = 'https://api.mapbox.com/geocoding/v5/%s/%F,%F.json';

  /**
   * @var string
   */
  const GEOCODING_MODE_PLACES = 'mapbox.places';

  /**
   * @var string
   */
  const GEOCODING_MODE_PLACES_PERMANENT = 'mapbox.places-permanent';

  /**
   * @var array
   */
  const GEOCODING_MODES = [
    self::GEOCODING_MODE_PLACES,
    self::GEOCODING_MODE_PLACES_PERMANENT,
  ];

  /**
   * @var string
   */
  const TYPE_COUNTRY = 'country';

  /**
   * @var string
   */
  const TYPE_REGION = 'region';

  /**
   * @var string
   */
  const TYPE_POSTCODE = 'postcode';

  /**
   * @var string
   */
  const TYPE_DISTRICT = 'district';

  /**
   * @var string
   */
  const TYPE_PLACE = 'place';

  /**
   * @var string
   */
  const TYPE_LOCALITY = 'locality';

  /**
   * @var string
   */
  const TYPE_NEIGHBORHOOD = 'neighborhood';

  /**
   * @var string
   */
  const TYPE_ADDRESS = 'address';

  /**
   * @var string
   */
  const TYPE_POI = 'poi';

  /**
   * @var string
   */
  const TYPE_POI_LANDMARK = 'poi.landmark';

  /**
   * @var array
   */
  const TYPES = [
    self::TYPE_COUNTRY,
    self::TYPE_REGION,
    self::TYPE_POSTCODE,
    self::TYPE_DISTRICT,
    self::TYPE_PLACE,
    self::TYPE_LOCALITY,
    self::TYPE_NEIGHBORHOOD,
    self::TYPE_ADDRESS,
    self::TYPE_POI,
    self::TYPE_POI_LANDMARK,
  ];

  const DEFAULT_TYPE = self::TYPE_ADDRESS;

  /**
   * Placeholder substituted for the access_token in URLs.
   *
   * Mapbox request URLs carry the token in the query string. The willdurand
   * InvalidServerResponse builder embeds the URL into the exception message,
   * so any catch-all that logged that message would otherwise leak the
   * token to watchdog.
   */
  private const REDACTED_PARAM = '[REDACTED]';

  /**
   * Maximum length of any sanitized Mapbox error summary surfaced to callers.
   */
  private const ERROR_SUMMARY_MAX = 120;

  /**
   * @var \Psr\Http\Client\ClientInterface
   */
  private $client;

  /**
   * @var string
   */
  private $accessToken;

  /**
   * @var string|null
   */
  private $country;

  /**
   * @var string
   */
  private $geocodingMode;

  /**
   * Stores raw address properties from the last reverse geocode call.
   *
   * @var array
   */
  private $lastRawProperties = [];

  /**
   * @param \Psr\Http\Client\ClientInterface $client
   *   An HTTP adapter.
   * @param string $accessToken
   *   Your Mapbox access token.
   * @param string|null $country
   * @param string $geocodingMode
   */
  public function __construct(
    ClientInterface $client,
    string $accessToken,
    ?string $country = NULL,
    string $geocodingMode = self::GEOCODING_MODE_PLACES,
  ) {
    parent::__construct($client);

    if (!in_array($geocodingMode, self::GEOCODING_MODES)) {
      throw new InvalidArgument('The Mapbox geocoding mode should be either mapbox.places or mapbox.places-permanent.');
    }

    $this->client = $client;
    $this->accessToken = $accessToken;
    $this->country = $country;
    $this->geocodingMode = $geocodingMode;
  }

  /**
   *
   */
  public function geocodeQuery(GeocodeQuery $query): Collection {
    // Mapbox API returns invalid data if IP address given
    // This API doesn't handle IPs.
    if (filter_var($query->getText(), FILTER_VALIDATE_IP)) {
      throw new UnsupportedOperation('The Mapbox provider does not support IP addresses, only street addresses.');
    }

    $url = sprintf(self::GEOCODE_ENDPOINT_URL_SSL, $this->geocodingMode, rawurlencode($query->getText()));

    $urlParameters = [];
    if ($query->getBounds()) {
      // Format is "minLon,minLat,maxLon,maxLat".
      $urlParameters['bbox'] = sprintf(
            '%s,%s,%s,%s',
            $query->getBounds()->getWest(),
            $query->getBounds()->getSouth(),
            $query->getBounds()->getEast(),
            $query->getBounds()->getNorth()
        );
    }

    if (NULL !== $locationType = $query->getData('location_type')) {
      $urlParameters['types'] = is_array($locationType) ? implode(',', $locationType) : $locationType;
    }
    else {
      $urlParameters['types'] = self::DEFAULT_TYPE;
    }

    if (NULL !== $fuzzyMatch = $query->getData('fuzzy_match')) {
      $urlParameters['fuzzyMatch'] = $fuzzyMatch ? 'true' : 'false';
    }

    if ($urlParameters) {
      $url .= '?' . http_build_query($urlParameters);
    }

    return $this->fetchUrl($url, $query->getLimit(), $query->getLocale(), $query->getData('country', $this->country));
  }

  /**
   *
   */
  public function reverseQuery(ReverseQuery $query): Collection {
    $coordinate = $query->getCoordinates();
    $url = sprintf(
          self::REVERSE_ENDPOINT_URL_SSL,
          $this->geocodingMode,
          $coordinate->getLongitude(),
          $coordinate->getLatitude()
      );

    if (NULL !== $locationType = $query->getData('location_type')) {
      $urlParameters['types'] = is_array($locationType) ? implode(',', $locationType) : $locationType;
    }
    else {
      $urlParameters['types'] = self::DEFAULT_TYPE;
    }

    if ($urlParameters) {
      $url .= '?' . http_build_query($urlParameters);
    }

    return $this->fetchUrl($url, $query->getLimit(), $query->getLocale(), $query->getData('country', $this->country));
  }

  /**
   * {@inheritdoc}
   */
  public function getName(): string {
    return 'mapbox';
  }

  /**
   * Returns raw address properties from the last reverse geocode call.
   *
   * Keys use Nominatim-compatible names for consistent mapping:
   * neighborhood -> neighbourhood, locality -> suburb, district -> city_district.
   *
   * @return array
   */
  public function getLastRawProperties(): array {
    return $this->lastRawProperties;
  }

  /**
   * @param string $url
   * @param int $limit
   * @param string|null $locale
   * @param string|null $country
   *
   * @return string query with extra params
   */
  private function buildQuery(string $url, int $limit, ?string $locale = NULL, ?string $country = NULL): string {
    $parameters = array_filter([
      'country' => $country,
      'language' => $locale,
      'limit' => $limit,
      'access_token' => $this->accessToken,
    ]);

    $separator = parse_url($url, PHP_URL_QUERY) ? '&' : '?';

    return $url . $separator . http_build_query($parameters);
  }

  /**
   * @param string $url
   * @param int $limit
   * @param string|null $locale
   * @param string|null $country
   *
   * @return \Geocoder\Model\AddressCollection
   */
  private function fetchUrl(string $url, int $limit, ?string $locale = NULL, ?string $country = NULL): AddressCollection {
    $url = $this->buildQuery($url, $limit, $locale, $country);
    $content = $this->getUrlContents($url);
    $json = $this->validateResponse($url, $content);

    // No result.
    if (!isset($json['features']) || !count($json['features'])) {
      return new AddressCollection([]);
    }

    $results = [];
    $this->lastRawProperties = [];
    foreach ($json['features'] as $result) {
      if (!array_key_exists('context', $result)) {
        break;
      }

      $builder = new AddressBuilder($this->getName());
      $this->parseCoordinates($builder, $result);
      // $sublocality = $result;
      // set official Mapbox place id
      if (isset($result['id'])) {
        $builder->setValue('id', $result['id']);
      }

      // Set official Mapbox place id.
      if (isset($result['text'])) {
        $builder->setValue('street_name', $result['text']);
      }

      // Capture raw context properties for district mapping.
      // Map Mapbox types to Nominatim-compatible keys.
      $typeMapping = [
        'neighborhood' => 'neighbourhood',
        'locality' => 'suburb',
        'district' => 'city_district',
        'place' => 'city',
      ];
      foreach ($result['context'] as $component) {
        $typeParts = explode('.', $component['id']);
        $mapboxType = reset($typeParts);
        $key = $typeMapping[$mapboxType] ?? $mapboxType;
        if (isset($component['text']) && !isset($this->lastRawProperties[$key])) {
          $this->lastRawProperties[$key] = $component['text'];
        }
      }

      // Update address components.
      foreach ($result['context'] as $component) {
        $this->updateAddressComponent($builder, $component['id'], $component);
      }

      /** @var \Geocoder\Provider\Mapbox\Model\MapboxAddress $address */
      $address = $builder->build(MapboxAddress::class);
      $address = $address->withId($builder->getValue('id'));
      if (isset($result['address'])) {
        $address = $address->withStreetNumber($result['address']);
      }
      if (isset($result['place_type'])) {
        $address = $address->withResultType($result['place_type']);
      }
      if (isset($result['place_name'])) {
        $address = $address->withFormattedAddress($result['place_name']);
      }
      $address = $address->withNeighborhood($builder->getValue('sublocality'));
      $address = $address->withStreetName($builder->getValue('street_name'));
      // $address = $address->withNeighborhood($builder->getValue('neighborhood'));
      $results[] = $address;

      if (count($results) >= $limit) {
        break;
      }
    }

    return new AddressCollection($results);
  }

  /**
   * Update current resultSet with given key/value.
   *
   * @param \Geocoder\Model\AddressBuilder $builder
   * @param string $type
   *   Component type.
   * @param array $value
   *   The component value.
   */
  private function updateAddressComponent(AddressBuilder $builder, string $type, array $value) {
    $typeParts = explode('.', $type);
    $type = reset($typeParts);

    switch ($type) {
      case 'postcode':
        $builder->setPostalCode($value['text']);

        break;

      case 'locality':
        $builder->setSubLocality($value['text'] ?? '');
        break;

      case 'country':
        $builder->setCountry($value['text']);
        if (isset($value['short_code'])) {
          $builder->setCountryCode(strtoupper($value['short_code']));
        }

        break;

      case 'neighborhood':
        $builder->setValue($type, $value['text']);

        break;

      case 'place':
        $builder->addAdminLevel(1, $value['text']);
        $builder->setLocality($value['text']);

        break;

      case 'region':
        $code = NULL;
        if (!empty($value['short_code']) && preg_match('/[A-z]{2}-/', $value['short_code'])) {
          $code = preg_replace('/[A-z]{2}-/', '', $value['short_code']);
        }
        $builder->addAdminLevel(2, $value['text'], $code);

        break;

      default:
    }
  }

  /**
   * Decode the response content and validate it does not carry an error.
   *
   * Mapbox includes the access_token in the request URL's query string. The
   * willdurand InvalidServerResponse builder embeds the URL into its message,
   * so callers logging $ex->getMessage() would leak the token. The URL is
   * redacted in place before any exception is thrown.
   *
   * Mapbox error responses (HTTP 401, 403, 429, ...) return valid JSON with
   * a "message" key but no "features". The base implementation treated them
   * as "no result" and silently returned an empty collection, hiding token
   * rotation and rate-limit issues from operators. Such responses are now
   * surfaced as InvalidServerResponse with a sanitized summary.
   *
   * @param string $url
   *   The request URL. May contain access_token in the query string.
   * @param string $content
   *   The raw response body.
   *
   * @return array
   *   The decoded JSON payload.
   *
   * @throws \Geocoder\Exception\InvalidServerResponse
   *   When the response is not parseable JSON or carries a Mapbox error.
   */
  private function validateResponse(string $url, $content): array {
    $safeUrl = preg_replace(
      '/([?&])access_token=[^&]*/',
      '$1access_token=' . self::REDACTED_PARAM,
      $url
    ) ?? $url;

    $json = json_decode($content, TRUE);

    if (!isset($json) || JSON_ERROR_NONE !== json_last_error()) {
      throw InvalidServerResponse::create($safeUrl);
    }

    // Mapbox error JSON: parseable, but no features and a message string.
    if (is_array($json) && !isset($json['features']) && isset($json['message'])) {
      throw new InvalidServerResponse(sprintf(
        'Mapbox API error: %s',
        $this->sanitizeError($json)
      ));
    }

    return $json;
  }

  /**
   * Builds a short, token-free summary of a Mapbox error JSON payload.
   *
   * @param array $json
   *   Decoded Mapbox error response.
   *
   * @return string
   *   Sanitized "<code> <message>" summary, capped at ERROR_SUMMARY_MAX.
   */
  private function sanitizeError(array $json): string {
    $parts = [];
    if (isset($json['code']) && (is_string($json['code']) || is_int($json['code']))) {
      $parts[] = (string) $json['code'];
    }
    if (isset($json['message']) && is_string($json['message'])) {
      $parts[] = $json['message'];
    }
    $summary = trim(implode(' ', $parts));

    // Defensive: a misconfigured Mapbox response could echo the token back.
    $summary = preg_replace('/access_token=[^&\s"\']+/i', 'access_token=' . self::REDACTED_PARAM, $summary) ?? $summary;
    $summary = preg_replace('/\bpk\.[A-Za-z0-9_\-\.]+/', self::REDACTED_PARAM, $summary) ?? $summary;

    return mb_substr($summary, 0, self::ERROR_SUMMARY_MAX);
  }

  /**
   * Parse coordinats and bounds.
   *
   * @param \Geocoder\Model\AddressBuilder $builder
   * @param array $result
   */
  private function parseCoordinates(AddressBuilder $builder, array $result) {
    $coordinates = $result['geometry']['coordinates'];
    $builder->setCoordinates($coordinates[1], $coordinates[0]);

    if (isset($result['bbox'])) {
      $builder->setBounds(
            $result['bbox'][1],
            $result['bbox'][0],
            $result['bbox'][3],
            $result['bbox'][2]
        );
    }
  }

}
