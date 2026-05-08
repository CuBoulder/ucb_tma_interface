<?php

namespace Drupal\ucb_tma_interface\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\taxonomy\Entity\Term;
use Symfony\Component\Yaml\Yaml;

final class FixitSeeder {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly TmaLocationFeedPayloadBuilder $locationFeedPayload,
  ) {}

  /**
   * Import facility, building, and area taxonomy directly from the Platform API (v7).
   *
   * This uses the same legacy-shaped JSON builder as /tma/location/*.
   *
   * @return array<string, int>
   */
  public function importLocationTaxonomyFromPlatform(): array {
    if (\function_exists('set_time_limit')) {
      @set_time_limit(0);
    }

    $logger = \Drupal::logger('ucb_tma_interface');
    $out = [
      'facility_created' => 0,
      'facility_updated' => 0,
      'building_created' => 0,
      'building_updated' => 0,
      'area_created' => 0,
      'area_updated' => 0,
      'skipped_rows' => 0,
      'http_errors' => 0,
    ];

    $logger->notice('TMA seed: direct import uses the same legacy JSON as /tma/location/* (shared builder).');

    $termStorage = $this->entityTypeManager->getStorage('taxonomy_term');
    $b = $this->locationFeedPayload;

    $facilityItems = $b->getFeedItems('Facility', NULL);
    if ($facilityItems === []) {
      $out['http_errors']++;
      $logger->error('Platform location import: no facility rows (check base_url and credentials).');
      return $out;
    }

    $facilityMap = $this->buildTmaTermIdMap('facility', 'field_tma_facility_id');
    foreach ($facilityItems as $item) {
      if (!is_array($item) || !isset($item['pk']) || !is_numeric($item['pk'])) {
        $out['skipped_rows']++;
        continue;
      }
      $tmaId = (int) $item['pk'];
      $label = $this->truncateTermName(trim((string) ($item['name'] ?? '')) !== '' ? trim((string) $item['name']) : ('Facility ' . $tmaId));
      if (isset($facilityMap[$tmaId])) {
        /** @var \Drupal\taxonomy\TermInterface $term */
        $term = $termStorage->load($facilityMap[$tmaId]);
        if ($term) {
          $term->setName($label);
          $term->set('field_tma_facility_id', $tmaId);
          $term->save();
          $out['facility_updated']++;
        }
      }
      else {
        $term = Term::create([
          'vid' => 'facility',
          'name' => $label,
          'status' => 1,
          'field_tma_facility_id' => $tmaId,
        ]);
        $term->save();
        $facilityMap[$tmaId] = (int) $term->id();
        $out['facility_created']++;
      }
    }

    $buildingItems = $b->getFeedItems('Building', NULL);
    if ($buildingItems === []) {
      $out['http_errors']++;
      $logger->error('Platform location import: no building rows.');
      return $out;
    }

    $buildingMap = $this->buildTmaTermIdMap('building', 'field_tma_building_id_');
    foreach ($buildingItems as $item) {
      if (!is_array($item) || !isset($item['pk'], $item['connector']) || !is_numeric($item['pk']) || !is_numeric($item['connector'])) {
        $out['skipped_rows']++;
        continue;
      }
      $tmaId = (int) $item['pk'];
      $facilityId = (int) $item['connector'];
      $label = $this->truncateTermName(trim((string) ($item['name'] ?? '')) !== '' ? trim((string) $item['name']) : ('Building ' . $tmaId));
      if (isset($buildingMap[$tmaId])) {
        /** @var \Drupal\taxonomy\TermInterface $term */
        $term = $termStorage->load($buildingMap[$tmaId]);
        if ($term) {
          $term->setName($label);
          $term->set('field_tma_facility_id', $facilityId);
          $term->set('field_tma_building_id_', $tmaId);
          $term->save();
          $out['building_updated']++;
        }
      }
      else {
        $term = Term::create([
          'vid' => 'building',
          'name' => $label,
          'status' => 1,
          'field_tma_facility_id' => $facilityId,
          'field_tma_building_id_' => $tmaId,
        ]);
        $term->save();
        $buildingMap[$tmaId] = (int) $term->id();
        $out['building_created']++;
      }
    }

    // Same area set as the union of the seven campus Feeds (FacilityName must match FEEDS_AREA_CAMPUS_NAMES).
    $areaItems = $b->getFeedItems('Area', NULL);
    if ($areaItems === []) {
      $logger->warning('Platform location import: no area rows after campus filter (check API + FacilityName).');
    }

    $areaMap = $this->buildTmaTermIdMap('area', 'field_tma_area_id');
    foreach ($areaItems as $item) {
      if (!is_array($item) || !isset($item['pk'], $item['connector']) || !is_numeric($item['pk']) || !is_numeric($item['connector'])) {
        $out['skipped_rows']++;
        continue;
      }
      $tmaId = (int) $item['pk'];
      $buildingId = (int) $item['connector'];
      $label = $this->truncateTermName(trim((string) ($item['name'] ?? '')) !== '' ? trim((string) $item['name']) : ('Area ' . $tmaId));
      $floor = $this->truncateString((string) ($item['floor_code'] ?? ''), 255);
      $descRaw = trim((string) ($item['description'] ?? ''));
      $description = $descRaw !== '' ? $this->truncateString($descRaw, 255) : '';

      if (isset($areaMap[$tmaId])) {
        /** @var \Drupal\taxonomy\TermInterface $term */
        $term = $termStorage->load($areaMap[$tmaId]);
        if ($term) {
          $term->setName($label);
          $term->set('field_tma_building_id_', $buildingId);
          $term->set('field_tma_area_id', $tmaId);
          $term->set('field_floor', $floor);
          $term->set('field_tma_description', $description);
          $term->save();
          $out['area_updated']++;
        }
      }
      else {
        $term = Term::create([
          'vid' => 'area',
          'name' => $label,
          'status' => 1,
          'field_tma_building_id_' => $buildingId,
          'field_tma_area_id' => $tmaId,
          'field_floor' => $floor,
          'field_tma_description' => $description,
        ]);
        $term->save();
        $areaMap[$tmaId] = (int) $term->id();
        $out['area_created']++;
      }
    }

    $logger->notice(
      'Platform location taxonomy import finished: facilities +@fc ~@fu, buildings +@bc ~@bu, areas +@ac ~@au, skipped=@sk',
      [
        '@fc' => $out['facility_created'],
        '@fu' => $out['facility_updated'],
        '@bc' => $out['building_created'],
        '@bu' => $out['building_updated'],
        '@ac' => $out['area_created'],
        '@au' => $out['area_updated'],
        '@sk' => $out['skipped_rows'],
      ]
    );

    return $out;
  }

  /**
   * @return array<int, int>
   */
  private function buildTmaTermIdMap(string $vid, string $fieldName): array {
    $storage = $this->entityTypeManager->getStorage('taxonomy_term');
    $tids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('vid', $vid)
      ->execute();
    $map = [];
    foreach (array_chunk(array_values($tids), 400) as $chunk) {
      foreach ($storage->loadMultiple($chunk) as $term) {
        if (!$term->hasField($fieldName) || $term->get($fieldName)->isEmpty()) {
          continue;
        }
        $map[(int) $term->get($fieldName)->value] = (int) $term->id();
      }
    }
    return $map;
  }

  private function truncateString(string $s, int $max): string {
    if (strlen($s) <= $max) {
      return $s;
    }
    return substr($s, 0, $max);
  }

  private function truncateTermName(string $name): string {
    return $this->truncateString($name, 255);
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

    $findExistingTask = static function (string $title, string $parentName, int $parentTid, string $parentVid) use ($nodeStorage): ?int {
      // Find by title, then disambiguate by parent term *name* so we
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

      // No safe match. Create a new node instead of reusing an arbitrary one, because titles can legitimately repeat across different parent tiles
      // Happens when same label in both categories and services!
      return NULL;
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

      $existingNid = $findExistingTask($title, $parentName, $parentTid, $parentVid);

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

