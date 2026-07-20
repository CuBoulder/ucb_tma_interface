<?php
/**
 * Created by PhpStorm.
 * User: hdsstudent
 * Date: 10/22/18
 * Time: 2:13 PM
 */

namespace Drupal\ucb_tma_interface\InterfaceController;

use Drupal\ucb_tma_interface\ApiConnector\PlatformConnector;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Drupal\Component\Serialization\Yaml;
use Drupal\taxonomy\TermInterface;

/**
 * Class TmaFrontController
 * @package Drupal\tma_interface\InterfaceController
 *
 * This class is the direct point of contact for the module routing
 * that maps the inputs from users to the correct logic for submission
 * of fixit requests to webTMA API.
 *
 * In other terms, the class ensures tickets submitted by users is
 * correctly submitted to TMA to be addressed by them.
 *
 * It also gathers location data for the location taxonomy since
 * that data is stored by TMA.
 *
 */
class TmaFrontController {

    private $config;

    public function __construct() {
        $this->config = \Drupal::config('ucb_tma_interface.settings');
    }

    /**
     * @return array|\Psr\Http\Message\ResponseInterface
     */
    public function submitFixitRequest($request) {
        // v7-only: submit via Platform API (Bearer JWT).
        $platformBase = rtrim((string) $this->config->get('base_url'), '/');
        if ($platformBase === '') {
            return $this->buildPlatformSubmissionErrorResponse('Missing Platform API base_url. Configure /admin/config/tma.');
        }

        $platform = \Drupal::service('ucb_tma_interface.platform_connector');
        if (!$platform instanceof PlatformConnector) {
            return $this->buildPlatformSubmissionErrorResponse('Platform connector service is unavailable.');
        }

        $reqData = is_array($request) ? $request : [];
        $payload = $this->buildPlatformRequestLogCreatePayload($reqData, $platform);
        if ($payload === NULL) {
            return $this->buildPlatformSubmissionErrorResponse('Could not resolve TMA facility for RequestLog.');
        }
        $this->debugLog('v7.before', [
            'integrationRevision' => '2026-05-22-requests-taskcode-camel',
            'incoming' => $this->sanitizeForLog($reqData),
            'payload' => $this->sanitizeForLog($payload),
        ]);
        $resp = $platform->postJson('/v2/Requests', $payload);
        if ($resp instanceof ResponseInterface) {
            $taskCode = trim((string) ($payload['taskCode'] ?? $payload['TaskCode'] ?? ''));
            $this->patchRequestLogTaskAfterCreate($platform, $resp, $taskCode);
            $this->verifyRequestLogAfterCreate($platform, $resp);
        }
        $resp = $this->maybeWrapRequestLogResponseAsLegacyIlog($platform, $reqData, $payload, $resp);
        $this->debugLog('v7.after', $this->formatResponseForLog($resp));
        return $resp;
    }

    /**
     * @return JsonResponse
     */
    public function getFacility() {
        return new JsonResponse($this->getLocationData("Facility"));
    }

    /**
     * @return JsonResponse
     */
    public function getBuilding() {
        return new JsonResponse($this->getLocationData("Building"));
    }

    /**
     * @return JsonResponse
     */
    public function getArea($facility = null) {
        return new JsonResponse($this->getLocationData("Area", $facilityName = $facility));
    }

    /**
     * Return the exception map (title -> exception_text) from fixit_tasks.yml.
     *
     * This avoids relying on content entities/views to determine exceptions, and matches
     * the canonical dataset used for seeding tasks.
     */
    public function getTaskExceptions(): JsonResponse {
        $rows = $this->loadFixitTasksYamlRows();

        $out = [];
        if (is_array($rows)) {
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $isException = $row['exception'] ?? FALSE;
                if ($isException !== TRUE) {
                    continue;
                }
                $title = trim((string) ($row['title'] ?? ''));
                $text = (string) ($row['exception_text'] ?? '');
                if ($title !== '' && trim($text) !== '') {
                    $out[$title] = $text;
                }
            }
        }

