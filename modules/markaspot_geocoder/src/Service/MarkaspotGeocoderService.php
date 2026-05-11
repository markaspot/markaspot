<?php

namespace Drupal\markaspot_geocoder\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\markaspot_geocoder\Geocoder\Provider\MarkaspotMapbox;
use Drupal\markaspot_geocoder\Geocoder\Provider\MarkaspotNominatim;
use Geocoder\Exception\CollectionIsEmpty;
use Geocoder\Provider\Provider;
use Geocoder\Query\GeocodeQuery;
use Geocoder\Query\ReverseQuery;
use Geocoder\StatefulGeocoder;
use GuzzleHttp\ClientInterface;
use Http\Adapter\Guzzle7\Client as GuzzleAdapter;
use Psr\Log\LoggerInterface;

/**
 * Server-side geocoding service for Mark-a-Spot.
 */
class MarkaspotGeocoderService {

  /**
   * Maximum number of provider-specific location type filters.
   */
  private const MAX_LOCATION_TYPE_FILTERS = 5;

  /**
   * Placeholder used in log/exception context.
   *
   * Anything that would otherwise carry a provider credential is replaced
   * with this short, all-caps marker, which keeps the redaction easy to
   * grep for in tests and watchdog.
   */
  private const REDACTED = '[REDACTED]';

  /**
   * Decimal precision for coordinates logged in informational paths.
   *
   * Three decimals correspond to roughly 110 metres, which is sufficient to
   * triage "no result" events without persisting precise citizen-report
   * locations in watchdog. Service request coordinates are PII; raw values
   * stay out of logs.
   */
  private const LOG_COORD_PRECISION = 3;

  /**
   * Geocoder configuration.
   */
  protected ConfigFactoryInterface $configFactory;

  /**
   * HTTP client used by geocoder providers.
   */
  protected ClientInterface $httpClient;

  /**
   * Geocoder logger.
   */
  protected LoggerInterface $logger;

  /**
   * The last created provider instance, for accessing raw properties.
   */
  protected MarkaspotMapbox|MarkaspotNominatim|null $lastProvider = NULL;

  public function __construct(
    ConfigFactoryInterface $config_factory,
    ClientInterface $http_client,
    LoggerInterface $logger,
  ) {
    $this->configFactory = $config_factory;
    $this->httpClient = $http_client;
    $this->logger = $logger;
  }

  /**
   * Creates the geocoder provider based on ENV vars or Drupal config.
   *
   * Fallback chain: ENV GEOCODER_PROVIDER > config provider > 'nominatim'.
   *
   * Unknown provider names log a warning and fall back to Nominatim. This is
   * fail-open by design: the presave hook runs on every report save, and a
   * typo in GEOCODER_PROVIDER must not block report creation. Operators get a
   * watchdog signal instead of a silent backend switch.
   */
  protected function createProvider(): Provider {
    $config = $this->configFactory->get('markaspot_geocoder.settings');

    $providerName = strtolower(trim((string) (getenv('GEOCODER_PROVIDER') ?: $config->get('provider') ?: 'nominatim')));
    $apiKey = trim((string) (getenv('GEOCODER_API_KEY') ?: $config->get('mapbox_token') ?: ''));

    // Wrap GuzzleHttp\Client in PSR-18 adapter for geocoder providers.
    $adapter = new GuzzleAdapter($this->httpClient);

    $this->lastProvider = match ($providerName) {
      'mapbox', 'mapbox_address' => new MarkaspotMapbox($adapter, $apiKey),
      'nominatim' => new MarkaspotNominatim($adapter, 'https://nominatim.openstreetmap.org'),
      default => $this->fallbackToNominatim($adapter, $providerName),
    };

    return $this->lastProvider;
  }

  /**
   * Logs an unknown provider name and returns the Nominatim fallback.
   */
  private function fallbackToNominatim(GuzzleAdapter $adapter, string $providerName): MarkaspotNominatim {
    $this->logger->warning('Unknown GEOCODER_PROVIDER "@name", falling back to nominatim.', [
      '@name' => $providerName,
    ]);
    return new MarkaspotNominatim($adapter, 'https://nominatim.openstreetmap.org');
  }

  /**
   * Resolves the language for geocoding results.
   *
   * Fallback chain: ENV GEOCODER_LANGUAGE > config language > 'de'.
   */
  protected function getLanguage(): string {
    $config = $this->configFactory->get('markaspot_geocoder.settings');

    return strtolower(trim((string) (getenv('GEOCODER_LANGUAGE') ?: $config->get('language') ?: 'de')));
  }

  /**
   * Resolves coordinates to address fields and district metadata.
   */
  public function getAddressFromCoordinates($lat, $lng): ?array {
    $provider = $this->createProvider();
    $language = $this->getLanguage();
    $geocoder = new StatefulGeocoder($provider, $language);

    try {
      $result = $geocoder->reverseQuery(ReverseQuery::fromCoordinates($lat, $lng));
      $address = $result->first();

      return [
        'country_code' => $address->getCountry()?->getCode() ?? 'DE',
        'locality' => $address->getLocality() ?? '',
        'address_line1' => ($address->getStreetName() ?? '') . ' ' . ($address->getStreetNumber() ?? ''),
        'postal_code' => $address->getPostalCode() ?? '',
        'admin_area' => $address->getAdminLevels()?->first()?->getName() ?? '',
        'district_properties' => $this->lastProvider?->getLastRawProperties() ?? [],
      ];
    }
    catch (CollectionIsEmpty | \OutOfBoundsException $ex) {
      // Empty reverse result. The willdurand AddressCollection::first()
      // raises CollectionIsEmpty (a LogicException), older code paths
      // raised OutOfBoundsException; both mean "no address". Coordinates
      // are rounded to avoid persisting precise report locations in
      // watchdog. See LOG_COORD_PRECISION.
      $this->logger->info('No address found for coordinates near @lat, @lng.', [
        '@lat' => is_numeric($lat) ? round((float) $lat, self::LOG_COORD_PRECISION) : self::REDACTED,
        '@lng' => is_numeric($lng) ? round((float) $lng, self::LOG_COORD_PRECISION) : self::REDACTED,
      ]);
      return NULL;
    }
    catch (\Exception $ex) {
      // Never log $ex->getMessage() or the trace: provider exception messages
      // can carry the access_token URL (Mapbox path). Type + file + line is
      // enough to triage. Same shape as getCoordinatesFromAddress() below.
      $this->logger->error('Error during reverse geocoding | Type: @type | File: @file:@line', [
        '@type' => get_class($ex),
        '@file' => $ex->getFile(),
        '@line' => $ex->getLine(),
      ]);
      return NULL;
    }
  }

