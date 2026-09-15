<?php

declare(strict_types=1);

namespace Drupal\markaspot\Config;

use Drupal\Core\Config\StorageInterface;
use Drupal\Core\Config\StorageTransformerException;

/**
 * Converts ECA 2 import storage to the ECA 3 Modeler API representation.
 *
 * Only the supplied storage is written. Unlike the upstream post update, this
 * never saves entities or runs model-owner setters (setModelData saves config).
 */
final class EcaModelImportNormalizer {

  /**
   * Constructs the normalizer.
   *
   * @param \Closure|null $parseMetadata
   *   Optional read-only BPMN metadata parser, primarily for isolated tests.
   * @param \Closure|null $calculateDependencies
   *   Optional dependency resolver for offline exports across different sites.
   * @param \Drupal\Core\Config\StorageInterface|null $identityStorage
   *   Read-only active identities and storage settings for migrated models.
   */
  public function __construct(
    private readonly ?\Closure $parseMetadata = NULL,
    private readonly ?\Closure $calculateDependencies = NULL,
    private readonly ?StorageInterface $identityStorage = NULL,
  ) {}

  /**
   * Normalizes a source known to be destined for ECA 3.
   *
   * @param \Drupal\Core\Config\StorageInterface $source
   *   Mutable import storage, or an explicitly selected offline export.
   *
   * @throws \Drupal\Core\Config\StorageTransformerException
   *   If legacy model data cannot be converted without losing source changes.
   */
  public function normalize(StorageInterface $source): void {
    $writes = [];
    foreach ($source->listAll('eca.eca.') as $name) {
      try {
        $eca = $source->read($name);
        $id = substr($name, strlen('eca.eca.'));
        if (!is_array($eca) || ($eca['id'] ?? NULL) !== $id) {
          throw new \UnexpectedValueException('Missing or mismatched ECA id.');
        }
        // An explicit ECA 3 source is authoritative, including modeler changes.
        if (isset($eca['third_party_settings']['modeler_api'])) {
          $this->validateModelerData($source, $eca, $id);
          $metadata = $eca['third_party_settings']['modeler_api'];
          if (str_starts_with($metadata['data'] ?? '', 'hash:')) {
            $modelId = 'eca_' . $metadata['modeler_id'] . '_' . $id;
            $modelName = 'modeler_api.data_model.' . $modelId;
            $writes[$modelName] = $this->completeDataModel($source->read($modelName), $modelId);
          }
          $writes[$name] = self::orderEca($eca);
          continue;
        }
        $legacy = $source->read('eca.model.' . $id);
        if ($legacy !== FALSE && (!is_array($legacy)
          || !array_key_exists('modeldata', $legacy)
          || !is_string($legacy['modeldata']))) {
          throw new \UnexpectedValueException('Legacy modeldata must be a string.');
        }
        $xml = $legacy['modeldata'] ?? '';
        if ($xml !== '') {
          $modeler = $legacy['modeller'] ?? $eca['modeller'] ?? 'bpmn_io';
          if ($modeler !== 'bpmn_io') {
            throw new \UnexpectedValueException('Unsupported legacy modeller: ' . (string) $modeler);
          }
          $xml = self::normalizeFormFields($xml);
          $metadata = $this->parseMetadata !== NULL
            ? ($this->parseMetadata)($xml)
            : $this->parseBpmnMetadata($xml);
          // Operators can disable the executable model independently of the
          // diagram's isExecutable flag. Preserve that explicit source state.
          unset($metadata['status']);
          $modelId = 'eca_bpmn_io_' . $id;
          if ($source->exists('modeler_api.data_model.' . $modelId)) {
            throw new \UnexpectedValueException('Both legacy and unreferenced Modeler API data exist. Export a coherent ECA 3 pair first.');
          }
          $metadata['modeler_id'] = 'bpmn_io';
          [$storage, $override] = $this->legacyStorageMethod($source, $name);
          if ($override !== '') {
            $metadata['storage'] = $override;
          }
          if ($storage === 'third-party') {
            $metadata['data'] = $xml;
          }
          else {
            $writes['modeler_api.data_model.' . $modelId] = $this->completeDataModel([
              'id' => $modelId,
              'data' => $xml,
            ], $modelId, $legacy);
            $metadata['data'] = 'hash:' . md5($xml);
          }
        }
        else {
          // An explicit BPMN model without its diagram is incomplete. Do not
          // silently downgrade it to the fallback editor and delete the data.
          if (($eca['modeller'] ?? $eca['model'] ?? '') === 'bpmn_io') {
            throw new \UnexpectedValueException('The legacy BPMN diagram is missing or empty.');
          }
          $metadata = [
            'modeler_id' => 'fallback',
            'label' => $eca['label'] ?? $id,
          ];
        }
        foreach ($eca['events'] ?? [] as $eventId => $event) {
          if (str_starts_with($event['plugin'] ?? '', 'form:')
            && isset($event['configuration']['form_id'])) {
            if (isset($event['configuration']['form_ids'])
              && $event['configuration']['form_ids'] !== $event['configuration']['form_id']) {
              throw new \UnexpectedValueException('Conflicting form_id and form_ids configuration.');
            }
            $eca['events'][$eventId]['configuration']['form_ids'] = $event['configuration']['form_id'];
            unset($eca['events'][$eventId]['configuration']['form_id']);
          }
        }
        // ModelOwnerBase omits empty third-party values. Keep that exact shape
        // so a subsequent entity save/export cannot produce recurring diffs.
        $eca['third_party_settings']['modeler_api'] = array_filter($metadata);
        foreach (['label', 'model', 'modeller', 'version', 'documentation', 'tags'] as $key) {
          unset($eca[$key]);
        }
        $eca['template'] ??= NULL;
        $eca = $this->updateDependencies($eca);
        $writes[$name] = self::orderEca($eca);
      }
      catch (\Throwable $exception) {
        throw new StorageTransformerException(sprintf(
          'Cannot safely import %s into ECA 3: %s Correct the source and export a coherent ECA 3 model before retrying.',
          $name, $exception->getMessage(),
        ), 0, $exception);
      }
    }
    // Validate everything before changing even the temporary source storage.
    foreach ($writes as $name => $data) {
      $source->write($name, $data);
    }
    // Orphaned legacy diagrams do not resurrect intentionally deleted ECAs.
    foreach ($source->listAll('eca.model.') as $name) {
      $source->delete($name);
    }
  }