        return new JsonResponse([
            'exceptions' => $out,
        ]);
    }

    /**
     * Rows from data/fixit_tasks.yml (canonical task list for seeding and API codes).
     *
     * @return list<array<string, mixed>>
     */
    private function loadFixitTasksYamlRows(): array {
        static $cache = NULL;
        if (is_array($cache)) {
            return $cache;
        }
        $path = \Drupal::service('extension.list.module')->getPath('ucb_tma_interface');
        $yamlPath = $path . '/data/fixit_tasks.yml';
        try {
            $raw = is_file($yamlPath) ? file_get_contents($yamlPath) : '';
            $decoded = is_string($raw) && $raw !== '' ? Yaml::decode($raw) : [];
            $cache = is_array($decoded) ? $decoded : [];
        }
        catch (\Throwable) {
            $cache = [];
        }
        return $cache;
    }

    /**
     * Platform TaskCode from fixit_tasks.yml task_code, task node field_task_code, or webform value.
     */
    public function resolveFixitTaskCode(array $request): string {
        $raw = trim((string) ($request['task_select'] ?? ''));
        if ($raw === '') {
            return '';
        }
        if (ctype_digit($raw)) {
            $nid = (int) $raw;
            if ($nid > 0) {
                try {
                    $node = \Drupal::entityTypeManager()->getStorage('node')->load($nid);
                    if ($node && $node->hasField('field_task_code') && !$node->get('field_task_code')->isEmpty()) {
                        $code = trim((string) $node->get('field_task_code')->value);
                        if ($code !== '') {
                            return $code;
                        }
                    }
                    if ($node) {
                        $fromTitle = $this->lookupFixitTaskCodeInYaml(trim((string) $node->getTitle()));
                        if ($fromTitle !== '') {
                            return $fromTitle;
                        }
                    }
                }
                catch (\Throwable) {
                    return '';
                }
            }
            return '';
        }
        $fromYaml = $this->lookupFixitTaskCodeInYaml($raw);
        if ($fromYaml !== '') {
            return $fromYaml;
        }
        return strtoupper($raw);
    }

    /**
     * Match fixit_tasks.yml task_code by issue title or by code string.
     */
    private function lookupFixitTaskCodeInYaml(string $titleOrCode): string {
        $meta = $this->lookupFixitTaskMetaInYaml($titleOrCode);
        return $meta['code'] ?? '';
    }

    /**
     * @return array{code: string, title: string}
     */
    private function lookupFixitTaskMetaInYaml(string $titleOrCode): array {
        $titleOrCode = trim($titleOrCode);
        if ($titleOrCode === '') {
            return ['code' => '', 'title' => ''];
        }
        foreach ($this->loadFixitTasksYamlRows() as $row) {
            if (!is_array($row)) {
                continue;
            }
            $code = trim((string) ($row['task_code'] ?? ''));
            if ($code === '') {
                continue;
            }
            $title = trim((string) ($row['title'] ?? ''));
            if (strcasecmp($titleOrCode, $code) === 0 || ($title !== '' && strcasecmp($titleOrCode, $title) === 0)) {
                return ['code' => $code, 'title' => $title];
            }
        }
        return ['code' => '', 'title' => ''];
    }

    private function getLocationData($type, $facilityName = null): array {
        /** @var \Drupal\ucb_tma_interface\Service\TmaLocationFeedPayloadBuilder $builder */
        $builder = \Drupal::service('ucb_tma_interface.location_feed_payload');
        $t = strtolower((string) $type);
        if ($t === 'facility') {
          return $builder->getFeedItems('Facility', NULL);
        }
        if ($t === 'building') {
          return $builder->getFeedItems('Building', NULL);
        }
        if ($t === 'area') {
          return $builder->getFeedItems('Area', $facilityName);
        }
        return [];
    }

    /**
     * Build POST /v2/Requests payload (RequestLog PascalCase; matches Platform GET response shape).
     *
     * @param array<string, mixed> $request
     *
     * @return array<string, mixed>|null Null when facility id cannot be resolved.
     */
    private function buildPlatformRequestLogCreatePayload(array $request, PlatformConnector $platform): ?array {
        $contact = is_array($request['user_contact'] ?? NULL) ? $request['user_contact'] : [];
        $requestorName = trim((string) ($contact['name'] ?? ''));
        $requestorEmail = trim((string) ($contact['email'] ?? ''));
        $requestorPhone = trim((string) ($contact['phone'] ?? ''));
        $actionRequested = $this->issueDescriptionForWorkRequest(trim((string) ($request['input_information_related_to_the_issue'] ?? '')));

        $taskCode = $this->resolveFixitTaskCode($request);
        $facilityName = trim((string) ($request['facility'] ?? ''));
        $buildingRaw = trim((string) ($request['building'] ?? ''));
        $areaName = trim((string) ($request['area'] ?? ''));

        $facilityTmaId = $facilityName !== '' ? $this->lookupTermTmaIdByName('facility', 'field_tma_facility_id', $facilityName) : NULL;
        $buildingId = is_numeric($buildingRaw) ? (int) $buildingRaw : NULL;
        if (!is_int($buildingId) && $buildingRaw !== '') {
            $buildingId = $this->lookupTermTmaIdByName('building', 'field_tma_building_id_', $buildingRaw);
        }
        $areaId = $areaName !== '' ? $this->lookupTermTmaIdByName('area', 'field_tma_area_id', $areaName) : NULL;

        if (!is_int($facilityTmaId) || $facilityTmaId <= 0) {
            return NULL;
        }

        $requestTypeCode = trim((string) ($this->config->get('platform_request_type_code') ?: 'WEB'));
        if ($requestTypeCode === '') {
            $requestTypeCode = 'WEB';
        }
        $requestTypeId = $this->lookupPlatformNumericIdByCode($platform, '/v2/RequestTypes', $requestTypeCode);

        $repairCenter = $this->resolveRepairCenterForWorkRequest($platform, $request, $taskCode);
        $repairCenterCode = $repairCenter['code'] ?? NULL;
        $repairCenterName = $repairCenter['name'] ?? NULL;
        $repairCenterId = is_string($repairCenterCode) && $repairCenterCode !== ''
            ? $this->lookupPlatformNumericIdByCode($platform, '/v2/RepairCenters', $repairCenterCode)
            : NULL;

        $floorCode = $this->resolveWorkRequestFloorNumber($request, $areaName, is_int($areaId) ? $areaId : NULL);
        if ($floorCode === '' && is_int($areaId) && $areaId > 0) {
            $areaRec = $this->fetchPlatformAreaRecord($platform, $areaId);
            if (is_array($areaRec)) {
                $floorCode = $this->scalarStringFromRecord($areaRec, ['FloorCode', 'floorCode'])
                    ?? $this->stringFieldFromRecord($areaRec, ['FloorCode', 'floorCode'])
                    ?? '';
            }
        }

        $facilityCode = $this->fetchFacilityCode($platform, $facilityTmaId);
        $buildingCode = is_int($buildingId) && $buildingId > 0 ? $this->fetchBuildingCode($platform, $buildingId) : NULL;
        $facilityPlatformName = $this->fetchFacilityDisplayName($platform, $facilityTmaId);
        $buildingPlatformName = is_int($buildingId) && $buildingId > 0
            ? $this->fetchBuildingDisplayName($platform, $buildingId)
            : NULL;

        $locationFields = $this->buildRequestLogLocationFields(
            $platform,
            $facilityTmaId,
            $facilityCode,
            $facilityPlatformName,
            is_int($buildingId) && $buildingId > 0 ? $buildingId : NULL,
            $buildingCode,
            $buildingPlatformName,
            $areaName,
            is_int($areaId) ? $areaId : NULL
        );

        // Writable RequestLog fields (response adds Id, Number, CreatedDate, …).
        $payload = [
            'ObjectState' => 0,
            'RequestDate' => $this->formatRequestLogDateUtc(),
            'ActionRequested' => $actionRequested,
            'NumberOfEstimate' => 0,
            'Active' => FALSE,
            'NotifyMe' => TRUE,
            'Authorized' => TRUE,
            'RoutingStatus' => 0,
            'RequestorName' => $requestorName !== '' ? $requestorName : NULL,
            'RequestorPhone' => $requestorPhone !== '' ? $requestorPhone : NULL,
            'RequestorEmail' => $requestorEmail !== '' ? $requestorEmail : NULL,
            'FacilityId' => $facilityTmaId,
            'isPending' => TRUE,
        ];
        if (is_string($facilityCode) && $facilityCode !== '') {
            $payload['FacilityCode'] = $facilityCode;
        }
        if (is_string($facilityPlatformName) && $facilityPlatformName !== '') {
            $payload['FacilityName'] = $facilityPlatformName;
        }
        if (is_int($buildingId) && $buildingId > 0) {
            $payload['BuildingId'] = $buildingId;
            if (is_string($buildingCode) && $buildingCode !== '') {
                $payload['BuildingCode'] = $buildingCode;
            }
            if (is_string($buildingPlatformName) && $buildingPlatformName !== '') {
                $payload['BuildingName'] = $buildingPlatformName;
            }
        }
        if (is_int($requestTypeId) && $requestTypeId > 0) {
            $payload['RequestTypeId'] = $requestTypeId;
        }
        if ($requestTypeCode !== '') {
            $payload['RequestTypeCode'] = $requestTypeCode;
        }
        if (is_string($repairCenterCode) && $repairCenterCode !== '') {
            $payload['RepairCenterCode'] = $repairCenterCode;
        }
        if (is_int($repairCenterId) && $repairCenterId > 0) {
            $payload['RepairCenterId'] = $repairCenterId;
        }
        if (is_string($repairCenterName) && $repairCenterName !== '') {
            $payload['RepairCenterName'] = $repairCenterName;
        }
        if ($floorCode !== '') {
            $payload['FloorCode'] = $floorCode;
        }
        if (is_int($areaId) && $areaId > 0) {
            $payload['AreaId'] = $areaId;
            $floorId = $this->lookupPlatformFloorIdForArea($platform, $areaId);
            if (is_int($floorId) && $floorId > 0) {
                $payload['FloorId'] = $floorId;
            }
            $areaRoom = $this->resolveWorkRequestRoomNumber($platform, $areaName, $areaId);
            if ($areaRoom !== '') {
                $payload['AreaRoomNumber'] = $areaRoom;
                $payload['AreaLocationCode'] = $areaRoom;
            }
        }

        $payload = array_merge($payload, $locationFields);
        $this->applyRequestLogTaskFields($payload, $platform, $taskCode);

        $this->debugLog('v7.resolved_ids', [
            'mode' => 'request_log',
            'endpoint' => 'POST /v2/Requests',
            'taskCode' => $taskCode,
            'taskSelectRaw' => trim((string) ($request['task_select'] ?? '')),
            'taskCodeOnPayload' => $payload['taskCode'] ?? $payload['TaskCode'] ?? NULL,
            'taskIdOnPayload' => $payload['taskId'] ?? $payload['TaskId'] ?? NULL,
            'requestTypeCode' => $requestTypeCode,
            'requestTypeId' => $requestTypeId,
            'repairCenterCode' => $repairCenterCode,
            'repairCenterId' => $repairCenterId,
            'facilityTmaId' => $facilityTmaId,
            'buildingId' => $buildingId,
            'areaName' => $areaName,
            'areaId' => $areaId,
            'floorCode' => $floorCode,
            'locationFields' => $this->sanitizeForLog($locationFields),
        ]);

        return $payload;
    }

    /**
     * RequestLog.RequestDate as the actual submission time in UTC
     */
    private function formatRequestLogDateUtc(): string {
        return gmdate('c');
    }

    /**
     * RequestLog Location* fields: Facility (type 10) or Area (type 7) when area is selected.
     *
     * @return array<string, mixed>
     */
    private function buildRequestLogLocationFields(
        PlatformConnector $platform,
        int $facilityId,
        ?string $facilityCode,
        ?string $facilityName,
        ?int $buildingId,
        ?string $buildingCode,
        ?string $buildingName,
        string $areaName,
        ?int $areaId,
    ): array {
        $facilityTypeId = (int) ($this->config->get('platform_facility_location_type_id') ?? 10);
        $areaTypeId = (int) ($this->config->get('platform_area_location_type_id') ?? 7);
        if ($facilityTypeId <= 0) {
            $facilityTypeId = 10;
        }
        if ($areaTypeId <= 0) {
            $areaTypeId = 7;
        }

        if ((!is_int($areaId) || $areaId <= 0) && $areaName !== '') {
            $resolved = $this->lookupTermTmaIdByName('area', 'field_tma_area_id', $areaName);
            $areaId = is_int($resolved) && $resolved > 0 ? $resolved : NULL;
        }

        $fields = [];
        $areaRoom = $this->resolveWorkRequestRoomNumber($platform, $areaName, is_int($areaId) ? $areaId : NULL);

        if (is_int($areaId) && $areaId > 0) {
            $fields['LocationTypeId'] = $areaTypeId;
            $fields['LocationId'] = $areaId;
            $fields['AreaId'] = $areaId;
            if ($areaRoom !== '') {
                $fields['LocationCode'] = $areaRoom;
                $fields['AreaRoomNumber'] = $areaRoom;
                $fields['AreaLocationCode'] = $areaRoom;
            }
            $areaRec = $this->fetchPlatformAreaRecord($platform, $areaId);
            if (is_array($areaRec)) {
                $areaDesc = $this->scalarStringFromRecord($areaRec, ['Description', 'description'])
                    ?? $this->stringFieldFromRecord($areaRec, ['Description', 'description']);
                if (is_string($areaDesc) && $areaDesc !== '') {
                    $fields['LocationName'] = $areaDesc;
                    $fields['AreaDescription'] = $areaDesc;
                }
                $floorFromArea = $this->scalarStringFromRecord($areaRec, ['FloorCode', 'floorCode'])
                    ?? $this->stringFieldFromRecord($areaRec, ['FloorCode', 'floorCode']);
                if (is_string($floorFromArea) && $floorFromArea !== '') {
                    $fields['FloorCode'] = $floorFromArea;
                }
                $floorId = $areaRec['FloorId'] ?? $areaRec['floorId'] ?? NULL;
                if (is_numeric($floorId) && (int) $floorId > 0) {
                    $fields['FloorId'] = (int) $floorId;
                }
            }
            return $fields;
        }

        // Facility-level location (matches manual RequestLog: LocationTypeId 10, LocationId = FacilityId).
        $fields['LocationTypeId'] = $facilityTypeId;
        $fields['LocationId'] = $facilityId;
        if (is_string($facilityCode) && $facilityCode !== '') {
            $fields['LocationCode'] = $facilityCode;
        }
        if (is_string($facilityName) && $facilityName !== '') {
            $fields['LocationName'] = $facilityName;
        }
        if (is_int($buildingId) && $buildingId > 0) {
            $fields['BuildingId'] = $buildingId;
            if (is_string($buildingCode) && $buildingCode !== '') {
                $fields['BuildingCode'] = $buildingCode;
            }
            if (is_string($buildingName) && $buildingName !== '') {
                $fields['BuildingName'] = $buildingName;
            }
        }

        return $fields;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchPlatformAreaRecord(PlatformConnector $platform, int $platformAreaId): ?array {
        if ($platformAreaId <= 0) {
            return NULL;
        }
        $rec = $this->fetchPlatformEntity(
            $platform,
            '/v2/Areas/' . $platformAreaId . '?columns=LocationCode,RoomNumber,FloorCode,FloorId,locationCode,roomNumber,floorCode,floorId'
        );
        return is_array($rec) ? $rec : NULL;
    }

    /**
     * Add task fields to POST /v2/Requests (camelCase like WorkRequest + PascalCase response shape).
     *
     * @param array<string, mixed> $payload
     */
    private function applyRequestLogTaskFields(array &$payload, PlatformConnector $platform, string $taskCode): void {
        $taskCode = trim($taskCode);
        if ($taskCode === '') {
            return;
        }
        $yamlMeta = $this->lookupFixitTaskMetaInYaml($taskCode);
        $code = ($yamlMeta['code'] ?? '') !== '' ? $yamlMeta['code'] : strtoupper($taskCode);

        // WorkRequest sample uses taskCode; RequestLog GET returns TaskCode — send both.
        $payload['taskCode'] = $code;
        $payload['TaskCode'] = $code;

        $issueTitle = trim((string) ($yamlMeta['title'] ?? ''));
        if ($issueTitle !== '') {
            $payload['taskDescription'] = $issueTitle;
            $payload['TaskDescription'] = $issueTitle;
        }

        $taskId = $this->lookupPlatformTaskIdByCode($platform, $code);
        if (is_int($taskId) && $taskId > 0) {
            $payload['taskId'] = $taskId;
            $payload['TaskId'] = $taskId;
        }
    }

    /**
     * PATCH task onto RequestLog after create (POST often leaves TaskCode null on pending rows).
     */
    private function patchRequestLogTaskAfterCreate(PlatformConnector $platform, ResponseInterface $resp, string $taskCode): void {
        $taskCode = trim($taskCode);
        if ($taskCode === '' || $resp->getStatusCode() < 200 || $resp->getStatusCode() >= 300) {
            return;
        }
        $requestLogId = $this->resolveRequestLogIdFromCreateResponse($platform, $resp);
        if (!is_int($requestLogId) || $requestLogId <= 0) {
            $this->debugLog('v7.request_log_task_patch_skipped', ['reason' => 'no_request_log_id', 'taskCode' => $taskCode]);
            return;
        }
        $patch = [];
        $this->applyRequestLogTaskFields($patch, $platform, $taskCode);
        if ($patch === []) {
            return;
        }
        $patchResp = $platform->patchJson('/v2/Requests/' . $requestLogId, $patch);
        if (!$patchResp instanceof ResponseInterface) {
            return;
        }
        $ps = $patchResp->getStatusCode();
        $this->debugLog($ps >= 200 && $ps < 300 ? 'v7.request_log_task_patch' : 'v7.request_log_task_patch_error', [
            'requestLogId' => $requestLogId,
            'taskCode' => $taskCode,
            'status' => $ps,
            'patch' => $this->sanitizeForLog($patch),
        ]);
    }

    private function resolveRequestLogIdFromCreateResponse(PlatformConnector $platform, ResponseInterface $resp): ?int {
        $raw = $this->readResponseBodyWithoutConsuming($resp);
        $decoded = json_decode($raw, TRUE);
        if (!is_array($decoded)) {
            return NULL;
        }
        $id = $decoded['Id'] ?? $decoded['id'] ?? NULL;
        if (is_numeric($id) && (int) $id > 0) {
            return (int) $id;
        }
        $requestNumber = trim((string) ($decoded['Number'] ?? $decoded['number'] ?? ''));
        if ($requestNumber === '') {
            return NULL;
        }
        return $this->lookupRequestLogIdByNumber($platform, $requestNumber, 2);
    }

    /**
     * Lookup /v2/Tasks Id by Code (exact match only).
     */
    private function lookupPlatformTaskIdByCode(PlatformConnector $platform, string $code): ?int {
        $code = trim($code);
        if ($code === '') {
            return NULL;
        }
        $escaped = str_replace("'", "''", $code);
        $filters = [
            "Code eq '" . $escaped . "'",
            "tolower(Code) eq '" . strtolower($escaped) . "'",
        ];
        foreach ($filters as $expr) {
            $listResp = $platform->get('/v2/Tasks?filter=' . rawurlencode($expr) . '&pageSize=5&columns=Id,Code');
            $rows = $this->decodePlatformListRows($listResp);
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $rowCode = $this->stringFieldFromRecord($row, ['Code', 'code']);
                if ($rowCode === NULL || strcasecmp(trim($rowCode), $code) !== 0) {
                    continue;
                }
                $id = $row['Id'] ?? $row['id'] ?? NULL;
                if (is_numeric($id) && (int) $id > 0) {
                    return (int) $id;
                }
            }
        }
        return NULL;
    }

    /**
     * Lookup Platform entity Id by Code (RequestTypes, Tasks, RepairCenters, …).
     */
    private function lookupPlatformNumericIdByCode(PlatformConnector $platform, string $resourcePath, string $code): ?int {
        $code = trim($code);
        if ($code === '') {
            return NULL;
        }
        $resourcePath = rtrim($resourcePath, '/');
        $escaped = str_replace("'", "''", $code);
        $filters = [
            "Code eq '" . $escaped . "'",
            "tolower(Code) eq '" . strtolower($escaped) . "'",
        ];
        foreach ($filters as $expr) {
            $listResp = $platform->get($resourcePath . '?filter=' . rawurlencode($expr) . '&pageSize=1&columns=Id,Code');
            $rows = $this->decodePlatformListRows($listResp);
            $first = is_array($rows[0] ?? NULL) ? $rows[0] : NULL;
            $id = is_array($first) ? ($first['Id'] ?? $first['id'] ?? NULL) : NULL;
            if (is_numeric($id) && (int) $id > 0) {
                return (int) $id;
            }
        }
        return NULL;
    }

    /**
     * When Platform POST /v2/Requests succeeds, return a legacy-shaped response body.
     */
    private function maybeWrapRequestLogResponseAsLegacyIlog(PlatformConnector $platform, array $reqData, array $payload, mixed $resp): mixed {
        if (!$resp instanceof ResponseInterface) {
            return $resp;
        }
        $status = $resp->getStatusCode();
        if ($status < 200 || $status >= 300) {
            return $resp;
        }
        $raw = $this->readResponseBodyWithoutConsuming($resp);
        $decoded = json_decode($raw, TRUE);
        if (!is_array($decoded)) {
            return $resp;
        }
        $requestNumber = trim((string) ($decoded['Number'] ?? $decoded['number'] ?? ''));
        if ($requestNumber === '') {
            return $resp;
        }

        $contact = is_array($reqData['user_contact'] ?? NULL) ? $reqData['user_contact'] : [];
        $requestorName = (string) ($contact['name'] ?? '');
        $requestorEmail = (string) ($contact['email'] ?? '');
        $requestorPhone = (string) ($contact['phone'] ?? '');
        $actionRequested = trim((string) ($payload['ActionRequested'] ?? ''));
        if ($actionRequested === '') {
            $actionRequested = $this->issueDescriptionForWorkRequest((string) ($reqData['input_information_related_to_the_issue'] ?? ''));
        }

        $facilityLabel = (string) ($reqData['facility'] ?? '');
        $areaLabel = (string) ($reqData['area'] ?? '');
        $resolvedAreaId = $areaLabel !== '' ? $this->lookupTermTmaIdByName('area', 'field_tma_area_id', $areaLabel) : NULL;
        $floor = trim((string) ($payload['FloorCode'] ?? ''));
        if ($floor === '') {
            $floor = $this->resolveWorkRequestFloorNumber(
                $reqData,
                $areaLabel,
                is_int($resolvedAreaId) && $resolvedAreaId > 0 ? $resolvedAreaId : NULL
            );
        }

        $buildingName = '';
        $buildingRaw = (string) ($reqData['building'] ?? '');
        if ($buildingRaw !== '' && is_numeric($buildingRaw)) {
            $b = $this->fetchPlatformEntity($platform, '/v2/Buildings/' . (int) $buildingRaw . '?columns=Name');
            if (is_array($b)) {
                $buildingName = (string) ($b['Name'] ?? $b['name'] ?? '');
            }
        }
        if ($buildingName === '') {
            $buildingName = $buildingRaw;
        }

        $clientName = (string) ($this->config->get('authentication_client_name') ?? '');
        if ($clientName === '') {
            $clientName = 'ucb';
        }

        $repairCenterCode = (string) ($payload['RepairCenterCode'] ?? '');
        $taskCode = trim((string) ($payload['taskCode'] ?? $payload['TaskCode'] ?? ''));
        $reqDate = (string) ($payload['RequestDate'] ?? gmdate('c'));

        $legacy = [
            'NewDataSet' => [
                'i_WebTMA_Requests' => [
                    [
                        'ILOG_PK' => '',
                        'ILOG_NUMBER' => $requestNumber,
                        'ILOG_REQUESTOR' => $requestorName,
                        'ILOG_REQ_DATE' => $reqDate,
                        'ILOG_REQ_PHONE' => $requestorPhone,
                        'ILOG_REQ_EMAIL' => $requestorEmail,
                        'ILOG_REQUEST' => $actionRequested,
                        'ILOG_CREATED_DATE' => $reqDate,
                        'ILOG_CLIENT_NAME' => $clientName,
                        'ILOG_EMAIL' => TRUE,
                        'ILOG_RQ_ID' => '',
                        'ILOG_PROCESSED' => 0,
                        'ILOG_PROCESSED_ERROR' => '',
                        'ILOG_RC_CODE' => $repairCenterCode,
                        'ILOG_REQ_TYPE' => 'Fix It!',
                        'ILOG_FACILITY' => $facilityLabel,
                        'ILOG_BUILDING' => $buildingName,
                        'ILOG_FLOOR' => $floor,
                        'ILOG_AREA' => $areaLabel,
                        'ILOG_REF' => '',
                        'ILOG_STATUS' => '',
                        'ILOG_department' => '',
                        'ILOG_TASKCODE' => $taskCode,
                        'ILOG_ACCOUNTCODE' => '',
                        'state' => 0,
                    ],
                ],
            ],
        ];

        $body = json_encode($legacy, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return new Response(200, ['Content-Type' => 'application/json'], $body ?: '{}');
    }

    /**
     * Debug GET after POST /v2/Requests to confirm Request Information fields persisted.
     */
    private function verifyRequestLogAfterCreate(PlatformConnector $platform, ResponseInterface $resp): void {
        $status = $resp->getStatusCode();
        if ($status < 200 || $status >= 300) {
            return;
        }
        $raw = $this->readResponseBodyWithoutConsuming($resp);
        $decoded = json_decode($raw, TRUE);
        if (!is_array($decoded)) {
            return;
        }
        $requestLogId = $decoded['Id'] ?? $decoded['id'] ?? NULL;
        if (!is_numeric($requestLogId) || (int) $requestLogId <= 0) {
            $requestNumber = trim((string) ($decoded['Number'] ?? $decoded['number'] ?? ''));
            if ($requestNumber !== '') {
                $requestLogId = $this->lookupRequestLogIdByNumber($platform, $requestNumber, 2);
            }
        }
        if (!is_numeric($requestLogId) || (int) $requestLogId <= 0) {
            return;
        }
        $cols = 'Id,Number,LocationId,LocationTypeId,AreaRoomNumber,AreaLocationCode,FloorCode,NotifyMe,AreaId,FacilityId,BuildingId,TaskCode,TaskId,TaskDescription,taskCode,taskId,taskDescription';
        $getResp = $platform->get('/v2/Requests/' . (int) $requestLogId . '?columns=' . rawurlencode($cols));
        if (!$getResp instanceof ResponseInterface || $getResp->getStatusCode() < 200 || $getResp->getStatusCode() >= 300) {
            return;
        }
        $body = $this->readResponseBodyWithoutConsuming($getResp);
        $row = json_decode($body, TRUE);
        if (!is_array($row)) {
            return;
        }
        $this->debugLog('v7.request_log_verify', [
            'requestLogId' => (int) $requestLogId,
            'record' => $this->sanitizeForLog($row),
        ]);
    }

    /**
     * Free-text issue only for WorkRequest.actionRequested / legacy ILOG_REQUEST.
     *
     * Strips TMA / webform template tails (labeled empty fields) that are not meant to live
     * inside the issue body — those belong in separate API keys when we send them.
     */
    private function issueDescriptionForWorkRequest(string $raw): string {
        $s = trim(str_replace(["\r\n", "\r"], "\n", $raw));
        if ($s === '') {
            return '';
        }
        // Rare: textarea stored HTML breaks instead of newlines.
        $s = preg_replace('#<br\s*/?>#i', "\n", $s) ?? $s;
        if (preg_match('/<(?:br|p|div)\b/i', $s)) {
            $s = html_entity_decode(strip_tags($s), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $s = trim(str_replace(["\r\n", "\r"], "\n", $s));
        }
        // Labeled template block (RepairCenter / Department / RoomNumber / …) is not part of the issue body.
        if (preg_match('/\R[ \t\x{00A0}]*(?:RepairCenter|Department)\s*:/u', $s, $m, PREG_OFFSET_CAPTURE)) {
            $s = trim(substr($s, 0, $m[0][1]));
        }
        $labels = 'RepairCenter|Department|EquipmentTagNumber|EquipmentDescription|RoomNumber|DueDate|SubLocation|RecommendedAction|Account';
        $s = preg_replace('/^[ \t\x{00A0}]*(?:' . $labels . ')\s*:.*$/mu', '', $s) ?? $s;
        $s = preg_replace('/^[ \t\x{00A0}]*Requestor\s*:.*$/mu', '', $s) ?? $s;
        $s = preg_replace('/^[ \t\x{00A0}]*Phone\s*:.*$/mu', '', $s) ?? $s;
        $s = preg_replace("/\n{3,}/u", "\n\n", $s);
        return trim($s);
    }

    private function lookupRequestLogIdByNumber(PlatformConnector $platform, string $requestNumber, int $attempts = 1): ?int {
        $requestNumber = trim($requestNumber);
        if ($requestNumber === '') {
            return NULL;
        }
        $escaped = str_replace("'", "''", $requestNumber);
        $filters = [
            "Number eq '" . $escaped . "'",
            "number eq '" . $escaped . "'",
        ];
        if (ctype_digit($requestNumber)) {
            $filters[] = 'Number eq ' . (int) $requestNumber;
            $filters[] = 'number eq ' . (int) $requestNumber;
        }
        $attempts = max(1, $attempts);
        for ($try = 0; $try < $attempts; $try++) {
            foreach ($filters as $expr) {
                $listResp = $platform->get('/v2/Requests?filter=' . rawurlencode($expr) . '&pageSize=1&columns=Id,Number');
                $rows = $this->decodePlatformListRows($listResp);
                $first = is_array($rows[0] ?? NULL) ? $rows[0] : NULL;
                $id = is_array($first) ? ($first['Id'] ?? $first['id'] ?? NULL) : NULL;
                if (is_numeric($id) && (int) $id > 0) {
                    return (int) $id;
                }
            }
            if ($try + 1 < $attempts) {
                usleep(300000);
            }
        }
        return NULL;
    }

    private function lookupPlatformFloorIdForArea(PlatformConnector $platform, int $platformAreaId): ?int {
        $rec = $this->fetchPlatformEntity($platform, '/v2/Areas/' . $platformAreaId . '?columns=FloorId,floorId');
        if (!is_array($rec)) {
            return NULL;
        }
        $fid = $rec['FloorId'] ?? $rec['floorId'] ?? NULL;
        return is_numeric($fid) && (int) $fid > 0 ? (int) $fid : NULL;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchPlatformEntity(PlatformConnector $platform, string $relativePath): ?array {
        $resp = $platform->get($relativePath);
        if (!$resp instanceof ResponseInterface || $resp->getStatusCode() !== 200) {
            return NULL;
        }
        $data = json_decode((string) $resp->getBody(), TRUE);
        return is_array($data) ? $data : NULL;
    }

    /**
     * @param list<string> $keys
     */
    private function stringFieldFromRecord(?array $record, array $keys): ?string {
        if (!is_array($record)) {
            return NULL;
        }
        foreach ($keys as $key) {
            $v = $record[$key] ?? NULL;
            if (is_string($v) && trim($v) !== '') {
                return trim($v);
            }
        }
        return NULL;
    }

    /**
     * @param list<string> $keys
     */
    private function scalarStringFromRecord(?array $record, array $keys): ?string {
        if (!is_array($record)) {
            return NULL;
        }
        foreach ($keys as $key) {
            $v = $record[$key] ?? NULL;
            if (is_string($v) && trim($v) !== '') {
                return trim($v);
            }
            if (is_int($v) || is_float($v)) {
                return trim((string) $v);
            }
        }
        return NULL;
    }

    /**
     * Normalize Platform location codes embedded in descriptions (e.g. BRKT-BRKT-02 → BRKT-02).
     *
     * Not used for WorkRequest.floorNumber; that value comes from taxonomy `field_floor` (already
     * normalized when areas are imported in TmaLocationFeedPayloadBuilder).
     */
    private function normalizeDoublePrefixedLocationCode(string $code): string {
        $code = trim($code);
        if ($code === '') {
            return '';
        }
        if (preg_match('/^([A-Za-z0-9]+)-\\1-(.+)$/', $code, $m)) {
            return $m[1] . '-' . $m[2];
        }
        return $code;
    }

    private function fetchFacilityCode(PlatformConnector $platform, int $facilityId): ?string {
        $rec = $this->fetchPlatformEntity($platform, '/v2/Facilities/' . $facilityId . '?columns=Code');
        return $this->stringFieldFromRecord($rec, ['Code', 'code']);
    }

    private function fetchFacilityDisplayName(PlatformConnector $platform, int $facilityId): ?string {
        $rec = $this->fetchPlatformEntity($platform, '/v2/Facilities/' . $facilityId . '?columns=Name');
        return $this->stringFieldFromRecord($rec, ['Name', 'name']);
    }

    private function fetchBuildingCode(PlatformConnector $platform, int $buildingId): ?string {
        $rec = $this->fetchPlatformEntity($platform, '/v2/Buildings/' . $buildingId . '?columns=Code');
        return $this->stringFieldFromRecord($rec, ['Code', 'code']);
    }

    private function fetchBuildingDisplayName(PlatformConnector $platform, int $buildingId): ?string {
        $rec = $this->fetchPlatformEntity($platform, '/v2/Buildings/' . $buildingId . '?columns=Name');
        return $this->stringFieldFromRecord($rec, ['Name', 'name']);
    }

    /**
     * Repair center for WorkRequest when task field_repair_center is true (fixit_tasks.yml via seeder).
     *
     * @param array<string, mixed> $request
     *
     * @return array{code: string, name: string|null}
     */
    private function resolveRepairCenterForWorkRequest(PlatformConnector $platform, array $request, string $taskCode): array {
        // Webform handler sets repair_center to '' or 'FS' from the selected task node; do not
        // override an explicit empty value by looking up task code (SIGNAG matches multiple tasks).
        if (array_key_exists('repair_center', $request)) {
            $code = trim((string) $request['repair_center']);
        }
        else {
            $code = '';
            if ($code === '' && $taskCode !== '' && $this->taskRepairCenterEnabledFromTaskCode($taskCode)) {
                $code = 'FS';
            }
        }
        if ($code === '') {
            return [];
        }
        return [
            'code' => $code,
            'name' => $this->fetchRepairCenterDisplayName($platform, $code),
        ];
    }

    /**
     * Whether the Fix It task node has field_repair_center set (from fixit_tasks.yml repair_center).
     */
    public function taskRepairCenterEnabledFromTaskCode(string $taskCode): bool {
        $taskCode = trim($taskCode);
        if ($taskCode === '') {
            return FALSE;
        }
        try {
            $nids = \Drupal::entityTypeManager()->getStorage('node')->getQuery()
                ->accessCheck(FALSE)
                ->condition('type', 'task')
                ->condition('field_task_code', $taskCode)
                ->execute();
            if (!$nids) {
                return FALSE;
            }
            // Multiple tasks can share a code (e.g. SIGNAG); do not send FS if any match is false.
            $anyTrue = FALSE;
            foreach ($nids as $nid) {
                $node = \Drupal::entityTypeManager()->getStorage('node')->load((int) $nid);
                if (!$node || !$node->hasField('field_repair_center') || $node->get('field_repair_center')->isEmpty()) {
                    continue;
                }
                if (!(bool) $node->get('field_repair_center')->value) {
                    return FALSE;
                }
                $anyTrue = TRUE;
            }
            return $anyTrue;
        }
        catch (\Throwable) {
            return FALSE;
        }
    }

    /**
     * Repair center flag for a specific task node (webform task_select nid).
     */
    public function taskRepairCenterEnabledFromNodeId(int $nodeId): bool {
        if ($nodeId <= 0) {
            return FALSE;
        }
        try {
            $node = \Drupal::entityTypeManager()->getStorage('node')->load($nodeId);
            if (!$node || !$node->hasField('field_repair_center') || $node->get('field_repair_center')->isEmpty()) {
                return FALSE;
            }
            return (bool) $node->get('field_repair_center')->value;
        }
        catch (\Throwable) {
            return FALSE;
        }
    }

    private function fetchRepairCenterDisplayName(PlatformConnector $platform, string $code): ?string {
        $code = trim($code);
        if ($code === '') {
            return NULL;
        }
        $escaped = str_replace("'", "''", $code);
        $filters = [
            "Code eq '" . $escaped . "'",
            "tolower(Code) eq '" . strtolower($escaped) . "'",
        ];
        foreach ($filters as $expr) {
            $listResp = $platform->get('/v2/RepairCenters?filter=' . rawurlencode($expr) . '&pageSize=1&columns=Name,Code');
            $rows = $this->decodePlatformListRows($listResp);
            $first = is_array($rows[0] ?? NULL) ? $rows[0] : NULL;
            $name = $this->stringFieldFromRecord($first, ['Name', 'name']);
            if ($name !== NULL && $name !== '') {
                return $name;
            }
        }
        return NULL;
    }

    private function buildPlatformSubmissionErrorResponse(string $message): ResponseInterface {
        $body = json_encode([
            'Success' => FALSE,
            'ErrorMessage' => $message,
            'TMAWorkOrderNumber' => NULL,
            'TMARequestNumber' => NULL,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return new Response(400, ['Content-Type' => 'application/json'], $body ?: '{}');
    }

    /**
     * Floor string from the area vocabulary term's field_floor (entity API only).
     *
     * @param string $areaSubmitted
     *   Webform `area` value (usually LocationCode / term name, or numeric taxonomy tid).
     * @param int|null $platformAreaId
     *   Platform area id stored on the term as field_tma_area_id, when already resolved.
     */
    public function getFloorFromAreaTaxonomy(string $areaSubmitted, ?int $platformAreaId = NULL): string {
        $areaSubmitted = trim($areaSubmitted);
        try {
            $storage = \Drupal::entityTypeManager()->getStorage('taxonomy_term');
        }
        catch (\Throwable) {
            return '';
        }

        $readFloor = static function ($term): string {
            if (!$term instanceof TermInterface || $term->bundle() !== 'area') {
                return '';
            }
            $base = method_exists($term, 'getUntranslated') ? $term->getUntranslated() : $term;
            if (!$base->hasField('field_floor') || $base->get('field_floor')->isEmpty()) {
                return '';
            }
            $v = $base->get('field_floor')->value;
            if (is_string($v)) {
                return trim($v);
            }
            if (is_int($v) || is_float($v)) {
                return trim((string) $v);
            }
            return '';
        };

        try {
            if ($areaSubmitted !== '' && ctype_digit($areaSubmitted)) {
                $term = $storage->load((int) $areaSubmitted);
                if ($term) {
                    $hit = $readFloor($term);
                    if ($hit !== '') {
                        return $hit;
                    }
                }
            }
            if ($areaSubmitted !== '') {
                $tids = $storage->getQuery()
                    ->accessCheck(FALSE)
                    ->condition('vid', 'area')
                    ->condition('name', $areaSubmitted)
                    ->range(0, 1)
                    ->execute();
                if ($tids) {
                    $term = $storage->load((int) reset($tids));
                    $hit = $readFloor($term);
                    if ($hit !== '') {
                        return $hit;
                    }
                }
            }
            if (is_int($platformAreaId) && $platformAreaId > 0) {
                $tids = $storage->getQuery()
                    ->accessCheck(FALSE)
                    ->condition('vid', 'area')
                    ->condition('field_tma_area_id', $platformAreaId)
                    ->range(0, 1)
                    ->execute();
                if ($tids) {
                    $term = $storage->load((int) reset($tids));
                    $hit = $readFloor($term);
                    if ($hit !== '') {
                        return $hit;
                    }
                }
            }
        }
        catch (\Throwable) {
            return '';
        }

        return '';
    }

    /**
     * Floor code for WorkRequest.floorNumber / TMA Floor Code: area term field_floor (fixit_tasks feed).
     *
     * @param array<string, mixed> $request
     */
    private function resolveWorkRequestFloorNumber(array $request, string $areaName, ?int $platformAreaId): string {
        $platformAreaId = is_int($platformAreaId) && $platformAreaId > 0 ? $platformAreaId : NULL;
        if ($areaName !== '' || $platformAreaId !== NULL) {
            $fromTaxonomy = $this->getFloorFromAreaTaxonomy($areaName, $platformAreaId);
            if ($fromTaxonomy !== '') {
                return $fromTaxonomy;
            }
        }
        return trim((string) ($request['floor'] ?? ''));
    }

    /**
     * Area # for WorkRequest.roomNumber: area term name (LocationCode), else Platform Area fields.
     */
    private function resolveWorkRequestRoomNumber(PlatformConnector $platform, string $areaName, ?int $platformAreaId): string {
        $areaName = trim($areaName);
        if ($areaName !== '') {
            return $areaName;
        }
        if (!is_int($platformAreaId) || $platformAreaId <= 0) {
            return '';
        }
        $rec = $this->fetchPlatformEntity(
            $platform,
            '/v2/Areas/' . $platformAreaId . '?columns=LocationCode,RoomNumber,locationCode,roomNumber'
        );
        if (!is_array($rec)) {
            return '';
        }
        $loc = $this->scalarStringFromRecord($rec, ['LocationCode', 'locationCode'])
            ?? $this->stringFieldFromRecord($rec, ['LocationCode', 'locationCode']);
        if ($loc !== NULL && $loc !== '') {
            return $loc;
        }
        $room = $this->scalarStringFromRecord($rec, ['RoomNumber', 'roomNumber'])
            ?? $this->stringFieldFromRecord($rec, ['RoomNumber', 'roomNumber']);
        return $room !== NULL && $room !== '' ? $room : '';
    }

    /**
     * Human-readable floor / area context for WorkRequest.subLocation (TMA "Type Description" style).
     */
    private function resolveWorkRequestSubLocation(PlatformConnector $platform, ?int $platformAreaId): string {
        if (!is_int($platformAreaId) || $platformAreaId <= 0) {
            return '';
        }
        $areaPaths = [
            '/v2/Areas/' . $platformAreaId . '?columns=FloorId,Description,description',
            '/v2/Areas/' . $platformAreaId,
        ];
        $fallbackDescription = '';
        foreach ($areaPaths as $apath) {
            $area = $this->fetchPlatformEntity($platform, $apath);
            if (!is_array($area)) {
                continue;
            }
            $ad = $this->scalarStringFromRecord($area, ['Description', 'description'])
                ?? $this->stringFieldFromRecord($area, ['Description', 'description']);
            if ($ad !== NULL && $ad !== '') {
                $fallbackDescription = $ad;
            }
            $fid = $area['FloorId'] ?? $area['floorId'] ?? NULL;
            if (!is_numeric($fid) || (int) $fid <= 0) {
                continue;
            }
            $fl = $this->fetchPlatformEntity($platform, '/v2/Floors/' . (int) $fid . '?columns=Description,Name,description,name');
            if (!is_array($fl)) {
                continue;
            }
            $d = $this->scalarStringFromRecord($fl, ['Description', 'description'])
                ?? $this->stringFieldFromRecord($fl, ['Description', 'description']);
            if ($d !== NULL && $d !== '') {
                return $this->normalizeDoublePrefixedLocationCode($d);
            }
            $n = $this->scalarStringFromRecord($fl, ['Name', 'name'])
                ?? $this->stringFieldFromRecord($fl, ['Name', 'name']);
            if ($n !== NULL && $n !== '') {
                return $this->normalizeDoublePrefixedLocationCode($n);
            }
        }

        return $fallbackDescription !== '' ? $this->normalizeDoublePrefixedLocationCode($fallbackDescription) : '';
    }

    /**
     * Resolve a taxonomy term's TMA id by term name.
     */
    private function lookupTermTmaIdByName(string $vocab, string $fieldMachineName, string $termName): ?int {
        try {
            $storage = \Drupal::entityTypeManager()->getStorage('taxonomy_term');
            $tids = $storage->getQuery()
                ->accessCheck(FALSE)
                ->condition('vid', $vocab)
                ->condition('name', $termName)
                ->range(0, 1)
                ->execute();
            if (!$tids) {
                return NULL;
            }
            $term = $storage->load((int) reset($tids));
            if (!$term || !$term->hasField($fieldMachineName)) {
                return NULL;
            }
            $val = $term->get($fieldMachineName)->value ?? NULL;
            $id = is_numeric($val) ? (int) $val : NULL;
            return ($id && $id > 0) ? $id : NULL;
        }
        catch (\Throwable) {
            return NULL;
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function decodePlatformListRows(mixed $resp): array {
        if (!$resp instanceof ResponseInterface || $resp->getStatusCode() !== 200) {
            // If debugging is on, log non-200 bodies to help diagnose filters.
            if ($resp instanceof ResponseInterface) {
                $this->debugLog('v7.platform_list_error', [
                    'status' => $resp->getStatusCode(),
                    'body' => $this->sanitizeForLog($this->truncate($this->readResponseBodyWithoutConsuming($resp), 2000)),
                ]);
            }
            return [];
        }
        $raw = $this->readResponseBodyWithoutConsuming($resp);
        $decoded = json_decode($raw, TRUE);
        if (!is_array($decoded)) {
            return [];
        }
        if (array_is_list($decoded)) {
            return $decoded;
        }
        $data = $decoded['Data'] ?? $decoded['data'] ?? [];
        return is_array($data) ? $data : [];
    }

    private function debugEnabled(): bool {
        return (bool) $this->config->get('debug_api_logging');
    }

    /**
     * @param array<string, mixed> $context
     */
    private function debugLog(string $event, array $context): void {
        if (!$this->debugEnabled()) {
            return;
        }
        try {
            \Drupal::logger('ucb_tma_interface')->notice('TMA submit debug: @event @json', [
                '@event' => $event,
                '@json' => json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ]);
        }
        catch (\Throwable) {
            // Never fail submission due to logging.
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function formatResponseForLog(mixed $resp): array {
        if ($resp instanceof ResponseInterface) {
            $body = $this->readResponseBodyWithoutConsuming($resp);
            return [
                'type' => 'http',
                'status' => $resp->getStatusCode(),
                'body' => $this->sanitizeForLog(json_decode($body, TRUE) ?: ['raw' => $this->truncate($body)]),
            ];
        }
        if (is_array($resp)) {
            return [
                'type' => 'drupal_markup_array',
                'body' => $this->sanitizeForLog($resp),
            ];
        }
        return [
            'type' => gettype($resp),
            'body' => $this->truncate((string) $resp),
        ];
    }

    private function readResponseBodyWithoutConsuming(ResponseInterface $resp): string {
        try {
            $stream = $resp->getBody();
            $contents = (string) $stream;
            if (method_exists($stream, 'isSeekable') && $stream->isSeekable()) {
                $stream->rewind();
            }
            return $contents;
        }
        catch (\Throwable) {
            return '';
        }
    }

    /**
     * Redact secrets and shrink nested payloads for logs.
     *
     * @param mixed $value
     * @return mixed
     */
    private function sanitizeForLog(mixed $value): mixed {
        if (is_array($value)) {
            $out = [];
            foreach ($value as $k => $v) {
                $key = is_string($k) ? $k : (string) $k;
                $lower = strtolower($key);
                if (str_contains($lower, 'password') || str_contains($lower, 'authorization') || str_contains($lower, 'token')) {
                    $out[$key] = '[REDACTED]';
                    continue;
                }
                $out[$key] = $this->sanitizeForLog($v);
            }
            return $out;
        }
        if (is_string($value)) {
            return $this->truncate($value);
        }
        return $value;
    }

    private function truncate(string $s, int $max = 5000): string {
        $s = (string) $s;
        if (strlen($s) <= $max) {
            return $s;
        }
        return substr($s, 0, $max) . '…[truncated]';
    }

}