  /**
   * Resolves an address string to coordinates.
   *
   * This is an explicit forward-geocoding API for importers and queues. The
   * node presave hook intentionally stays reverse-only, so address-only nodes
   * are not mutated unless a caller opts in.
   *
   * Supported options:
   * - country: Provider-specific country filter, used by Mapbox.
   * - location_type: Provider-specific location type filter, used by Mapbox.
   * - fuzzy_match: Provider-specific fuzzy match toggle, used by Mapbox.
   */
  public function getCoordinatesFromAddress(string $address, array $options = []): ?array {
    $address = trim((string) preg_replace('/\s+/', ' ', $address));
    if ($address === '') {
      return NULL;
    }

    $provider = $this->createProvider();
    $language = $this->getLanguage();
    $geocoder = new StatefulGeocoder($provider, $language);

    $query = $this->buildForwardGeocodeQuery($address, $options);

    try {
      $result = $geocoder->geocodeQuery($query);
      $location = $result->first();
      $coordinates = $location->getCoordinates();

      if ($coordinates === NULL) {
        $this->logger->warning('No coordinates found for supplied address.');
        return NULL;
      }

      return [
        'lat' => $coordinates->getLatitude(),
        'lng' => $coordinates->getLongitude(),
        'country_code' => $location->getCountry()?->getCode() ?? 'DE',
        'locality' => $location->getLocality() ?? '',
        'postal_code' => $location->getPostalCode() ?? '',
        'street_name' => $location->getStreetName() ?? '',
        'street_number' => (string) ($location->getStreetNumber() ?? ''),
        'address_line1' => trim(($location->getStreetName() ?? '') . ' ' . ($location->getStreetNumber() ?? '')),
        'provider' => $location->getProvidedBy(),
        'district_properties' => $this->lastProvider?->getLastRawProperties() ?? [],
      ];
    }
    catch (CollectionIsEmpty $ex) {
      $this->logger->warning('No coordinates found for supplied address.');
      return NULL;
    }
    catch (\Exception $ex) {
      $this->logger->error('Error during forward geocoding for supplied address | Type: @type | File: @file:@line', [
        '@type' => get_class($ex),
        '@file' => $ex->getFile(),
        '@line' => $ex->getLine(),
      ]);
      return NULL;
    }
  }

  /**
   * Builds a forward-geocoding query from sanitized caller options.
   */
  private function buildForwardGeocodeQuery(string $address, array $options): GeocodeQuery {
    $query = GeocodeQuery::create($address)->withLimit(1);

    $country = $this->normalizeCountryOption($options['country'] ?? NULL);
    if ($country !== NULL) {
      $query = $query->withData('country', $country);
    }

    $locationType = $this->normalizeLocationTypeOption($options['location_type'] ?? NULL);
    if ($locationType !== NULL) {
      $query = $query->withData('location_type', $locationType);
    }

    if (array_key_exists('fuzzy_match', $options) && is_bool($options['fuzzy_match'])) {
      $query = $query->withData('fuzzy_match', $options['fuzzy_match']);
    }

    return $query;
  }

  /**
   * Normalizes a provider country filter to a single ISO 3166-1 alpha-2 code.
   */
  private function normalizeCountryOption(mixed $country): ?string {
    if (!is_string($country)) {
      return NULL;
    }

    $country = strtoupper(trim($country));

    return preg_match('/^[A-Z]{2}$/', $country) ? $country : NULL;
  }

  /**
   * Normalizes Mapbox location type filters to allowed provider values.
   */
  private function normalizeLocationTypeOption(mixed $locationType): string|array|null {
    $allowedTypes = MarkaspotMapbox::TYPES;

    if (is_string($locationType)) {
      $locationType = trim($locationType);
      return in_array($locationType, $allowedTypes, TRUE) ? $locationType : NULL;
    }

    if (!is_array($locationType)) {
      return NULL;
    }

    $types = array_values(array_filter(
      array_map(static fn ($type) => is_string($type) ? trim($type) : '', $locationType),
      static fn (string $type) => in_array($type, $allowedTypes, TRUE),
    ));
    $types = array_slice(array_values(array_unique($types)), 0, self::MAX_LOCATION_TYPE_FILTERS);

    return $types === [] ? NULL : $types;
  }

  /**
   * Returns the district mapping configuration.
   *
   * @return array
   *   Array of mapping definitions, each with:
   *   - geocoder_properties: Fallback chain of property names
   *   - field: Target entity reference field name
   *   - vocabulary: Target taxonomy vocabulary machine name
   *   - auto_create: Whether to auto-create terms
   */
  public function getDistrictMappings(): array {
    $config = $this->configFactory->get('markaspot_geocoder.settings');
    return $config->get('district_mappings') ?: [];
  }

}
