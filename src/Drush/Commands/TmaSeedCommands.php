<?php

namespace Drupal\ucb_tma_interface\Drush\Commands;

use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;

final class TmaSeedCommands extends DrushCommands {

  #[CLI\Command(name: 'tma:seed-fixit', aliases: ['tma-seed-fixit'])]
  #[CLI\Usage(name: 'tma:seed-fixit', description: 'Create feeds + import location terms + import tasks dataset')]
  #[CLI\Option(name: 'skip-feeds', description: 'Skip creating/importing feeds')]
  #[CLI\Option(name: 'skip-tasks', description: 'Skip importing tasks')]
  #[CLI\Option(name: 'tasks-file', description: 'Path to tasks YAML file (defaults to module dataset)')]
  #[CLI\Option(name: 'tasks-verbose', description: 'Log each task create/update + taxonomy linking result')]
  public function seedFixit(array $options = [
    'skip-feeds' => FALSE,
    'skip-tasks' => FALSE,
    'tasks-file' => NULL,
    'tasks-verbose' => FALSE,
  ]): void {
    /** @var \Drupal\ucb_tma_interface\Service\FixitSeeder $fixitSeeder */
    $fixitSeeder = \Drupal::service('ucb_tma_interface.fixit_seeder');

    $skipFeeds = (bool) ($options['skip-feeds'] ?? FALSE);
    $skipTasks = (bool) ($options['skip-tasks'] ?? FALSE);
    $tasksFile = $options['tasks-file'] ?? NULL;
    $tasksVerbose = (bool) ($options['tasks-verbose'] ?? FALSE);

    if (!$skipFeeds) {
      $created = $fixitSeeder->ensureFeedsExist();
      $this->logger()->notice("Feeds created: {$created}");

      $result = $fixitSeeder->importAllFeeds();
      $this->logger()->notice("Feeds imported: {$result['imported']}, failed: {$result['failed']}");
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

