<?php
/**
 * Created by PhpStorm.
 * User: hdsstudent
 * Date: 10/19/18
 * Time: 2:27 PM
 */

namespace Drupal\ucb_tma_interface\FixitRequest;

/**
 * Class FixitRequestFactory
 * @package Drupal\tma_interface\FixitRequest
 *
 * The request factory is in charge of creating the different types of requests
 * needed for different situations. Currently their is only one, but more may be
 * added in the future.
 */
class FixitRequestFactory {

    private const STATE_NEW = 1;
    private const ID_NEW = -1;
    private const CLIENT_NAME = "ucb";
    private const REQUEST_TYPE = "Fix It!";
    private const REQUEST_ID = "FIXIT";

    /**
     * @param $requestorName
     * @param $requestorPhone
     * @param $requestorEmail
     * @param $description
     * @param $facility
     * @param $building
     * @param $floor
     * @param $area
     * @return FixitRequest
     *
     * Returns a fixit request for first submission with all the required fields set.
     *
     * Notice: Only required for submission fields are set in this method.
     */
    public static function newFixitRequest(
        $requestorName,
        $requestorPhone,
        $requestorEmail,
        $description,
        $facility,
        $building,
        $area,
        $floor,
        $taskCode,
        $repairCenterCode
    ): FixitRequest {
        $fixitRequest = new FixitRequest();
        $fixitRequest->setId(self::ID_NEW);
        $fixitRequest->setState(self::STATE_NEW);
        $fixitRequest->setClientName(self::CLIENT_NAME);
        $fixitRequest->setRequestType(self::REQUEST_TYPE);
        $fixitRequest->setRequestorId(self::REQUEST_ID);

        $fixitRequest->setRequestorName($requestorName);
        $fixitRequest->setRequestorPhone($requestorPhone);
        $fixitRequest->setRequestorEmail($requestorEmail);
        $fixitRequest->setDescription($description);
        $fixitRequest->setFacility($facility);
        $fixitRequest->setBuilding($building);
        $fixitRequest->setArea($area);
        $fixitRequest->setFloor($floor);
        $fixitRequest->setTaskCode($taskCode);
        $fixitRequest->setRepairCenterCode($repairCenterCode);

        return $fixitRequest;
    }

}