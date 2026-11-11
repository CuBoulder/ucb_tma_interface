<?php

namespace Drupal\ucb_tma_interface\InterfaceController;

use Drupal\ucb_tma_interface\ApiConnector\PlatformConnector;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Creates one UMC maintenance request from the browser script
 *
 * Uses the module's existing Platform login
 */
class UmcRequestController {

  /**
   * POST /tma/umc-request
   */
  public function submit(Request $request): JsonResponse {
    $data = json_decode($request->getContent(), TRUE);
    if (!is_array($data)) {
      return new JsonResponse(['error' => 'The request could not be read.'], 400);
    }

    $name = trim((string) ($data['name'] ?? ''));
    $email = trim((string) ($data['email'] ?? ''));
    $detail = trim((string) ($data['detail'] ?? ''));
    $department = trim((string) ($data['department'] ?? ''));
    $room = $data['room'] ?? '';
    $areaId = is_numeric($room) ? (int) $room : 0;
    if ($name === '' || $email === '' || $detail === '' || $areaId <= 0) {
      return new JsonResponse(['error' => 'Name, email, room, and request detail are required.'], 400);
    }

    $platform = \Drupal::service('ucb_tma_interface.platform_connector');
    if (!$platform instanceof PlatformConnector) {
      return new JsonResponse(['error' => 'Platform is unavailable.'], 500);
    }

    $payload = [
      'ObjectState' => '0',
      'RequestDate' => gmdate('Y-m-d\TH:i:sP'),
      'ActionRequested' => $department !== '' ? "Department: {$department}\n\n{$detail}" : $detail,
      'NumberOfEstimate' => '0',
      'Active' => FALSE,
      'NotifyMe' => TRUE,
      'Authorized' => TRUE,
      'RoutingStatus' => '0',
      'RequestorName' => $name,
      'RequestorPhone' => trim((string) ($data['phone'] ?? '')),
      'RequestorEmail' => $email,
      'FacilityId' => '15873',
      'isPending' => TRUE,
      'BuildingId' => '3740194',
      'RequestTypeId' => '3194096',
      'AreaId' => $areaId,
      'LocationId' => $areaId,
      'RepairCenterId' => '1000',
    ];

    $resp = $platform->postJson('/v2/Requests', $payload);
    if (!$resp instanceof ResponseInterface) {
      return new JsonResponse(['error' => 'Platform did not accept the login.'], 502);
    }
    $status = $resp->getStatusCode();
    $decoded = json_decode((string) $resp->getBody(), TRUE);
    if ($status < 200 || $status >= 300 || !is_array($decoded)) {
      \Drupal::logger('ucb_tma_interface')->error('UMC request was not created. Platform status @status.', [
        '@status' => $status,
      ]);
      return new JsonResponse(['error' => 'Platform did not create the request.'], 502);
    }
    $number = trim((string) ($decoded['Number'] ?? $decoded['number'] ?? ''));
    if ($number === '') {
      return new JsonResponse(['error' => 'Platform did not return a request number.'], 502);
    }
    return new JsonResponse(['number' => $number]);
  }

}