  /**
   * Resolves legacy storage against the policy that the import will install.
   */
  private function legacyStorageMethod(StorageInterface $source, string $name): array {
    $active = $this->identityStorage?->read($name) ?: [];
    $override = $active['third_party_settings']['modeler_api']['storage'] ?? '';
    $settings = $source->read('modeler_api.settings');
    if ($settings === FALSE) {
      $settings = $this->identityStorage?->read('modeler_api.settings') ?: [];
    }
    $storage = $override !== ''
      ? $override
      : ($settings['owner_modeler']['eca']['bpmn_io']['storage'] ?? 'separate');
    if (!in_array($storage, ['separate', 'third-party'], TRUE)) {
      throw new \UnexpectedValueException('Legacy BPMN conversion requires separate or third-party storage; refusing to discard its diagram.');
    }
    return [$storage, $override];
  }

  /**
   * Adds inherited ConfigEntityBase export metadata with stable identity.
   */
  private function completeDataModel(array $model, string $id, array $legacy = []): array {
    $active = $this->identityStorage?->read('modeler_api.data_model.' . $id) ?: [];
    // RFC 4122 UUIDv5, using the standard URL namespace and a stable model URI.
    $hash = sha1(hex2bin('6ba7b8119dad11d180b400c04fd430c8') . 'urn:markaspot:eca-data-model:' . $id);
    $uuid = substr($hash, 0, 8) . '-' . substr($hash, 8, 4) . '-5' . substr($hash, 13, 3)
      . '-' . dechex((hexdec($hash[16]) & 3) | 8) . substr($hash, 17, 3) . '-' . substr($hash, 20, 12);
    $model += [
      'uuid' => $active['uuid'] ?? $legacy['uuid'] ?? $uuid,
      'langcode' => $active['langcode'] ?? $legacy['langcode'] ?? 'en',
      'status' => TRUE,
      'dependencies' => [],
    ];
    return self::orderKeys($model, [
      'uuid', 'langcode', 'status', 'dependencies', 'third_party_settings',
      '_core', 'id', 'data',
    ]);
  }

  /**
   * Matches ECA/Modeler API schema order used by config entity saves.
   *
   * StorageComparer compares arrays strictly, including associative key order.
   */
  private static function orderEca(array $eca): array {
    if (isset($eca['third_party_settings']['modeler_api'])) {
      $eca['third_party_settings']['modeler_api'] = self::orderKeys(
        $eca['third_party_settings']['modeler_api'],
        [
          'modeler_id', 'storage', 'data', 'changelog', 'label', 'documentation',
          'tags', 'version', 'annotations', 'colors', 'swimlanes',
        ],
      );
    }
    return self::orderKeys($eca, [
      'uuid', 'langcode', 'status', 'dependencies', 'third_party_settings',
      '_core', 'id', 'weight', 'template', 'events', 'conditions', 'gateways',
      'actions',
    ]);
  }

  /**
   * Reorders known schema keys while preserving every supplied value.
   */
  private static function orderKeys(array $data, array $keys): array {
    return array_replace(array_intersect_key(array_fill_keys($keys, NULL), $data), $data);
  }

