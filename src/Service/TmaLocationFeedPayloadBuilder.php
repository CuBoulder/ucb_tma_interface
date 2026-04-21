<?php

namespace Drupal\ucb_tma_interface\Service;

use Drupal\ucb_tma_interface\ApiConnector\PlatformConnector;
use Psr\Http\Message\ResponseInterface;

/**
 * Single source of truth for Platform API → legacy Feeds JSON (pk, name, connector, …).
 *
 * Used by /tma/location/* and by direct Drush seed so results match Feeds YAML mappings.
 */
final class TmaLocationFeedPayloadBuilder {

  public const PAGE_SIZE = 500;

  /**
   * Same campus names as FixitSeeder area feed URLs. Areas with other FacilityName values
   * are excluded so direct seed matches the union of the 7 Feeds area imports.
   */
  public const FEEDS_AREA_CAMPUS_NAMES = [
    'Central Campus',
    'Williams Village',
    'Graduate and Family Housing',
    'Grounds',
    'East Campus',
    'Kittredge Loop',
    'Bear Creek',
  ];

  public function __construct(
    private readonly PlatformConnector $platformConnector,
  ) {}

  /**
   * Legacy JSON rows for Feeds / Drush — identical shape to old Mobile.svc simpleArea output.
   *
   * @param string $kind
   *   "Facility", "Building", or "Area".
   * @param string|null $areaFacilityName
   *   For Areas only: if set, only rows whose FacilityName equals this string (one campus feed).
   *   If NULL for Areas, only rows whose FacilityName is in FEEDS_AREA_CAMPUS_NAMES (matches
   *   the union of the seven area Feeds, not literally every area in TMA).
   */
  public function getFeedItems(string $kind, ?string $areaFacilityName = NULL): array {
    $kind = ucfirst(strtolower($kind));
    if ($kind === 'Facility') {
      return $this->buildFacilityItems();
    }
    if ($kind === 'Building') {
      return $this->buildBuildingItems();
    }
    if ($kind === 'Area') {
      return $this->buildAreaItems($areaFacilityName);
    }
    return [];
  }

  /**
   * @return list<array<string, mixed>>
   */
  private function buildFacilityItems(): array {
    $rows = $this->fetchAllPages('/v2/Facilities', 'Id,Name,Code,Active');
    $out = [];
    foreach ($rows as $loc) {
      if (!is_array($loc)) {
        continue;
      }
      $active = $loc['Active'] ?? $loc['active'] ?? FALSE;
      if (!$active) {
        continue;
      }
      $item = $this->mapFacilityRowToLegacyJson($loc);
      if ($item !== NULL) {
        $out[] = $item;
      }
    }
    return $out;
  }

  /**
   * @return list<array<string, mixed>>
   */
  private function buildBuildingItems(): array {
    $rows = $this->fetchAllPages('/v2/Buildings', 'Id,Name,Code,FacilityId,Active');
    $out = [];
    foreach ($rows as $loc) {
      if (!is_array($loc)) {
        continue;
      }
      $active = $loc['Active'] ?? $loc['active'] ?? FALSE;
      if (!$active) {
        continue;
      }
      $item = $this->mapBuildingRowToLegacyJson($loc);
      if ($item !== NULL) {
        $out[] = $item;
      }
    }
    return $out;
  }

  /**
   * @return list<array<string, mixed>>
   */
  private function buildAreaItems(?string $areaFacilityName): array {
    $rows = $this->fetchAllPages(
      '/v2/Areas',
      'Id,BuildingId,Description,RoomNumber,FloorCode,LocationCode,FacilityName,Active'
    );
    $out = [];
    foreach ($rows as $loc) {
      if (!is_array($loc)) {
        continue;
      }
      $active = $loc['Active'] ?? $loc['active'] ?? FALSE;
      if (!$active) {
        continue;
      }
      $fname = trim((string) ($loc['FacilityName'] ?? $loc['facilityName'] ?? ''));
      if ($areaFacilityName !== NULL && $areaFacilityName !== '') {
        if ($fname !== $areaFacilityName) {
          continue;
        }
      }
      else {
        if (!in_array($fname, self::FEEDS_AREA_CAMPUS_NAMES, TRUE)) {
          continue;
        }
      }
      $out[] = $this->mapAreaRowToLegacyJson($loc);
    }
    return $out;
  }

