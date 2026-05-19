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
        // WorkRequest (code-based) matches legacy Fix It behavior; direct WorkOrders POST
        // often fails for area locations with server-side FK / nullable binding errors.
        $payload = $this->buildPlatformWorkRequestPayload($reqData, $platform);
        if ($payload === NULL) {
            return $this->buildPlatformSubmissionErrorResponse('Could not resolve TMA facility or building codes for WorkRequest.');
        }
        $this->debugLog('v7.before', [
            'incoming' => $this->sanitizeForLog($reqData),
            'payload' => $this->sanitizeForLog($payload),
        ]);
        $resp = $platform->postJson('/v2/WorkRequest', $payload);
        if ($resp instanceof ResponseInterface) {
            $this->patchRequestLogAfterSuccessfulWorkRequest($platform, $resp, $reqData, $payload);
            $this->patchWorkOrderNotifyMeAfterSuccessfulWorkRequest($platform, $resp);
        }
        $resp = $this->maybeWrapWorkRequestResponseAsLegacyIlog($platform, $reqData, $payload, $resp);
        $this->debugLog('v7.after', $this->formatResponseForLog($resp));
        return $resp;
    }

    /**
     * When Platform WorkRequest succeeds, return a legacy-shaped response body.
     *
     * This keeps downstream behavior (ticket_id extraction, confirmation pages, existing parsing)
     * stable without retaining any v5 Mobile/iRequest submission path.
     */
    private function maybeWrapWorkRequestResponseAsLegacyIlog(PlatformConnector $platform, array $reqData, array $payload, mixed $resp): mixed {
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

        $success = $decoded['Success'] ?? $decoded['success'] ?? NULL;
        if ($success !== TRUE) {
            return $resp;
        }

        $woNumber = (string) ($decoded['TMAWorkOrderNumber'] ?? $decoded['tmaWorkOrderNumber'] ?? '');
        $requestNumber = (string) ($decoded['TMARequestNumber'] ?? $decoded['tmaRequestNumber'] ?? '');

        $contact = is_array($reqData['user_contact'] ?? NULL) ? $reqData['user_contact'] : [];
        $requestorName = (string) ($contact['name'] ?? '');
        $requestorEmail = (string) ($contact['email'] ?? '');
        $requestorPhone = (string) ($contact['phone'] ?? '');
        $actionRequested = $this->issueDescriptionForWorkRequest((string) ($reqData['input_information_related_to_the_issue'] ?? ''));

        $facilityLabel = (string) ($reqData['facility'] ?? '');
        $areaLabel = (string) ($reqData['area'] ?? '');
        $resolvedAreaId = $areaLabel !== '' ? $this->lookupTermTmaIdByName('area', 'field_tma_area_id', $areaLabel) : NULL;
        $floor = trim((string) ($payload['floorNumber'] ?? ''));
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

        $repairCenterCode = (string) ($payload['repairCenterCode'] ?? '');
        $taskCode = (string) ($payload['taskCode'] ?? '');
        $requestType = 'Fix It!';

        $now = gmdate('c');
        $reqDate = (string) ($payload['requestDate'] ?? $now);

        $legacy = [
            'NewDataSet' => [
                'i_WebTMA_Requests' => [
                    [
                        'ILOG_PK' => '',
                        // Confirmation / ticket_id: request number for end users; work order if no request id.
                        'ILOG_NUMBER' => (string) (($requestNumber !== '') ? $requestNumber : $woNumber),
                        'ILOG_REQUESTOR' => (string) $requestorName,
                        'ILOG_REQ_DATE' => (string) $reqDate,
                        'ILOG_REQ_PHONE' => (string) $requestorPhone,
                        'ILOG_REQ_EMAIL' => (string) $requestorEmail,
                        'ILOG_REQUEST' => (string) $actionRequested,
                        'ILOG_CREATED_DATE' => (string) $reqDate,
                        'ILOG_CLIENT_NAME' => (string) $clientName,
                        'ILOG_EMAIL' => TRUE,
                        'ILOG_RQ_ID' => '',
                        'ILOG_PROCESSED' => 0,
                        'ILOG_PROCESSED_ERROR' => '',
                        'ILOG_RC_CODE' => (string) $repairCenterCode,
                        'ILOG_REQ_TYPE' => (string) $requestType,
                        'ILOG_FACILITY' => (string) $facilityLabel,
                        'ILOG_BUILDING' => (string) $buildingName,
                        'ILOG_FLOOR' => (string) $floor,
                        'ILOG_AREA' => (string) $areaLabel,
                        'ILOG_REF' => '',
                        'ILOG_STATUS' => '',
                        'ILOG_department' => '',
                        'ILOG_TASKCODE' => (string) $taskCode,
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
        $path = \Drupal::service('extension.list.module')->getPath('ucb_tma_interface');
        $yamlPath = $path . '/data/fixit_tasks.yml';
        try {
            $raw = is_file($yamlPath) ? file_get_contents($yamlPath) : '';
            $rows = is_string($raw) && $raw !== '' ? Yaml::decode($raw) : [];
        }
        catch (\Throwable) {
            $rows = [];
        }

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
     * Build a v7 Platform API WorkRequest payload (code-based; matches Fix It / RequestTypes).
     *
     * @param array<string, mixed> $request
     *
     * @return array<string, mixed>|null Null when facility/building codes cannot be resolved.
     */
    private function buildPlatformWorkRequestPayload(array $request, PlatformConnector $platform): ?array {
        $contact = is_array($request['user_contact'] ?? NULL) ? $request['user_contact'] : [];
        $requestorName = trim((string) ($contact['name'] ?? ''));
        $requestorEmail = trim((string) ($contact['email'] ?? ''));
        $requestorPhone = trim((string) ($contact['phone'] ?? ''));
        $actionRequested = $this->issueDescriptionForWorkRequest(trim((string) ($request['input_information_related_to_the_issue'] ?? '')));

        $taskCode = trim((string) ($request['task_select'] ?? ''));
        $repairCenterField = trim((string) ($request['repair_center'] ?? ''));

        $facilityName = trim((string) ($request['facility'] ?? ''));
        $buildingRaw = trim((string) ($request['building'] ?? ''));
        $areaName = trim((string) ($request['area'] ?? ''));

        $facilityTmaId = $facilityName !== '' ? $this->lookupTermTmaIdByName('facility', 'field_tma_facility_id', $facilityName) : NULL;

        $buildingId = is_numeric($buildingRaw) ? (int) $buildingRaw : NULL;
        if (!is_int($buildingId) && $buildingRaw !== '') {
            $buildingId = $this->lookupTermTmaIdByName('building', 'field_tma_building_id_', $buildingRaw);
        }

        $areaId = $areaName !== '' ? $this->lookupTermTmaIdByName('area', 'field_tma_area_id', $areaName) : NULL;

        $facilityCode = is_int($facilityTmaId) ? $this->fetchFacilityCode($platform, $facilityTmaId) : NULL;
        $buildingCode = is_int($buildingId) ? $this->fetchBuildingCode($platform, $buildingId) : NULL;
        $facilityPlatformName = is_int($facilityTmaId) ? $this->fetchFacilityDisplayName($platform, $facilityTmaId) : NULL;
        $buildingPlatformName = is_int($buildingId) ? $this->fetchBuildingDisplayName($platform, $buildingId) : NULL;

        $repairCenter = $this->resolveRepairCenterForWorkRequest($platform, $request, $taskCode);
        $repairCenterCode = $repairCenter['code'] ?? NULL;
        $repairCenterName = $repairCenter['name'] ?? NULL;

        $requestTypeCode = trim((string) ($this->config->get('platform_request_type_code') ?: 'WEB'));
        if ($requestTypeCode === '') {
            $requestTypeCode = 'WEB';
        }

        $floorStr = $this->resolveWorkRequestFloorNumber($request, $areaName, is_int($areaId) ? $areaId : NULL);
        $roomNumber = $this->resolveWorkRequestRoomNumber($platform, $areaName, is_int($areaId) ? $areaId : NULL);
        $subLocationResolved = $this->resolveWorkRequestSubLocation($platform, is_int($areaId) ? $areaId : NULL);

        $this->debugLog('v7.resolved_ids', [
            'taskCode' => $taskCode,
            'requestTypeCode' => $requestTypeCode,
            'repairCenterField' => $repairCenterField,
            'repairCenterCode' => $repairCenterCode,
            'repairCenterName' => $repairCenterName,
            'facilityName' => $facilityName,
            'facilityTmaId' => $facilityTmaId,
            'facilityCode' => $facilityCode,
            'buildingRaw' => $buildingRaw,
            'buildingId' => $buildingId,
            'buildingCode' => $buildingCode,
            'areaName' => $areaName,
            'areaId' => $areaId,
            'workRequestFloorNumber' => $floorStr,
            'workRequestRoomNumber' => $roomNumber,
            'facilityPlatformName' => $facilityPlatformName,
            'buildingPlatformName' => $buildingPlatformName,
            'workRequestSubLocation' => $subLocationResolved,
            'notifyMe' => TRUE,
        ]);

        if ($facilityCode === NULL || $facilityCode === '' || $buildingCode === NULL || $buildingCode === '') {
            return NULL;
        }

        $payload = [
            'externalID' => uniqid('fixit_', TRUE),
            'requestTypeCode' => $requestTypeCode,
            'facilityCode' => $facilityCode,
            'buildingCode' => $buildingCode,
            'requestDate' => gmdate('c'),
            'actionRequested' => $actionRequested,
            'requestorEmail' => $requestorEmail,
            'requestorName' => $requestorName,
            'requestorPhoneNumber' => $requestorPhone,
            // WorkRequestCreate omits this in swagger; WorkOrder exposes notifyMe — we also PATCH WO after create.
            'notifyMe' => TRUE,
        ];
        if ($taskCode !== '') {
            $payload['taskCode'] = $taskCode;
        }
        if ($repairCenterCode !== NULL && $repairCenterCode !== '') {
            $payload['repairCenterCode'] = $repairCenterCode;
        }
        if (is_string($repairCenterName) && $repairCenterName !== '') {
            $payload['repairCenterName'] = $repairCenterName;
        }
        if ($floorStr !== '') {
            $payload['floorNumber'] = $floorStr;
        }
        if ($roomNumber !== '') {
            $payload['roomNumber'] = $roomNumber;
        }
        if (is_string($facilityPlatformName) && $facilityPlatformName !== '') {
            $payload['facilityName'] = $facilityPlatformName;
        }
        if (is_string($buildingPlatformName) && $buildingPlatformName !== '') {
            $payload['buildingName'] = $buildingPlatformName;
        }
        if ($subLocationResolved !== '') {
            $payload['subLocation'] = $subLocationResolved;
        }

        return $payload;
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

    /**
     * WorkRequest POST maps loosely to RequestLog; PATCH /v2/Requests/{id} for UI fields (swagger RequestLog).
     *
     * Request Information uses floorCode, areaRoomNumber, notifyMe — not only WorkRequestCreate keys.
     *
     * @param array<string, mixed> $reqData
     * @param array<string, mixed> $workRequestPayload
     */
    private function patchRequestLogAfterSuccessfulWorkRequest(PlatformConnector $platform, ResponseInterface $resp, array $reqData, array $workRequestPayload): void {
        $raw = $this->readResponseBodyWithoutConsuming($resp);
        $decoded = json_decode($raw, TRUE);
        if (!is_array($decoded)) {
            return;
        }
        $success = $decoded['Success'] ?? $decoded['success'] ?? NULL;
        if ($success !== TRUE) {
            return;
        }
        $requestNumber = trim((string) ($decoded['TMARequestNumber'] ?? $decoded['tmaRequestNumber'] ?? ''));
        if ($requestNumber === '') {
            $this->debugLog('v7.request_log_patch_skipped', ['reason' => 'no_request_number']);
            return;
        }
        $requestLogId = $this->lookupRequestLogIdByNumber($platform, $requestNumber, 3);
        if (!is_int($requestLogId) || $requestLogId <= 0) {
            $this->debugLog('v7.request_log_patch_skipped', [
                'requestNumber' => $requestNumber,
                'reason' => 'request_log_id_not_found',
            ]);
            return;
        }

        $areaName = trim((string) ($reqData['area'] ?? ''));
        $areaId = $areaName !== '' ? $this->lookupTermTmaIdByName('area', 'field_tma_area_id', $areaName) : NULL;
        $buildingRaw = trim((string) ($reqData['building'] ?? ''));
        $buildingId = is_numeric($buildingRaw) ? (int) $buildingRaw : NULL;
        if (!is_int($buildingId) && $buildingRaw !== '') {
            $buildingId = $this->lookupTermTmaIdByName('building', 'field_tma_building_id_', $buildingRaw);
        }
        $facilityName = trim((string) ($reqData['facility'] ?? ''));
        $facilityTmaId = $facilityName !== '' ? $this->lookupTermTmaIdByName('facility', 'field_tma_facility_id', $facilityName) : NULL;

        $floorCode = $this->resolveWorkRequestFloorNumber(
            $reqData,
            $areaName,
            is_int($areaId) && $areaId > 0 ? $areaId : NULL
        );
        if ($floorCode === '') {
            $floorCode = trim((string) ($workRequestPayload['floorNumber'] ?? ''));
        }
        $areaRoom = $this->resolveWorkRequestRoomNumber(
            $platform,
            $areaName,
            is_int($areaId) && $areaId > 0 ? $areaId : NULL
        );
        if ($areaRoom === '') {
            $areaRoom = trim((string) ($workRequestPayload['roomNumber'] ?? ''));
        }
        $taskCode = trim((string) ($reqData['task_select'] ?? ''));
        $repairCenter = $this->resolveRepairCenterForWorkRequest($platform, $reqData, $taskCode);
        $actionRequested = trim((string) ($workRequestPayload['actionRequested'] ?? ''));
        if ($actionRequested === '') {
            $actionRequested = $this->issueDescriptionForWorkRequest(
                trim((string) ($reqData['input_information_related_to_the_issue'] ?? ''))
            );
        }

        // PATCH /v2/Requests uses PascalCase property names (see swagger PUT/PATCH samples).
        $patch = [
            'NotifyMe' => TRUE,
        ];
        if ($actionRequested !== '') {
            $patch['ActionRequested'] = $actionRequested;
        }
        if ($floorCode !== '') {
            $patch['FloorCode'] = $floorCode;
        }
        if ($areaRoom !== '') {
            $patch['AreaRoomNumber'] = $areaRoom;
            $patch['AreaLocationCode'] = $areaRoom;
        }
        if (is_int($facilityTmaId) && $facilityTmaId > 0) {
            $patch['FacilityId'] = $facilityTmaId;
        }
        if (is_int($buildingId) && $buildingId > 0) {
            $patch['BuildingId'] = $buildingId;
        }
        if (is_int($areaId) && $areaId > 0) {
            $patch['AreaId'] = $areaId;
            $floorId = $this->lookupPlatformFloorIdForArea($platform, $areaId);
            if (is_int($floorId) && $floorId > 0) {
                $patch['FloorId'] = $floorId;
            }
        }
        $rcCode = $repairCenter['code'] ?? NULL;
        if (is_string($rcCode) && $rcCode !== '') {
            $patch['RepairCenterCode'] = $rcCode;
            $rcName = $repairCenter['name'] ?? NULL;
            if (is_string($rcName) && $rcName !== '') {
                $patch['RepairCenterName'] = $rcName;
            }
        }

        $patchResp = $platform->patchJson('/v2/Requests/' . $requestLogId, $patch);
        if ($patchResp instanceof ResponseInterface) {
            $ps = $patchResp->getStatusCode();
            if ($ps >= 200 && $ps < 300) {
                $this->debugLog('v7.request_log_patch', [
                    'requestLogId' => $requestLogId,
                    'requestNumber' => $requestNumber,
                    'status' => $ps,
                    'patch' => $this->sanitizeForLog($patch),
                ]);
            }
            else {
                $this->debugLog('v7.request_log_patch_error', [
                    'requestLogId' => $requestLogId,
                    'requestNumber' => $requestNumber,
                    'status' => $ps,
                    'body' => $this->sanitizeForLog($this->truncate($this->readResponseBodyWithoutConsuming($patchResp), 1500)),
                ]);
            }
        }
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
     * WorkRequest POST does not apply notifyMe on RequestLog; also set on WorkOrder when one exists.
     *
     * @see https://webtma.com/platformapi — PATCH /v2/WorkOrders/{id} partial update.
     */
    private function patchWorkOrderNotifyMeAfterSuccessfulWorkRequest(PlatformConnector $platform, ResponseInterface $resp): void {
        $raw = $this->readResponseBodyWithoutConsuming($resp);
        $decoded = json_decode($raw, TRUE);
        if (!is_array($decoded)) {
            return;
        }
        $success = $decoded['Success'] ?? $decoded['success'] ?? NULL;
        if ($success !== TRUE) {
            return;
        }
        $woNumber = trim((string) ($decoded['TMAWorkOrderNumber'] ?? $decoded['tmaWorkOrderNumber'] ?? ''));
        if ($woNumber === '') {
            return;
        }
        $escaped = str_replace("'", "''", $woNumber);
        $filterVariants = [
            "Number eq '" . $escaped . "'",
            "number eq '" . $escaped . "'",
            "tolower(Number) eq '" . strtolower($escaped) . "'",
            "tolower(number) eq '" . strtolower($escaped) . "'",
        ];
        foreach ($filterVariants as $expr) {
            $filter = rawurlencode($expr);
            $listResp = $platform->get('/v2/WorkOrders?filter=' . $filter . '&pageSize=1&columns=Id,Number');
            $rows = $this->decodePlatformListRows($listResp);
            $first = is_array($rows[0] ?? NULL) ? $rows[0] : NULL;
            $id = is_array($first) ? ($first['Id'] ?? $first['id'] ?? NULL) : NULL;
            if (!is_numeric($id) || (int) $id <= 0) {
                continue;
            }
            $patchResp = $platform->patchJson('/v2/WorkOrders/' . (int) $id, [
                'NotifyMe' => TRUE,
            ]);
            if ($patchResp instanceof ResponseInterface) {
                $ps = $patchResp->getStatusCode();
                if ($ps >= 200 && $ps < 300) {
                    $this->debugLog('v7.notify_me_patch', [
                        'workOrderId' => (int) $id,
                        'number' => $woNumber,
                        'status' => $ps,
                    ]);
                }
                else {
                    $this->debugLog('v7.notify_me_patch_error', [
                        'workOrderId' => (int) $id,
                        'number' => $woNumber,
                        'status' => $ps,
                        'body' => $this->sanitizeForLog($this->truncate($this->readResponseBodyWithoutConsuming($patchResp), 1500)),
                    ]);
                }
            }
            return;
        }
        $this->debugLog('v7.notify_me_patch_skipped', [
            'number' => $woNumber,
            'reason' => 'work_order_id_not_found',
        ]);
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
        $code = trim((string) ($request['repair_center'] ?? ''));
        if ($code === '' && $taskCode !== '' && $this->taskRepairCenterEnabledFromTaskCode($taskCode)) {
            $code = 'FS';
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
    private function taskRepairCenterEnabledFromTaskCode(string $taskCode): bool {
        $taskCode = trim($taskCode);
        if ($taskCode === '') {
            return FALSE;
        }
        try {
            $nids = \Drupal::entityTypeManager()->getStorage('node')->getQuery()
                ->accessCheck(FALSE)
                ->condition('type', 'task')
                ->condition('field_task_code', $taskCode)
                ->range(0, 1)
                ->execute();
            if (!$nids) {
                return FALSE;
            }
            $node = \Drupal::entityTypeManager()->getStorage('node')->load((int) reset($nids));
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