  /**
   * Adds migration dependencies while retaining the source dependency graph.
   *
   * The transform precedes installation of newly imported modules. Computing
   * dependencies by instantiating plugins would reject valid source plugins
   * that are not installed yet, and field inference would see the old schema.
   */
  public function updateDependencies(array $eca): array {
    if ($this->calculateDependencies !== NULL) {
      $eca['dependencies'] = ($this->calculateDependencies)($eca);
      return $eca;
    }
    $dependencies = $eca['dependencies'] ?? [];
    $modules = $dependencies['module'] ?? [];
    if (isset($eca['third_party_settings']['modeler_api'])) {
      $modules[] = 'modeler_api';
    }
    foreach ($eca['actions'] ?? [] as $action) {
      if (($action['plugin'] ?? NULL) === 'markaspot_mail_send_notification') {
        $modules[] = 'markaspot_mail';
      }
    }
    if ($modules !== []) {
      $modules = array_values(array_unique($modules));
      sort($modules);
      $dependencies['module'] = $modules;
    }
    $eca['dependencies'] = $dependencies;
    return $eca;
  }

  /**
   * Checks an explicit new-format source without replacing it with active data.
   */
  private function validateModelerData(StorageInterface $source, array $eca, string $id): void {
    $metadata = $eca['third_party_settings']['modeler_api'];
    $modeler = $metadata['modeler_id'] ?? NULL;
    if (!is_string($modeler) || $modeler === '') {
      throw new \UnexpectedValueException('Incomplete Modeler API metadata.');
    }
    $data = $metadata['data'] ?? NULL;
    if (is_string($data) && str_starts_with($data, 'hash:')) {
      $model = $source->read('modeler_api.data_model.eca_' . $modeler . '_' . $id);
      if (!is_array($model) || !is_string($model['data'] ?? NULL)
        || 'hash:' . md5($model['data']) !== $data) {
        throw new \UnexpectedValueException('Modeler API diagram is missing or its hash does not match.');
      }
    }
    elseif ($modeler !== 'fallback' && (!is_string($data) || $data === '')) {
      throw new \UnexpectedValueException('Modeler API data is missing.');
    }
  }

  /**
   * Reads metadata with the same parser used by ECA's migration, without saves.
   */
  private function parseBpmnMetadata(string $xml): array {
    // Optional contrib services must be resolved lazily: ECA is not required
    // by the profile, and this helper also supports offline bootstrapped use.
    // @phpstan-ignore globalDrupalDependencyInjection.useDependencyInjection
    $container = \Drupal::getContainer();
    foreach (['model_owner', 'modeler'] as $type) {
      if (!$container->has('plugin.manager.modeler_api.' . $type)) {
        throw new \UnexpectedValueException('The ECA Modeler API services are unavailable.');
      }
    }
    $owners = $container->get('plugin.manager.modeler_api.model_owner');
    $modelers = $container->get('plugin.manager.modeler_api.modeler');
    if (!$owners->hasDefinition('eca') || !$modelers->hasDefinition('bpmn_io')) {
      throw new \UnexpectedValueException('Enable the ECA UI and BPMN modeler before importing legacy diagrams.');
    }
    $modeler = $modelers->createInstance('bpmn_io');
    $modeler->parseData($owners->createInstance('eca'), $xml);
    return [
      'status' => $modeler->getStatus(),
      'label' => $modeler->getLabel(),
      'changelog' => $modeler->getChangelog(),
      'documentation' => $modeler->getDocumentation(),
      'tags' => $modeler->getTags(),
      'version' => $modeler->getVersion(),
    ];
  }

  /**
   * Validates BPMN and renames only form-event configuration fields.
   */
  public static function normalizeFormFields(string $xml): string {
    $previous = libxml_use_internal_errors(TRUE);
    try {
      $document = new \DOMDocument();
      if (!$document->loadXML($xml, LIBXML_NONET) || $document->doctype !== NULL) {
        throw new \UnexpectedValueException('Malformed BPMN XML or prohibited document type.');
      }
      $xpath = new \DOMXPath($document);
      $xpath->registerNamespace('bpmn', 'http://www.omg.org/spec/BPMN/20100524/MODEL');
      $xpath->registerNamespace('camunda', 'http://camunda.org/schema/1.0/bpmn');
      if ($xpath->query('/bpmn:definitions/bpmn:process')->length < 1) {
        throw new \UnexpectedValueException('Expected at least one BPMN process.');
      }
      $changed = FALSE;
      foreach ($xpath->query('//bpmn:extensionElements[camunda:properties/camunda:property[@name="pluginid" and starts-with(@value,"form:")]]/camunda:field[@name="form_id"]') as $field) {
        $field->setAttribute('name', 'form_ids');
        $changed = TRUE;
      }
      return $changed ? $document->saveXML() : $xml;
    }
    finally {
      libxml_clear_errors();
      libxml_use_internal_errors($previous);
    }
  }

}
