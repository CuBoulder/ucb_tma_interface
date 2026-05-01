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
        $actionRequested = (string) ($reqData['input_information_related_to_the_issue'] ?? '');

        $facilityLabel = (string) ($reqData['facility'] ?? '');
        $areaLabel = (string) ($reqData['area'] ?? '');
        // Prefer the computed floor we actually submitted to Platform.
        $floor = (string) ($payload['floorNumber'] ?? ($reqData['floor'] ?? ''));
        if (trim($floor) === '' && $areaLabel !== '') {
            $floor = $this->lookupLegacyFloorByAreaName($areaLabel) ?? '';
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
                        // Prefer work order number when present; fall back to request number.
                        'ILOG_NUMBER' => (string) (($woNumber !== '') ? $woNumber : $requestNumber),
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
        $actionRequested = trim((string) ($request['input_information_related_to_the_issue'] ?? ''));

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

        $roomNumber = NULL;
        $areaFloorCode = NULL;
        $areaSubLocation = NULL;
        if (is_int($areaId)) {
            // For WorkRequest, "roomNumber" is the best available stand-in for legacy ILOG_AREA.
            // Prefer the Area's LocationCode (e.g. BRCKA-1101) over plain RoomNumber (e.g. 1101),
            // because this is what requestors/search commonly use in TMA.
            $areaRec = $this->fetchPlatformEntity($platform, '/v2/Areas/' . $areaId . '?columns=RoomNumber,LocationCode,FloorCode,Description');
            if (is_array($areaRec)) {
                $loc = $this->stringFieldFromRecord($areaRec, ['LocationCode', 'locationCode']);
                $rm = $this->stringFieldFromRecord($areaRec, ['RoomNumber', 'roomNumber']);
                $roomNumber = ($loc !== NULL && $loc !== '') ? $loc : $rm;
                $areaFloorCode = $this->stringFieldFromRecord($areaRec, ['FloorCode', 'floorCode']);
                $areaSubLocation = $this->stringFieldFromRecord($areaRec, ['Description', 'description']);
            }
            if ($roomNumber === NULL || $roomNumber === '') {
                // Fallback to whatever label came from the webform.
                $roomNumber = $areaName;
            }
        }

        $repairCenterCode = $repairCenterField !== '' ? $repairCenterField : NULL;
        if ($repairCenterCode === NULL) {
            $task = $taskCode !== '' ? $this->lookupTaskByCode($platform, $taskCode) : NULL;
            $repairCenterId = is_array($task) ? $this->extractTaskRepairCenterId($task) : NULL;
            if (!is_int($repairCenterId) && is_int($areaId)) {
                $repairCenterId = $this->lookupAreaRepairCenterId($platform, $areaId);
            }
            if (is_int($repairCenterId)) {
                $repairCenterCode = $this->fetchRepairCenterCode($platform, $repairCenterId);
            }
        }

        $requestTypeCode = trim((string) ($this->config->get('platform_request_type_code') ?: 'WEB'));
        if ($requestTypeCode === '') {
            $requestTypeCode = 'WEB';
        }

        $this->debugLog('v7.resolved_ids', [
            'taskCode' => $taskCode,
            'requestTypeCode' => $requestTypeCode,
            'repairCenterField' => $repairCenterField,
            'repairCenterCode' => $repairCenterCode,
            'facilityName' => $facilityName,
            'facilityTmaId' => $facilityTmaId,
            'facilityCode' => $facilityCode,
            'buildingRaw' => $buildingRaw,
            'buildingId' => $buildingId,
            'buildingCode' => $buildingCode,
            'areaName' => $areaName,
            'areaId' => $areaId,
            'roomNumber' => $roomNumber,
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
        ];
        if ($taskCode !== '') {
            $payload['taskCode'] = $taskCode;
        }
        if ($repairCenterCode !== NULL && $repairCenterCode !== '') {
            $payload['repairCenterCode'] = $repairCenterCode;
        }
        if ($roomNumber !== NULL && $roomNumber !== '') {
            $payload['roomNumber'] = $roomNumber;
        }
        // Prefer legacy-derived floor (Drupal taxonomy) for parity with v5 submissions.
        // Platform Areas often have FloorCode/FloorId null (e.g. housing), so relying on Platform alone loses floor.
        $floor = trim((string) ($request['floor'] ?? ''));
        if ($floor === '' && $areaName !== '') {
            $floor = $this->lookupLegacyFloorByAreaName($areaName) ?? '';
        }
        $floorNumber = NULL;
        if ($floor !== '') {
            $floorNumber = $floor;
        }
        if ($floorNumber !== NULL && $floorNumber !== '') {
            $payload['floorNumber'] = $floorNumber;
        }
        // Best-effort: carry area description as subLocation (helps match legacy "area" semantics in v7).
        if (is_string($areaSubLocation) && trim($areaSubLocation) !== '') {
            $payload['subLocation'] = trim($areaSubLocation);
        }
        $extra = [];
        if ($requestorName !== '') {
            $extra[] = 'Requestor: ' . $requestorName;
        }
        if ($requestorPhone !== '') {
            $extra[] = 'Phone: ' . $requestorPhone;
        }
        if ($extra !== []) {
            $payload['comments'] = implode(' | ', $extra);
        }

        return $payload;
    }

    /**
     * Legacy parity: resolve floor code from the Drupal taxonomy term for the selected area.
     *
     * The legacy webform handler stored the floor value on submit by looking up
     * taxonomy_term__field_floor via the area's term name.
     */
    private function lookupLegacyFloorByAreaName(string $areaName): ?string {
        $areaName = trim($areaName);
        if ($areaName === '') {
            return NULL;
        }
        try {
            $query = \Drupal::database()->select('taxonomy_term_field_data', 'd');
            $query->leftJoin('taxonomy_term__field_floor', 'f', 'd.tid = f.entity_id');
            $query->addField('f', 'field_floor_value');
            $query->condition('d.name', $areaName);
            // Ensure we only match the Area vocabulary (names may be duplicated across vocabs).
            $query->condition('d.vid', 'area');
            $query->range(0, 1);
            $val = $query->execute()->fetchField();
            $s = is_string($val) ? trim($val) : (is_numeric($val) ? (string) $val : '');
            $out = $s !== '' ? $s : NULL;
            if ($this->debugEnabled()) {
                $this->debugLog('v7.floor_lookup', [
                    'areaName' => $areaName,
                    'result' => $out,
                ]);
            }
            return $out;
        }
        catch (\Throwable) {
            return NULL;
        }
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

    private function fetchFacilityCode(PlatformConnector $platform, int $facilityId): ?string {
        $rec = $this->fetchPlatformEntity($platform, '/v2/Facilities/' . $facilityId . '?columns=Code');
        return $this->stringFieldFromRecord($rec, ['Code', 'code']);
    }

    private function fetchBuildingCode(PlatformConnector $platform, int $buildingId): ?string {
        $rec = $this->fetchPlatformEntity($platform, '/v2/Buildings/' . $buildingId . '?columns=Code');
        return $this->stringFieldFromRecord($rec, ['Code', 'code']);
    }

    private function fetchRepairCenterCode(PlatformConnector $platform, int $repairCenterId): ?string {
        $rec = $this->fetchPlatformEntity($platform, '/v2/RepairCenters/' . $repairCenterId . '?columns=Code');
        return $this->stringFieldFromRecord($rec, ['Code', 'code']);
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
     * Lookup Task by Code using Platform API.
     *
     * @return array<string, mixed>|null
     */
    private function lookupTaskByCode(PlatformConnector $platform, string $taskCode): ?array {
        // Platform list endpoints may return either a bare list or {Data: [...], TotalCount: N}.
        $filter = rawurlencode("Code eq '" . str_replace("'", "''", $taskCode) . "'");
        // RepairCenterLinks is not a valid list column in some TMA instances; PreferredRepairCenterId is.
        $resp = $platform->get('/v2/Tasks?filter=' . $filter . '&pageSize=1&columns=Id,Code,PreferredRepairCenterId');
        $rows = $this->decodePlatformListRows($resp);
        if (isset($rows[0]) && is_array($rows[0])) {
            return $rows[0];
        }

        // Case-insensitive fallback.
        $filter2 = rawurlencode("tolower(Code) eq '" . str_replace("'", "''", strtolower($taskCode)) . "'");
        $resp2 = $platform->get('/v2/Tasks?filter=' . $filter2 . '&pageSize=1&columns=Id,Code,PreferredRepairCenterId');
        $rows2 = $this->decodePlatformListRows($resp2);
        if (isset($rows2[0]) && is_array($rows2[0])) {
            return $rows2[0];
        }
        return NULL;
    }

    private function extractTaskRepairCenterId(array $task): ?int {
        $pref = $task['PreferredRepairCenterId'] ?? $task['preferredRepairCenterId'] ?? NULL;
        if (is_numeric($pref)) {
            return (int) $pref;
        }
        $links = $task['repairCenterLinks'] ?? $task['RepairCenterLinks'] ?? NULL;
        if (!is_array($links) || $links === []) {
            return NULL;
        }
        // Prefer preferred link when present, else first.
        $preferred = NULL;
        foreach ($links as $link) {
            if (is_array($link) && (($link['preferred'] ?? FALSE) === TRUE || ($link['Preferred'] ?? FALSE) === TRUE)) {
                $preferred = $link;
                break;
            }
        }
        $link = $preferred ?? (is_array($links[0] ?? NULL) ? $links[0] : NULL);
        if (!is_array($link)) {
            return NULL;
        }
        $id = $link['repairCenterId'] ?? $link['RepairCenterId'] ?? NULL;
        return is_numeric($id) ? (int) $id : NULL;
    }

    private function lookupAreaRepairCenterId(PlatformConnector $platform, int $areaId): ?int {
        $resp = $platform->get('/v2/Areas/' . $areaId . '?columns=Id,RepairCenterLinks,RepairCenterId');
        if (!method_exists($resp, 'getBody')) {
            return NULL;
        }
        $data = json_decode((string) $resp->getBody(), TRUE);
        if (!is_array($data)) {
            return NULL;
        }
        $rc = $data['RepairCenterId'] ?? $data['repairCenterId'] ?? NULL;
        if (is_numeric($rc)) {
            return (int) $rc;
        }
        $links = $data['RepairCenterLinks'] ?? $data['repairCenterLinks'] ?? NULL;
        if (!is_array($links) || $links === []) {
            return NULL;
        }
        $first = is_array($links[0] ?? NULL) ? $links[0] : NULL;
        if (!is_array($first)) {
            return NULL;
        }
        $id = $first['RepairCenterId'] ?? $first['repairCenterId'] ?? NULL;
        return is_numeric($id) ? (int) $id : NULL;
    }

    private function lookupRepairCenterIdByCode(PlatformConnector $platform, string $code): ?int {
        $filter = rawurlencode("Code eq '" . str_replace("'", "''", $code) . "'");
        $resp = $platform->get('/v2/RepairCenters?filter=' . $filter . '&pageSize=1&columns=Id,Code');
        $rows = $this->decodePlatformListRows($resp);
        $first = is_array($rows[0] ?? NULL) ? $rows[0] : NULL;
        if (!is_array($first)) {
            return NULL;
        }
        $id = $first['Id'] ?? $first['id'] ?? NULL;
        return is_numeric($id) ? (int) $id : NULL;
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