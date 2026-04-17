<?php
/**
 * Created by PhpStorm.
 * User: hdsstudent
 * Date: 10/22/18
 * Time: 2:13 PM
 */

namespace Drupal\ucb_tma_interface\InterfaceController;

use Drupal\ucb_tma_interface\ApiConnector\TmaConnector;
use Drupal\ucb_tma_interface\FixitRequest\FixitRequestHandler;
use function GuzzleHttp\Psr7\str;
use Symfony\Component\HttpFoundation\JsonResponse;

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

    // Key mappings for location data. Used for maintainability, maps the Web Teams names to the TMA Names.
    // This way we always call our own names and simply update the TMA names if they change.
    private const TMA_KEYS = [
        "data" => "NewDataSet",
        "pk" => "PK",
        "facility" => "f_facility",
        "facility_name" => "fd_name",
        "facility_connector" => "fd_code",
        "facility_active" => "fd_active",
        "building" => "f_building",
        "building_name" => "fb_name",
        "building_connector" => "fd_pk",
        "building_active" => "fb_active",
        "area" => "f_area",
        "area_name" => "fu_unitID",
        "area_connector" => "fu_fb_fk",
        "area_active" => "fu_active",
        "area_description" => "fu_description",
        "area_exclude" => "fu_excludeFromRequestor",
        "floor_code" => "ff_code"
    ];

    private $connector;
    private $config;

    public function __construct() {
        $this->connector = new TmaConnector();
        $this->config = \Drupal::config('ucb_tma_interface.settings');
    }

    /**
     * @return array|\Psr\Http\Message\ResponseInterface
     */
    public function submitFixitRequest($request) {
        $fixitHandler = new FixitRequestHandler($request);
        return $this->connector->sendRequest($this->config->get('request_url'), $fixitHandler->formatRequest());
    }

    /**
     * @return array|\Psr\Http\Message\ResponseInterface
     */
    public function getFixitRequest() {
        return $this->connector->getResponse($this->config->get('request_url'));
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

}