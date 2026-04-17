<?php

namespace Drupal\ucb_tma_interface\Drush\Commands;

use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;
use GuzzleHttp\Exception\GuzzleException;

final class TmaSeedCommands extends DrushCommands {

  #[CLI\Command(name: 'tma:debug-feeds', aliases: ['tma-debug-feeds'])]
  #[CLI\Usage(name: 'tma:debug-feeds', description: 'Show resolved Feeds base URL and HTTP GET /tma/location/facility (for Drush/DDEV debugging)')]
  public function debugFeeds(): void {
    /** @var \Drupal\ucb_tma_interface\Service\FixitSeeder $fixitSeeder */
    $fixitSeeder = \Drupal::service('ucb_tma_interface.fixit_seeder');
    $base = $fixitSeeder->resolveFeedsBaseUrl();
    $this->logger()->notice("Resolved Feeds base URL: {$base}");
    $ddev = getenv('DDEV_PRIMARY_URL');
    if (is_string($ddev) && $ddev !== '') {
      $this->logger()->notice("DDEV_PRIMARY_URL in environment: {$ddev}");
    }
    $explicit = \Drupal::config('ucb_tma_interface.settings')->get('feeds_base_url');
    $this->logger()->notice('Config feeds_base_url: ' . ($explicit !== NULL && $explicit !== '' ? (string) $explicit : '(empty — auto)'));

    $testUrl = $base . '/tma/location/facility';
    $this->logger()->notice("GET {$testUrl}");
    try {
      $resp = \Drupal::httpClient()->get($testUrl, [
        'http_errors' => FALSE,
        'timeout' => 30,
        'headers' => ['Accept' => 'application/json'],
      ]);
      $code = $resp->getStatusCode();
      $body = (string) $resp->getBody();
      $this->logger()->notice("HTTP status: {$code}, body length: " . strlen($body));
      $decoded = json_decode($body, TRUE);
      if (!is_array($decoded)) {
        $this->logger()->warning('Body is not JSON. First 400 chars: ' . substr($body, 0, 400));
        return;
      }
      if (array_is_list($decoded)) {
        $this->logger()->notice('JSON: top-level array with ' . count($decoded) . ' item(s).');
        if (isset($decoded[0]) && is_array($decoded[0])) {
          $this->logger()->notice('First row keys: ' . implode(', ', array_keys($decoded[0])));
        }
      }
      else {
        $this->logger()->notice('JSON: object with keys: ' . implode(', ', array_keys($decoded)));
      }
    }
    catch (GuzzleException $e) {
      $this->logger()->error('Request failed: ' . $e->getMessage());
    }
    catch (\Throwable $e) {
      $this->logger()->error('Error: ' . $e->getMessage());
    }
  }

  #[CLI\Command(name: 'tma:seed-fixit', aliases: ['tma-seed-fixit'])]
  #[CLI\Usage(name: 'tma:seed-fixit', description: 'Import location taxonomy (Platform API by default) + tasks YAML')]
  #[CLI\Option(name: 'skip-feeds', description: 'Skip importing location taxonomy (facility/building/area)')]
  #[CLI\Option(name: 'use-feeds', description: 'Use Feeds HTTP imports from /tma/location/* (set feeds_base_url or DDEV_PRIMARY_URL so sources are reachable from Drush)')]
  #[CLI\Option(name: 'skip-tasks', description: 'Skip importing tasks')]
  #[CLI\Option(name: 'tasks-file', description: 'Path to tasks YAML file (defaults to module dataset)')]
  #[CLI\Option(name: 'tasks-verbose', description: 'Log each task create/update + taxonomy linking result')]
  public function seedFixit(array $options = [
    'skip-feeds' => FALSE,
    'use-feeds' => FALSE,
    'skip-tasks' => FALSE,
    'tasks-file' => NULL,
    'tasks-verbose' => FALSE,
  ]): void {
    /** @var \Drupal\ucb_tma_interface\Service\FixitSeeder $fixitSeeder */
    $fixitSeeder = \Drupal::service('ucb_tma_interface.fixit_seeder');

    $skipFeeds = (bool) ($options['skip-feeds'] ?? FALSE);
    $useFeeds = (bool) ($options['use-feeds'] ?? FALSE);
    $skipTasks = (bool) ($options['skip-tasks'] ?? FALSE);
    $tasksFile = $options['tasks-file'] ?? NULL;
    $tasksVerbose = (bool) ($options['tasks-verbose'] ?? FALSE);

    if (!$skipFeeds) {
      if ($useFeeds) {
        $created = $fixitSeeder->ensureFeedsExist();
        $this->logger()->notice("Feeds created: {$created}");

        $result = $fixitSeeder->importAllFeeds();
        $this->logger()->notice("Feeds imported: {$result['imported']}, failed: {$result['failed']}");
      }
      else {
        $this->logger()->notice('Importing location taxonomy via Platform API (JWT). Watch for TMA seed: … lines per page; this can take several minutes.');
        $result = $fixitSeeder->importLocationTaxonomyFromPlatform();
        $this->logger()->notice(sprintf(
          'Platform location taxonomy: facilities +%d ~%d, buildings +%d ~%d, areas +%d ~%d, skipped_rows=%d, http_errors=%d',
          $result['facility_created'],
          $result['facility_updated'],
          $result['building_created'],
          $result['building_updated'],
          $result['area_created'],
          $result['area_updated'],
          $result['skipped_rows'],
          $result['http_errors']
        ));
      }
    }

    if (!$skipTasks) {
      if (!is_string($tasksFile) || trim($tasksFile) === '') {
        try {
          /** @var \Drupal\Core\Extension\ModuleExtensionList $extensionList */
          $extensionList = \Drupal::service('extension.list.module');
          $modulePath = $extensionList->getPath('ucb_tma_interface');
          $tasksFile = DRUPAL_ROOT . '/' . $modulePath . '/data/fixit_tasks.yml';
        }
        catch (\Throwable) {
          // Fallback for unusual Drupal roots; keeps older behavior.
          $tasksFile = DRUPAL_ROOT . '/modules/custom/tma_interface/data/fixit_tasks.yml';
        }
      }
      $tasksFile = (string) $tasksFile;
      $result = $fixitSeeder->importTasksFromYaml($tasksFile, $tasksVerbose);
      $linkFailed = (int) ($result['link_failed'] ?? 0);
      $this->logger()->notice("Tasks imported from {$tasksFile}: created={$result['created']} updated={$result['updated']} skipped={$result['skipped']} link_failed={$linkFailed}");
    }
  }

}

