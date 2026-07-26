<?php

declare(strict_types=1);

namespace Drupal\markaspot_fastmap\Service;

/**
 * Generates deterministic placeholder signets.
 */
final class PlaceholderSignetGenerator implements PlaceholderSignetGeneratorInterface {

  private const VIEW_WIDTH = 96;

  private const VIEW_HEIGHT = 104;

  private const SILHOUETTES = [
    [
      'path' => 'M14 0H82A14 14 0 0 1 96 14V58C96 81 76 96 48 104C20 96 0 81 0 58V14A14 14 0 0 1 14 0Z',
    ],
    [
      'path' => 'M16 0H80A16 16 0 0 1 96 16V78A26 26 0 0 1 70 104H26A26 26 0 0 1 0 78V16A16 16 0 0 1 16 0Z',
    ],
    [
      'path' => 'M0 34A48 34 0 0 1 96 34V58C96 81 76 96 48 104C20 96 0 81 0 58Z',
    ],
    [
      'path' => 'M8 0H88A8 8 0 0 1 96 8V70L48 104L0 70V8A8 8 0 0 1 8 0Z',
    ],
  ];

  private const ORNAMENTS = [
    [
      'slim' => 'M40 0H56V104H40Z',
      'broad' => 'M32 0H64V104H32Z',
      'asymmetric' => FALSE,
    ],
    [
      'slim' => 'M-8 54L54 -8H80L-8 80Z',
      'broad' => 'M-8 54L54 -8H108L-8 108Z',
      'asymmetric' => TRUE,
    ],
    [
      'slim' => 'M48 26L96 74V94L48 46L0 94V74Z',
      'broad' => 'M48 18L96 66V98L48 50L0 98V66Z',
      'asymmetric' => FALSE,
    ],
    [
      'slim' => 'M32 0H64L48 66Z',
      'broad' => 'M20 0H76L48 82Z',
      'asymmetric' => FALSE,
    ],
  ];

  private const WEIGHTS = ['slim', 'broad'];

  /**
   * {@inheritdoc}
   */
  public function generate(string $seed, string $primaryHex): string {
    $silhouette = self::SILHOUETTES[$this->hash($seed, 1) % count(self::SILHOUETTES)];
    $ornament = self::ORNAMENTS[$this->hash($seed, 2) % count(self::ORNAMENTS)];
    $weight = self::WEIGHTS[$this->hash($seed, 3) % count(self::WEIGHTS)];
    $mirrored = $ornament['asymmetric'] && $this->hash($seed, 4) % 2 === 1;
    $tone = $this->ornamentTone($primaryHex);
    $uid = 'c' . base_convert((string) $this->hash($seed, 4), 10, 36);
    $flip = $mirrored
      ? ' transform="translate(' . self::VIEW_WIDTH . ' 0) scale(-1 1)"'
      : '';

    return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 '
      . self::VIEW_WIDTH . ' ' . self::VIEW_HEIGHT . '" '
      . 'role="img" aria-label="Placeholder logo" data-generated="placeholder">'
      . '<defs><clipPath id="' . $uid . '"><path d="' . $silhouette['path'] . '"/></clipPath></defs>'
      . '<g clip-path="url(#' . $uid . ')">'
      . '<path d="' . $silhouette['path'] . '" fill="' . $primaryHex . '"/>'
      . '<g' . $flip . '><path d="' . $ornament[$weight] . '" fill="' . $tone . '"/></g>'
      . '</g></svg>';
  }

  /**
   * Multiplies two integers with JavaScript Math.imul() semantics.
   */
  private function imul(int $left, int $right): int {
    $leftLow = $left & 0xFFFF;
    $leftHigh = ($left >> 16) & 0xFFFF;
    $rightLow = $right & 0xFFFF;
    $rightHigh = ($right >> 16) & 0xFFFF;

    return (
      ($leftLow * $rightLow)
      + ((($leftHigh * $rightLow + $leftLow * $rightHigh) & 0xFFFF) << 16)
    ) & 0xFFFFFFFF;
  }

  /**
   * Applies the Murmur3 32-bit finalizer.
   */
  private function fmix32(int $hash): int {
    $hash ^= $hash >> 16;
    $hash = $this->imul($hash, 0x85EBCA6B) & 0xFFFFFFFF;
    $hash ^= $hash >> 13;
    $hash = $this->imul($hash, 0xC2B2AE35) & 0xFFFFFFFF;
    $hash ^= $hash >> 16;

    return $hash & 0xFFFFFFFF;
  }

  /**
   * Computes FNV-1a over the seed's JavaScript UTF-16 code units.
   */
  private function baseHash(string $seed): int {
    $hash = 0x811C9DC5;
    $utf16 = mb_convert_encoding($seed, 'UTF-16LE', 'UTF-8');
    $codeUnits = unpack('v*', $utf16);

    foreach ($codeUnits ?: [] as $codeUnit) {
      $hash ^= $codeUnit;
      $hash = $this->imul($hash, 0x01000193) & 0xFFFFFFFF;
    }

    return $hash & 0xFFFFFFFF;
  }

  /**
   * Computes one independently salted hash axis.
   */
  private function hash(string $seed, int $salt): int {
    $salted = $this->baseHash($seed) ^ $this->imul($salt, 0x9E3779B9);

    return $this->fmix32($salted & 0xFFFFFFFF);
  }

  /**
   * Derives the neutral ornament tone from the primary color.
   */
  private function ornamentTone(string $primaryHex): string {
    $lightness = $this->lightness($primaryHex);
    $target = $lightness > 55 ? $lightness - 34 : $lightness + 34;

    return $this->greyAt(max(26, min(82, $target)));
  }

  /**
   * Computes CIE L* for an sRGB hex color.
   */
  private function lightness(string $hex): float {
    $channels = array_map(
      $this->toLinear(...),
      $this->parseHex($hex),
    );
    $y = 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];

    return $y > 0.008856 ? 116 * pow($y, 1 / 3) - 16 : 903.3 * $y;
  }

  /**
   * Parses a three- or six-digit sRGB hex color.
   *
   * @return array<int, float>
   *   Normalized red, green, and blue channels.
   */
  private function parseHex(string $hex): array {
    $trimmed = trim($hex);
    $hashless = preg_replace('/#/', '', $trimmed, 1) ?? $trimmed;
    $full = strlen($hashless) === 3
      ? implode('', array_map(
        static fn(string $channel): string => $channel . $channel,
        str_split($hashless),
      ))
      : $hashless;

    return array_map(
      static fn(int $offset): float => hexdec(substr($full, $offset, 2)) / 255,
      [0, 2, 4],
    );
  }

  /**
   * Converts an sRGB channel to linear light.
   */
  private function toLinear(float $channel): float {
    return $channel <= 0.04045
      ? $channel / 12.92
      : pow(($channel + 0.055) / 1.055, 2.4);
  }

  /**
   * Converts a linear-light channel to sRGB.
   */
  private function toGamma(float $channel): float {
    return $channel <= 0.0031308
      ? 12.92 * $channel
      : 1.055 * pow($channel, 1 / 2.4) - 0.055;
  }

  /**
   * Builds a neutral gray at the given CIE L*.
   */
  private function greyAt(float $lightness): string {
    $y = $lightness > 8
      ? pow(($lightness + 16) / 116, 3)
      : $lightness / 903.3;
    $channel = (int) round(max(0, min(1, $this->toGamma($y))) * 255);
    $hex = str_pad(dechex($channel), 2, '0', STR_PAD_LEFT);

    return '#' . $hex . $hex . $hex;
  }

}
