<?php
/**
 * Created by PhpStorm.
 * User: hdsstudent
 * Date: 10/22/18
 * Time: 2:31 PM
 */

namespace Drupal\ucb_tma_interface\FixitRequest;

/**
 * Class FixitRequestHandler
 * @package Drupal\tma_interface\FixitRequest
 *
 * The request handler is in charge of all logic directly dealing with the creation and
 * manipulation of fixit request objects.
 *
 */
class FixitRequestHandler {

    /**
     * Map from our in house array values to TMA's request values.
     * Primarily used for easy maintainability in case TMA ever
     * changes their values.
     */
    private const TMA_KEYS = [
        "data_set" => "NewDataSet",
        "requests" => "i_WebTMA_Requests",
        "id" => "ILOG_PK",
        "number" => "ILOG_NUMBER",
        "requestor_name" => "ILOG_REQUESTOR",
        "date_requested" => "ILOG_REQ_DATE",
        "requestor_phone" => "ILOG_REQ_PHONE",
        "requestor_email" => "ILOG_REQ_EMAIL",
        "description" => "ILOG_REQUEST",
        "date_created" => "ILOG_CREATED_DATE",
        "client_name" => "ILOG_CLIENT_NAME",
        "send_email" => "ILOG_EMAIL",
        "requestor_id" => "ILOG_RQ_ID",
        "processed_status" => "ILOG_PROCESSED",
        "processed_error_message" => "ILOG_PROCESSED_ERROR",
        "repair_center_code" => "ILOG_RC_CODE",
        "request_type" => "ILOG_REQ_TYPE",
        "facility" => "ILOG_FACILITY",
        "building" => "ILOG_BUILDING",
        "floor" => "ILOG_FLOOR",
        "area" => "ILOG_AREA",
        "reference_number" => "ILOG_REF",
        "request_status" => "ILOG_STATUS",
        "department" => "ILOG_department",
        "taskCode" => "ILOG_TASKCODE",
        "accountCode" => "ILOG_ACCOUNTCODE",
        "state" => "state"
    ];

    /**
     * Map from our in house values to the Webform module's submission values.
     * Primarily used for easy maintainability in case the Webform
     * module ever changes keys or we change these keys.
     */
    private const FORM_KEYS = [
        "contact" => "user_contact",
        "requestor_name" => "name",
        "requestor_phone" => "phone",
        "requestor_email" => "email",
        "description" => "input_information_related_to_the_issue",
        "facility" => "facility",
        "building" => "building",
        "area" => "area",
        "floor" => "floor",
        "taskCode" => "task_select",
        "repair_center_code" => "repair_center"
    ];

    private $fixitRequest;

    public function __construct($request) {
        $this->fixitRequest = FixitRequestFactory::newFixitRequest(
            $request[self::FORM_KEYS["contact"]][self::FORM_KEYS["requestor_name"]],
            $request[self::FORM_KEYS["contact"]][self::FORM_KEYS["requestor_phone"]],
            $request[self::FORM_KEYS["contact"]][self::FORM_KEYS["requestor_email"]],
            $request[self::FORM_KEYS["description"]],
            $request[self::FORM_KEYS["facility"]],
            $request[self::FORM_KEYS["building"]],
            $request[self::FORM_KEYS["area"]],
            $request[self::FORM_KEYS["floor"]],
            $request[self::FORM_KEYS["taskCode"]],
            $request[self::FORM_KEYS["repair_center_code"]]
        );
    }

    /**
     * @param $request
     * @param $update
     */
    public function updateFixitRequest($request, $update): void {

        $request = $this->convertIncomingRequest($request);

        foreach($update as $uKey => $uVal) {
            $request[self::TMA_KEYS[$uKey]] = $uVal;
        }

        $this->setValues($request);
        $this->fixitRequest->setState(2);
    }

