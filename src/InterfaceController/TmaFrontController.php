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
        $locations = [];
        $raw_data = json_decode($this->connector->getResponse($this->config->get('locations_url') . ($type == "Area" ? "simpleArea" : $type), self::TMA_KEYS[strtolower($type) . "_active"], 1)->getBody(), true)[self::TMA_KEYS["data"]][self::TMA_KEYS[strtolower($type)]];

        if(strtolower($type) == "area") {
            if($facilityName) {
                foreach ($raw_data as $loc) {
                    if ($loc[self::TMA_KEYS["area_active"]] == "True" && $loc[self::TMA_KEYS["facility_name"]] == $facilityName) { // && !$loc[self::TMA_KEYS["area_exclude"]]) {
                        $locations[] = [
                            "pk" => $loc[self::TMA_KEYS["pk"]],
                            "name" => $loc[self::TMA_KEYS[strtolower($type) . "_name"]],
                            "connector" => $loc[self::TMA_KEYS[strtolower($type) . "_connector"]],
                            "description" => $loc[self::TMA_KEYS[strtolower($type) . "_description"]],
                            "floor_code" => $loc[self::TMA_KEYS["floor_code"]]
                        ];
                    }
                }
            } else {
                foreach ($raw_data as $loc) {
                    if ($loc[self::TMA_KEYS["area_active"]] == "True") { // && !$loc[self::TMA_KEYS["area_exclude"]]) {
                        $locations[] = [
                            "pk" => $loc[self::TMA_KEYS["pk"]],
                            "name" => $loc[self::TMA_KEYS[strtolower($type) . "_name"]],
                            "connector" => $loc[self::TMA_KEYS[strtolower($type) . "_connector"]],
                            "description" => $loc[self::TMA_KEYS[strtolower($type) . "_description"]],
                            "floor_code" => $loc[self::TMA_KEYS["floor_code"]]
                        ];
                    }
                }
            }
        } else if(strtolower($type) != "area"){
            foreach ($raw_data as $loc) {
                if ($loc[self::TMA_KEYS[strtolower($type) . "_active"]] == "True") {
                    $locations[] = [
                        "pk" => $loc[self::TMA_KEYS["pk"]],
                        "name" => $loc[self::TMA_KEYS[strtolower($type) . "_name"]],
                        "connector" => $loc[self::TMA_KEYS[strtolower($type) . "_connector"]]
                    ];
                }
            }
        }
        return $locations;
    }

}