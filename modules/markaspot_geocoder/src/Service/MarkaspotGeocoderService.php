<?php

namespace Drupal\markaspot_geocoder\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\markaspot_geocoder\Geocoder\Provider\MarkaspotMapbox;
use Drupal\markaspot_geocoder\Geocoder\Provider\MarkaspotNominatim;
use Geocoder\Provider\Provider;
use Geocoder\Query\ReverseQuery;
use Geocoder\StatefulGeocoder;
use GuzzleHttp\ClientInterface;
use Http\Adapter\Guzzle7\Client as GuzzleAdapter;
use Psr\Log\LoggerInterface;

/**
 * Server-side geocoding service for Mark-a-Spot.
 */
class MarkaspotGeocoderService {

  protected ConfigFactoryInterface $configFactory;
  protected ClientInterface $httpClient;
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
   */
  protected function createProvider(): Provider {
    $config = $this->configFactory->get('markaspot_geocoder.settings');

    $providerName = getenv('GEOCODER_PROVIDER') ?: $config->get('provider') ?: 'nominatim';
    $apiKey = getenv('GEOCODER_API_KEY') ?: $config->get('mapbox_token') ?: '';

    // Wrap GuzzleHttp\Client in PSR-18 adapter for geocoder providers.
    $adapter = new GuzzleAdapter($this->httpClient);

    $this->lastProvider = match ($providerName) {
      'mapbox', 'mapbox_address' => new MarkaspotMapbox($adapter, $apiKey),
      default => new MarkaspotNominatim($adapter, 'https://nominatim.openstreetmap.org'),
    };

    return $this->lastProvider;
  }

  /**
   * Resolves the language for geocoding results.
   *
   * Fallback chain: ENV GEOCODER_LANGUAGE > config language > 'de'.
   */
  protected function getLanguage(): string {
    $config = $this->configFactory->get('markaspot_geocoder.settings');

    return getenv('GEOCODER_LANGUAGE') ?: $config->get('language') ?: 'de';
  }

  /**
   *
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
    catch (\OutOfBoundsException $ex) {
      $this->logger->warning('No address found for coordinates: @lat, @lng', [
        '@lat' => $lat,
        '@lng' => $lng,
      ]);
      return NULL;
    }
    catch (\Exception $ex) {
      $this->logger->error('Error during geocoding: @message | Type: @type | File: @file:@line | Trace: @trace', [
        '@message' => $ex->getMessage() ?: 'Unknown error',
        '@type' => get_class($ex),
        '@file' => $ex->getFile(),
        '@line' => $ex->getLine(),
        '@trace' => substr($ex->getTraceAsString(), 0, 500),
      ]);
      return NULL;
    }
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
