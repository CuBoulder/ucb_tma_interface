<?php

namespace Drupal\ucb_tma_interface\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\taxonomy\Entity\Term;
use Symfony\Component\Yaml\Yaml;

final class FixitSeeder {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ModuleHandlerInterface $moduleHandler,
  ) {}

  /**
   * Ensure the standard Fixit Feeds feed instances exist.
   */
  public function ensureFeedsExist(): int {
    if (!$this->moduleHandler->moduleExists('feeds')) {
      throw new \RuntimeException('feeds module is not enabled.');
    }

    $feeds = [
      [
        'type' => 'tma_facility_import',
        'title' => 'TMA Facility',
        'source' => 'https://fixit.colorado.edu/tma/location/facility',
      ],
      [
        'type' => 'tma_building_import',
        'title' => 'TMA Building',
        'source' => 'https://fixit.colorado.edu/tma/location/building',
      ],
    ];

    $campuses = [
      'Central Campus',
      'Williams Village',
      'Graduate and Family Housing',
      'Grounds',
      'East Campus',
      'Kittredge Loop',
      'Bear Creek',
    ];
    foreach ($campuses as $campus) {
      $feeds[] = [
        'type' => 'tma_area_import',
        'title' => 'TMA Areas - ' . $campus,
        'source' => 'https://fixit.colorado.edu/tma/location/area/' . rawurlencode($campus),
      ];
    }

    $storage = $this->entityTypeManager->getStorage('feeds_feed');
    $created = 0;

    foreach ($feeds as $f) {
      $existing = $storage->loadByProperties(['type' => $f['type'], 'source' => $f['source']]);
      if ($existing) {
        continue;
      }
      $e = $storage->create([
        'type' => $f['type'],
        'title' => $f['title'],
        'source' => $f['source'],
        'status' => 1,
        'uid' => 1,
      ]);
      $e->save();
      $created++;
    }

    return $created;
  }

  /**
   * Import all feeds (facility/building/area).
   *
   * @return array{imported:int, failed:int}
   */
  public function importAllFeeds(): array {
    if (!$this->moduleHandler->moduleExists('feeds')) {
      throw new \RuntimeException('feeds module is not enabled.');
    }

    $storage = $this->entityTypeManager->getStorage('feeds_feed');
    $feeds = $storage->loadMultiple();

    $imported = 0;
    $failed = 0;

    foreach ($feeds as $feed) {
      // Import is defined on Feeds feed entity.
      try {
        $feed->import();
        $imported++;
      }
      catch (\Throwable) {
        $failed++;
      }
    }

    return ['imported' => $imported, 'failed' => $failed];
  }

  /**
   * Import tasks (and create missing parent terms) from a YAML dataset.
   *
   * @return array{created:int, updated:int, skipped:int, link_failed:int}
   */
  public function importTasksFromYaml(string $yamlPath, bool $verbose = FALSE): array {
    if (!is_file($yamlPath) || !is_readable($yamlPath)) {
      throw new \RuntimeException("YAML not readable: {$yamlPath}");
    }

    $rows = Yaml::parseFile($yamlPath);
    if (!is_array($rows)) {
      throw new \RuntimeException("Invalid YAML structure: {$yamlPath}");
    }

    $termStorage = $this->entityTypeManager->getStorage('taxonomy_term');
    $nodeStorage = $this->entityTypeManager->getStorage('node');

    $logger = \Drupal::logger('ucb_tma_interface');

    $log = static function (string $level, string $message, array $context = []) use ($logger): void {
      // Log to Drupal. When running via Drush, Drupal log messages are typically
      // surfaced in the CLI output, so avoid double-logging here.
      try {
        $logger->log($level, $message, $context);
      }
      catch (\Throwable) {
        // Don't break seeding due to logging failures.
      }
    };

    // Ensure a catch-all Services term exists (even if no tasks reference it).
    try {
      $existingOther = $termStorage->loadByProperties(['vid' => 'services', 'name' => 'Other']);
      if (!$existingOther) {
        $t = Term::create([
          'vid' => 'services',
          'name' => 'Other',
          'status' => 1,
        ]);
        $t->save();
        if ($verbose) {
          $log('notice', 'Created services term "Other" (tid=@tid).', ['@tid' => (int) $t->id()]);
        }
      }
    }
    catch (\Throwable $e) {
      $log('warning', 'Unable to ensure services term "Other": @err', ['@err' => $e->getMessage()]);
    }

    $allowedParentVids = [];
    try {
      $probe = $nodeStorage->create(['type' => 'task']);
      if ($probe->hasField('field_parent_tile')) {
        $handlerSettings = $probe->getFieldDefinition('field_parent_tile')->getSetting('handler_settings');
        $targetBundles = is_array($handlerSettings) ? ($handlerSettings['target_bundles'] ?? NULL) : NULL;
        if (is_array($targetBundles) && $targetBundles) {
          $allowedParentVids = array_values(array_map('strval', array_keys($targetBundles)));
        }
      }
    }
    catch (\Throwable) {
      $allowedParentVids = [];
    }

    $ensureParentTerm = static function (string $name, ?string $parentVid) use ($termStorage, $allowedParentVids): Term {
      $name = trim($name);
      if ($name === '') {
        throw new \RuntimeException('Empty parent_tile (parent term name).');
      }

      $parentVid = is_string($parentVid) ? trim($parentVid) : '';
      if ($parentVid === '') {
        // Default behavior: Issue Types live in categories.
        $preferredVid = 'categories';
      }
      else {
        $preferredVid = $parentVid;
      }

      // If the field restricts bundles, enforce them.
      if ($allowedParentVids && !in_array($preferredVid, $allowedParentVids, TRUE)) {
        throw new \RuntimeException("parent_vid '{$preferredVid}' is not allowed by field_parent_tile. Allowed: " . implode(', ', $allowedParentVids));
      }

      $existing = $termStorage->loadByProperties(['vid' => $preferredVid, 'name' => $name]);
      if ($existing) {
        /** @var \Drupal\taxonomy\Entity\Term $term */
        $term = reset($existing);
        return $term;
      }

      $createVid = $preferredVid;
      $term = Term::create([
        'vid' => $createVid,
        'name' => $name,
        'status' => 1,
      ]);
      $term->save();
      return $term;
    };

    $findExistingTask = static function (string $title, string $parentName, int $parentTid, string $parentVid, ?string $taskCode) use ($nodeStorage): ?int {
      $taskCode = trim((string) $taskCode);

      if ($taskCode !== '') {
        $nids = \Drupal::entityQuery('node')
          ->accessCheck(FALSE)
          ->condition('type', 'task')
          ->condition('field_task_code', $taskCode)
          ->range(0, 1)
          ->execute();
        if ($nids) {
          return (int) reset($nids);
        }
      }

      // Fallback: find by title, then disambiguate by parent term *name* so we
      // can safely migrate between vocabularies without creating duplicates.
      $nids = \Drupal::entityQuery('node')
        ->accessCheck(FALSE)
        ->condition('type', 'task')
        ->condition('title', $title)
        ->range(0, 20)
        ->execute();
      if (!$nids) {
        return NULL;
      }

      $parentName = trim($parentName);
      $parentVid = trim($parentVid);
      /** @var \Drupal\node\NodeInterface[] $candidates */
      $candidates = $nodeStorage->loadMultiple($nids);
      foreach ($candidates as $candidate) {
        try {
          if (!$candidate->hasField('field_parent_tile') || $candidate->get('field_parent_tile')->isEmpty()) {
            continue;
          }
          /** @var \Drupal\taxonomy\TermInterface|null $t */
          $t = $candidate->get('field_parent_tile')->entity;
          if ($t && trim((string) $t->label()) === $parentName && (string) $t->bundle() === $parentVid) {
            return (int) $candidate->id();
          }
        }
        catch (\Throwable) {
          // ignore
        }
      }

      // As a last resort, match by the current parent tid.
      foreach ($candidates as $candidate) {
        try {
          if ($candidate->hasField('field_parent_tile') && !$candidate->get('field_parent_tile')->isEmpty()) {
            if ((int) $candidate->get('field_parent_tile')->target_id === $parentTid) {
              return (int) $candidate->id();
            }
          }
        }
        catch (\Throwable) {
          // ignore
        }
      }

      return (int) reset($nids);
    };

    $created = 0;
    $updated = 0;
    $skipped = 0;
    $linkFailed = 0;

    foreach ($rows as $row) {
      if (!is_array($row)) {
        $skipped++;
        continue;
      }

      $title = trim((string) ($row['title'] ?? ''));
      if ($title === '') {
        $skipped++;
        continue;
      }

      $parentName = (string) ($row['parent_tile'] ?? '');
      $parentVidHint = $row['parent_vid'] ?? NULL;
      $parentVid = is_string($parentVidHint) ? trim($parentVidHint) : '';
      if ($parentVid === '') {
        $parentVid = 'categories';
      }
      $taskCode = $row['task_code'] ?? null;
      $exception = (bool) ($row['exception'] ?? false);
      $repairCenter = (bool) ($row['repair_center'] ?? false);
      $exceptionText = $row['exception_text'] ?? null;

      try {
        $term = $ensureParentTerm($parentName, $parentVid);
      }
      catch (\Throwable $e) {
        $skipped++;
        $log('warning', 'Skipping task row: unable to resolve parent term for "@title" (parent_tile="@parent", parent_vid="@vid"). @err', [
          '@title' => $title,
          '@parent' => trim((string) $parentName),
          '@vid' => $parentVid,
          '@err' => $e->getMessage(),
        ]);
        continue;
      }

      $parentTid = (int) $term->id();
      $parentVid = (string) $term->bundle();

      $existingNid = $findExistingTask($title, $parentName, $parentTid, $parentVid, is_string($taskCode) ? $taskCode : '');

      if ($existingNid) {
        $node = $nodeStorage->load($existingNid);
        if (!$node) {
          $skipped++;
          continue;
        }
        $isNew = FALSE;
      }
      else {
        $node = $nodeStorage->create([
          'type' => 'task',
          'uid' => 1,
        ]);
        $isNew = TRUE;
      }

      $node->setTitle($title);
      $node->setPublished(TRUE);

      if ($node->hasField('field_parent_tile')) {
        try {
          $node->set('field_parent_tile', ['target_id' => $parentTid]);
        }
        catch (\Throwable $e) {
          $linkFailed++;
          $log('warning', 'Failed setting parent term link for task "@title" (parent="@parent", tid=@tid, vid="@vid"). @err', [
            '@title' => $title,
            '@parent' => trim((string) $parentName),
            '@tid' => $parentTid,
            '@vid' => $parentVid,
            '@err' => $e->getMessage(),
          ]);
        }
      }
      else {
        $linkFailed++;
        $log('warning', 'Task content type missing field_parent_tile; cannot link "@title" to parent term "@parent" (tid=@tid).', [
          '@title' => $title,
          '@parent' => trim((string) $parentName),
          '@tid' => $parentTid,
        ]);
      }
      if ($node->hasField('field_task_code')) {
        $node->set('field_task_code', is_string($taskCode) ? trim($taskCode) : '');
      }
      if ($node->hasField('field_exception')) {
        $node->set('field_exception', $exception ? 1 : 0);
      }
      if ($node->hasField('field_exception_text')) {
        $text = is_string($exceptionText) ? trim($exceptionText) : '';
        if ($text !== '') {
          $node->set('field_exception_text', [
            'value' => $text,
            'format' => 'basic_html',
          ]);
        }
        else {
          $node->set('field_exception_text', NULL);
        }
      }
      if ($node->hasField('field_repair_center')) {
        $node->set('field_repair_center', $repairCenter ? 1 : 0);
      }

      try {
        $node->save();
      }
      catch (\Throwable $e) {
        $skipped++;
        $log('error', 'Failed saving task "@title" (parent="@parent", tid=@tid, vid="@vid"). @err', [
          '@title' => $title,
          '@parent' => trim((string) $parentName),
          '@tid' => $parentTid,
          '@vid' => $parentVid,
          '@err' => $e->getMessage(),
        ]);
        continue;
      }

      // Verify the parent tile reference actually stuck.
      $linkedTid = NULL;
      try {
        if ($node->hasField('field_parent_tile') && !$node->get('field_parent_tile')->isEmpty()) {
          $linkedTid = (int) $node->get('field_parent_tile')->target_id;
        }
      }
      catch (\Throwable) {
        $linkedTid = NULL;
      }
      if ($linkedTid !== $parentTid) {
        $linkFailed++;
        $log('warning', 'Task "@title" saved but taxonomy link mismatch (expected tid=@expected, got tid=@got). parent="@parent" (vid="@vid") nid=@nid', [
          '@title' => $title,
          '@expected' => $parentTid,
          '@got' => $linkedTid ?? 0,
          '@parent' => trim((string) $parentName),
          '@vid' => $parentVid,
          '@nid' => (int) $node->id(),
        ]);
      }
      elseif ($verbose) {
        $log('notice', 'Task @action: "@title" nid=@nid linked parent="@parent" (tid=@tid, vid="@vid")', [
          '@action' => $isNew ? 'CREATED' : 'UPDATED',
          '@title' => $title,
          '@nid' => (int) $node->id(),
          '@parent' => trim((string) $parentName),
          '@tid' => $parentTid,
          '@vid' => $parentVid,
        ]);
      }

      if ($isNew) {
        $created++;
      }
      else {
        $updated++;
      }
    }

    if ($linkFailed > 0) {
      $log('warning', 'Task import finished with @count taxonomy link failures (see warnings above).', [
        '@count' => $linkFailed,
      ]);
    }

    return ['created' => $created, 'updated' => $updated, 'skipped' => $skipped, 'link_failed' => $linkFailed];
  }

}