  /**
   * @return list<array<string, mixed>>
   */
  public function fetchAllPages(string $path, string $columns): array {
    $merged = [];
    $pageIndex = 0;
    $totalExpected = NULL;
    $totalFetched = 0;
    $prevPageHash = NULL;

    while (TRUE) {
      $params = [
        'pageSize' => self::PAGE_SIZE,
        'pageIndex' => $pageIndex,
        'columns' => $columns,
      ];
      $rel = $path . '?' . http_build_query($params);
      $resp = $this->platformConnector->get($rel);
      if (is_array($resp) || !$resp instanceof ResponseInterface || $resp->getStatusCode() !== 200) {
        return [];
      }

      $parsed = $this->decodePlatformListResponse($resp);
      $rows = $parsed['rows'];
      if ($pageIndex === 0 && $parsed['total_count'] !== NULL) {
        $totalExpected = $parsed['total_count'];
      }

      $n = count($rows);
      if ($n === 0) {
        break;
      }

      $pageHash = md5((string) json_encode($rows));
      if ($prevPageHash !== NULL && $pageHash === $prevPageHash) {
        break;
      }
      $prevPageHash = $pageHash;

      foreach ($rows as $r) {
        $merged[] = $r;
      }
      $totalFetched += $n;

      if ($n < self::PAGE_SIZE) {
        break;
      }
      if ($totalExpected !== NULL && $totalFetched >= $totalExpected) {
        break;
      }
      $pageIndex++;
      if ($pageIndex > 500) {
        break;
      }
    }

    return $merged;
  }

  /**
   * @return array{rows: list<array<string, mixed>>, total_count: ?int}
   */
  private function decodePlatformListResponse(ResponseInterface $resp): array {
    $raw = (string) $resp->getBody();
    $decoded = json_decode($raw, TRUE);
    if (!is_array($decoded)) {
      return ['rows' => [], 'total_count' => NULL];
    }

    $totalCount = NULL;
    if (!array_is_list($decoded)) {
      $tc = $decoded['TotalCount'] ?? $decoded['totalCount'] ?? NULL;
      if (is_numeric($tc)) {
        $totalCount = (int) $tc;
      }
    }

    $rows = [];
    if (array_is_list($decoded)) {
      $rows = $decoded;
    }
    else {
      $data = $decoded['Data'] ?? $decoded['data'] ?? [];
      $rows = is_array($data) ? $data : [];
    }

    return ['rows' => $rows, 'total_count' => $totalCount];
  }

  /**
   * @param array<string, mixed> $loc
   *
   * @return array<string, mixed>|null
   */
  public function mapFacilityRowToLegacyJson(array $loc): ?array {
    $id = $loc['Id'] ?? $loc['id'] ?? NULL;
    if (!is_numeric($id)) {
      return NULL;
    }
    return [
      'pk' => $id,
      'name' => $loc['Name'] ?? $loc['name'] ?? '',
      'connector' => $loc['Code'] ?? $loc['code'] ?? '',
    ];
  }

  /**
   * @param array<string, mixed> $loc
   *
   * @return array<string, mixed>|null
   */
  public function mapBuildingRowToLegacyJson(array $loc): ?array {
    $id = $loc['Id'] ?? $loc['id'] ?? NULL;
    $facilityId = $loc['FacilityId'] ?? $loc['facilityId'] ?? NULL;
    if (!is_numeric($id) || !is_numeric($facilityId)) {
      return NULL;
    }
    return [
      'pk' => $id,
      'name' => $loc['Name'] ?? $loc['name'] ?? '',
      'connector' => $facilityId,
    ];
  }

  /**
   * Legacy simpleArea: fu_unitID → name, fu_description → description; connector = building fk.
   *
   * @param array<string, mixed> $loc
   *
   * @return array<string, mixed>
   */
  public function mapAreaRowToLegacyJson(array $loc): array {
    $locationCode = trim((string) ($loc['LocationCode'] ?? $loc['locationCode'] ?? ''));
    $room = trim((string) ($loc['RoomNumber'] ?? $loc['roomNumber'] ?? ''));
    $desc = trim((string) ($loc['Description'] ?? $loc['description'] ?? ''));
    $id = $loc['Id'] ?? $loc['id'] ?? NULL;
    $name = $locationCode !== '' ? $locationCode : ($room !== '' ? $room : (string) $id);

    $floorCode = (string) ($loc['FloorCode'] ?? $loc['floorCode'] ?? '');
    // Some tenants encode FloorCode with an accidental double-prefix like "ADEN-ADEN-1B".
    // Normalize this so Drupal taxonomy `field_floor` matches legacy submissions (e.g. "ADEN-1B").
    $floorCode = trim($floorCode);
    if ($floorCode !== '') {
      if (preg_match('/^([A-Za-z0-9]+)-\\1-(.+)$/', $floorCode, $m)) {
        $floorCode = $m[1] . '-' . $m[2];
      }
    }

    return [
      'pk' => $id,
      'name' => $name,
      'connector' => $loc['BuildingId'] ?? $loc['buildingId'] ?? NULL,
      'description' => $desc,
      'floor_code' => $floorCode,
    ];
  }

}
