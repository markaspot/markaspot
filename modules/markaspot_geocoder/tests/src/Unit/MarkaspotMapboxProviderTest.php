<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_geocoder\Unit;

use Drupal\markaspot_geocoder\Geocoder\Provider\MarkaspotMapbox;
use Drupal\Tests\UnitTestCase;
use Geocoder\Exception\InvalidServerResponse;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Psr\Http\Client\ClientInterface;

/**
 * Tests the hardened Mapbox provider response validator.
 *
 * The Mapbox request URL carries the access token as a query parameter.
 * Two leak paths must stay closed:
 * - Malformed JSON responses build an InvalidServerResponse whose message
 *   embeds the URL.
 * - Mapbox error JSON ({"message":"..."}) used to be returned as an empty
 *   AddressCollection, hiding token rotation and rate-limit issues.
 *
 * The provider now redacts the token before any exception is thrown and
 * surfaces Mapbox error JSON as an explicit InvalidServerResponse with a
 * short, token-free summary.
 */
#[CoversClass(MarkaspotMapbox::class)]
#[Group('markaspot_geocoder')]
final class MarkaspotMapboxProviderTest extends UnitTestCase {

  /**
   * Tests Mapbox error JSON is surfaced as a server error, token-stripped.
   */
  public function testValidateResponseTreatsMapboxErrorJsonAsServerError(): void {
    $url = 'https://api.mapbox.com/geocoding/v5/mapbox.places/6.0,51.0.json'
      . '?access_token=pk.eyJ.SECRET&language=de';
    $content = '{"message":"Not Authorized - Invalid Token","code":"InvalidToken"}';

    try {
      $this->invokeValidateResponse($url, $content);
      $this->fail('Expected InvalidServerResponse for Mapbox error JSON.');
    }
    catch (InvalidServerResponse $exception) {
      $this->assertStringContainsString('Mapbox API error', $exception->getMessage());
      $this->assertStringContainsString('Not Authorized', $exception->getMessage());
      $this->assertStringNotContainsString('pk.eyJ.SECRET', $exception->getMessage());
      $this->assertStringNotContainsString('access_token=pk', $exception->getMessage());
    }
  }

  /**
   * Tests malformed JSON raises a server error without the token in message.
   */
  public function testValidateResponseRedactsTokenFromMalformedJsonError(): void {
    $url = 'https://api.mapbox.com/geocoding/v5/mapbox.places/6.0,51.0.json'
      . '?access_token=pk.eyJ.SECRET&language=de';

    try {
      $this->invokeValidateResponse($url, 'not-json');
      $this->fail('Expected InvalidServerResponse for malformed JSON.');
    }
    catch (InvalidServerResponse $exception) {
      $this->assertStringNotContainsString('pk.eyJ.SECRET', $exception->getMessage());
      $this->assertStringNotContainsString('access_token=pk', $exception->getMessage());
      $this->assertStringContainsString('[REDACTED]', $exception->getMessage());
    }
  }

  /**
   * Tests a well-formed response with features survives validation untouched.
   */
  public function testValidateResponsePassesThroughValidPayload(): void {
    $url = 'https://api.mapbox.com/geocoding/v5/mapbox.places/6.0,51.0.json'
      . '?access_token=pk.eyJ.SECRET';
    $content = '{"type":"FeatureCollection","features":[{"id":"address.123"}]}';

    $payload = $this->invokeValidateResponse($url, $content);

    $this->assertIsArray($payload);
    $this->assertArrayHasKey('features', $payload);
    $this->assertCount(1, $payload['features']);
  }

  /**
   * Invokes the protected MarkaspotMapbox::validateResponse() via reflection.
   *
   * @param string $url
   *   The request URL.
   * @param string $content
   *   The raw response body.
   *
   * @return array
   *   The decoded JSON payload, when validation succeeds.
   *
   * @throws \Geocoder\Exception\InvalidServerResponse
   *   When the provider rejects the response.
   */
  private function invokeValidateResponse(string $url, string $content): array {
    $client = $this->createMock(ClientInterface::class);
    $provider = new MarkaspotMapbox($client, 'pk.eyJ.SECRET');

    $method = new \ReflectionMethod($provider, 'validateResponse');
    $method->setAccessible(TRUE);
    return $method->invoke($provider, $url, $content);
  }

}