    /**
     * @return string
     *
     * This sets and encodes the fixit request to json for submission into TMA.
     *
     */
    public function formatRequest(): string {
        return json_encode([
            self::TMA_KEYS["data_set"] => [
                self::TMA_KEYS["requests"] => [
                    [
                        self::TMA_KEYS["id"] => $this->fixitRequest->getId(),
                        self::TMA_KEYS["number"] => $this->fixitRequest->getNumber(),
                        self::TMA_KEYS["requestor_name"] => $this->fixitRequest->getRequestorName(),
                        self::TMA_KEYS["date_requested"] => $this->fixitRequest->getDateRequested(),
                        self::TMA_KEYS["requestor_phone"] => $this->fixitRequest->getRequestorPhone(),
                        self::TMA_KEYS["requestor_email"] => $this->fixitRequest->getRequestorEmail(),
                        self::TMA_KEYS["description"] => $this->fixitRequest->getDescription(),
                        self::TMA_KEYS["date_created"] => $this->fixitRequest->getDateCreated(),
                        self::TMA_KEYS["client_name"] => $this->fixitRequest->getClientName(),
                        self::TMA_KEYS["send_email"] => $this->fixitRequest->getSendEmail(),
                        self::TMA_KEYS["requestor_id"] => $this->fixitRequest->getRequestorId(),
                        self::TMA_KEYS["processed_status"] => $this->fixitRequest->getProcessedStatus(),
                        self::TMA_KEYS["processed_error_message"] => $this->fixitRequest->getProcessedErrorMessage(),
                        self::TMA_KEYS["repair_center_code"] => $this->fixitRequest->getRepairCenterCode(),
                        self::TMA_KEYS["request_type"] => $this->fixitRequest->getRequestType(),
                        self::TMA_KEYS["facility"] => $this->fixitRequest->getFacility(),
                        self::TMA_KEYS["building"] => $this->fixitRequest->getBuilding(),
                        self::TMA_KEYS["floor"] => $this->fixitRequest->getFloor(),
                        self::TMA_KEYS["area"] => $this->fixitRequest->getArea(),
                        self::TMA_KEYS["reference_number"] => $this->fixitRequest->getReferenceNumber(),
                        self::TMA_KEYS["request_status"] => $this->fixitRequest->getRequestStatus(),
                        self::TMA_KEYS["department"] => $this->fixitRequest->getDepartment(),
                        self::TMA_KEYS["taskCode"] => $this->fixitRequest->getTaskCode(),
                        self::TMA_KEYS["accountCode"] => $this->fixitRequest->getAccountCode(),
                        self::TMA_KEYS["state"] => $this->fixitRequest->getState()
                    ]
                ]
            ]
        ]);
    }

    private function convertIncomingRequest($request) {
        return json_decode($request, true)[self::TMA_KEYS["data_set"]][self::TMA_KEYS["requests"]][0];
    }

    private function setValues($values) {
        $this->fixitRequest->setId($values[self::TMA_KEYS["id"]]);
        $this->fixitRequest->setNumber($values[self::TMA_KEYS["number"]]);
        $this->fixitRequest->setRequestorName($values[self::TMA_KEYS["requestor_name"]]);
        $this->fixitRequest->setDateRequested($values[self::TMA_KEYS["date_requested"]]);
        $this->fixitRequest->setRequestorPhone($values[self::TMA_KEYS["requestor_phone"]]);
        $this->fixitRequest->setRequestorEmail($values[self::TMA_KEYS["requestor_email"]]);
        $this->fixitRequest->setDescription($values[self::TMA_KEYS["description"]]);
        $this->fixitRequest->setDateCreated($values[self::TMA_KEYS["date_created"]]);
        $this->fixitRequest->setClientName($values[self::TMA_KEYS["client_name"]]);
        $this->fixitRequest->setSendEmail($values[self::TMA_KEYS["send_email"]]);
        $this->fixitRequest->setRequestorId($values[self::TMA_KEYS["requestor_id"]]);
        $this->fixitRequest->setProcessedStatus($values[self::TMA_KEYS["processed_status"]]);
        $this->fixitRequest->setProcessedErrorMessage($values[self::TMA_KEYS["processed_error_message"]]);
        $this->fixitRequest->setRepairCenterCode($values[self::TMA_KEYS["repair_center_code"]]);
        $this->fixitRequest->setRequestType($values[self::TMA_KEYS["request_type"]]);
        $this->fixitRequest->setFacility($values[self::TMA_KEYS["facility"]]);
        $this->fixitRequest->setBuilding($values[self::TMA_KEYS["building"]]);
        $this->fixitRequest->setFloor($values[self::TMA_KEYS["floor"]]);
        $this->fixitRequest->setArea($values[self::TMA_KEYS["area"]]);
        $this->fixitRequest->setReferenceNumber($values[self::TMA_KEYS["reference_number"]]);
        $this->fixitRequest->setRequestStatus($values[self::TMA_KEYS["request_status"]]);
        $this->fixitRequest->setDepartment($values[self::TMA_KEYS["department"]]);
        $this->fixitRequest->setTaskCode($values[self::TMA_KEYS["taskCode"]]);
        $this->fixitRequest->setAccountCode($values[self::TMA_KEYS["accountCode"]]);
    }

}