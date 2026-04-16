<?php
/**
 * Created by PhpStorm.
 * User: hdsstudent
 * Date: 10/17/18
 * Time: 1:08 PM
 */

namespace Drupal\ucb_tma_interface\FixitRequest;

/**
 * Class FixitRequest
 * @package Drupal\tma_interface\FixitRequest
 *
 * This is the physical representation of a webTMA request used on our Fixit site.
 * This class is used to represent and hold data for individual requests while our
 * system performs logic to set-up and submit the requests.
 *
 */
class FixitRequest {

    /**
     * @var int
     *
     * The primary key used in TMA's api.
     * Equivalent to ILOG_PK which is autopopulated by TMA.
     * Set this to -1 to indicate a new request on
     * submission into TMA.
     *
     * THIS IS SET IN THE FixitRequestFactory, DO NOT CHANGE VALUE IN CONSTRUCTOR.
     *
     * Required: YES
     *
     */
    private $id;

    /**
     * @var string
     *
     * The request ticket number used by TMA.
     * Equivalent to ILOG_NUMBER and is autopopulated by TMA.
     *
     * Required: NO
     *
     */
    private $number;

    /**
     * @var int
     *
     * The indication used by TMA to know what to do with
     * this request. Set to 1 for new requests.
     *
     * THIS IS SET IN THE FixitRequestFactory, DO NOT CHANGE VALUE IN CONSTRUCTOR.
     *
     * Equivalent to state.
     *
     * Required: YES
     *
     */
    private $state;

    /**
     * @var string
     *
     * Equivalent to ILOG_REQUESTOR.
     *
     * Required: YES
     *
     */
    private $requestorName;

    /**
     * @var string
     *
     * The ID of a fixit request. Should always be set to "FIXIT".
     *
     * THIS IS SET IN THE FixitRequestFactory, DO NOT CHANGE VALUE IN CONSTRUCTOR.
     *
     * Equivalent to ILOG_REQ_ID.
     *
     * Required: YES
     *
     */
    private $requestorId;

    /**
     * @var string
     *
     * Equivalent to ILOG_REQ_PHONE.
     *
     * Required: YES
     *
     */
    private $requestorPhone;

    /**
     * @var string
     *
     * Equivalent to ILOG_REQ_PHONE.
     *
     * Required: YES
     *
     */
    private $requestorEmail;

    /**
     * @var string
     *
     * Equivalent to ILOG_REQUEST.
     *
     * Required: YES
     *
     */
    private $description;

    /**
     * @var string
     *
     * Equivalent to ILOG_FACILITY.
     *
     * Required: YES
     *
     */
    private $facility;

    /**
     * @var string
     *
     * Equivalent to ILOG_BUILDING.
     *
     * Required: YES
     *
     */
    private $building;

    /**
     * @var string
     *
     * Equivalent to ILOG_FLoor.
     *
     */
    private $floor;

    /**
     * @var string
     *
     * Equivalent to ILOG_AREA.
     *
     * Required: YES
     *
     */
    private $area;

    /**
     * @var string
     *
     * Autopopulated by TMA.
     * Equivalent to ILOG_REQ_DATE.
     *
     */
    private $dateRequested;

    /**
     * @var string
     *
     * Autopopulated by TMA.
     * Equivalent to ILOG_CREATED_DATE.
     *
     */
    private $dateCreated;

    /**
     * @var string
     *
     * This is the name of the client, us, and is needed for TMA.
     * Will always be set to "ucb".
     *
     * THIS IS SET IN THE FixitRequestFactory, DO NOT CHANGE VALUE IN CONSTRUCTOR.
     *
     * Equivalent to ILOG_CLIENT_NAME.
     *
     * Required: YES
     *
     */
    private $clientName;

    /**
     * @var string
     *
     * TMA field. Email will or will not be sent by them.
     * Equivalent to ILOG_EMAIL.
     *
     */
    private $sendEmail;

    /**
     * @var string
     *
     * Autopopulated by TMA.
     * Equivalent to ILOG_PROCESSED.
     *
     */
    private $processedStatus;

    /**
     * @var string
     *
     * Autopopulated by TMA.
     * Equivalent to ILOG_PROCESSED_ERROR.
     *
     */
    private $processedErrorMessage;

    /**
     * @var string
     *
     * Flag on submission will control this field.
     * Equivalent to ILOG_RC_CODE.
     *
     * Required: Depends on submission.
     *
     */
    private $repairCenterCode;

    /**
     * @var string
     *
     * Needs to be set to "Fix It!" to properly be handled by TMA.
     * THIS IS SET IN THE FixitRequestFactory, DO NOT CHANGE VALUE IN CONSTRUCTOR.
     *
     * Equivalent to ILOG_REQ_TYPE.
     *
     * Required: YES
     *
     */
    private $requestType;

    /**
     * @var string
     *
     * This field will always be blank as its not used by us or TMA, but it is needed in order to send a request.
     * Will possibly be depracated in the future.
     * Equivalent to ILOG_REF.
     *
     */
    private $referenceNumber;

    /**
     * @var string
     *
     * Equivalent to ILOG_STATUS.
     *
     */
    private $requestStatus;

    /**
     * @var string
     *
     * Equivalent to ILOG_department.
     */
    private $department;

    /**
     * @var string
     *
     * Task Code that denotes to TMA the specified task.
     *
     * Equivalent to ILOG_TASKCODE.
     */
    private $taskCode;

    /**
     * @var string
     *
     * Equivalent to ILOG_ACCOUNTCODE.
     */
    private $accountCode;

    /**
     * FixitRequest constructor.
     *
     * All fields are originally set to an empty string and will be filled as needed by the FixitRequestHandler.
     *
     */
    public function __construct() {
        $placeholder = "";

        $this->id = $placeholder;
        $this->number = $placeholder;
        $this->state = $placeholder;
        $this->requestorName = $placeholder;
        $this->requestorId = $placeholder;
        $this->requestorPhone = $placeholder;
        $this->requestorEmail = $placeholder;
        $this->description = $placeholder;
        $this->facility = $placeholder;
        $this->building = $placeholder;
        $this->floor = $placeholder;
        $this->area = $placeholder;
        $this->dateRequested = $placeholder;
        $this->dateCreated = $placeholder;
        $this->clientName = $placeholder;
        $this->sendEmail = $placeholder;
        $this->processedStatus = $placeholder;
        $this->processedErrorMessage = $placeholder;
        $this->repairCenterCode = $placeholder;
        $this->requestType = $placeholder;
        $this->referenceNumber = $placeholder;
        $this->requestStatus = $placeholder;
        $this->department = $placeholder;
        $this->taskCode = $placeholder;
        $this->accountCode = $placeholder;
    }

    /**
     * @return string
     */
    public function getId(): string {
        return $this->id;
    }

    /**
     * @param string $id
     */
    public function setId(string $id): void {
        $this->id = $id;
    }

    /**
     * @return string
     */
    public function getNumber(): string {
        return $this->number;
    }

    /**
     * @param string $number
     */
    public function setNumber(string $number): void {
        $this->number = $number;
    }

    /**
     * @return string
     */
    public function getState(): string {
        return $this->state;
    }

    /**
     * @param string $state
     */
    public function setState(string $state): void {
        $this->state = $state;
    }

    /**
     * @return string
     */
    public function getRequestorName(): string {
        return $this->requestorName;
    }

    /**
     * @param string $requestorName
     */
    public function setRequestorName(string $requestorName): void {
        $this->requestorName = $requestorName;
    }

    /**
     * @return string
     */
    public function getRequestorId(): string {
        return $this->requestorId;
    }

    /**
     * @param string $requestorId
     */
    public function setRequestorId(string $requestorId): void {
        $this->requestorId = $requestorId;
    }

    /**
     * @return string
     */
    public function getRequestorPhone(): string {
        return $this->requestorPhone;
    }

    /**
     * @param string $requestorPhone
     */
    public function setRequestorPhone(string $requestorPhone): void {
        $this->requestorPhone = $requestorPhone;
    }

    /**
     * @return string
     */
    public function getRequestorEmail(): string {
        return $this->requestorEmail;
    }

    /**
     * @param string $requestorEmail
     */
    public function setRequestorEmail(string $requestorEmail): void {
        $this->requestorEmail = $requestorEmail;
    }

    /**
     * @return string
     */
    public function getDescription(): string {
        return $this->description;
    }

    /**
     * @param string $description
     */
    public function setDescription(string $description): void {
        $this->description = $description;
    }

    /**
     * @return string
     */
    public function getFacility(): string {
        return $this->facility;
    }

    /**
     * @param string $facility
     */
    public function setFacility(string $facility): void {
        $this->facility = $facility;
    }

    /**
     * @return string
     */
    public function getBuilding(): string {
        return $this->building;
    }

    /**
     * @param string $building
     */
    public function setBuilding(string $building): void {
        $this->building = $building;
    }

    /**
     * @return string
     */
    public function getFloor(): string {
        return $this->floor;
    }

    /**
     * @param string $floor
     */
    public function setFloor(string $floor): void {
        $this->floor = $floor;
    }

    /**
     * @return string
     */
    public function getArea(): string {
        return $this->area;
    }

    /**
     * @param string $area
     */
    public function setArea(string $area): void {
        $this->area = $area;
    }

    /**
     * @return string
     */
    public function getDateRequested(): string {
        return $this->dateRequested;
    }

    /**
     * @param string $dateRequested
     */
    public function setDateRequested(string $dateRequested): void {
        $this->dateRequested = $dateRequested;
    }

    /**
     * @return string
     */
    public function getDateCreated(): string {
        return $this->dateCreated;
    }

    /**
     * @param string $dateCreated
     */
    public function setDateCreated(string $dateCreated): void {
        $this->dateCreated = $dateCreated;
    }

    /**
     * @return string
     */
    public function getClientName(): string {
        return $this->clientName;
    }

    /**
     * @param string $clientName
     */
    public function setClientName(string $clientName): void {
        $this->clientName = $clientName;
    }

    /**
     * @return string
     */
    public function getSendEmail(): string {
        return $this->sendEmail;
    }

    /**
     * @param string $sendEmail
     */
    public function setSendEmail(string $sendEmail): void {
        $this->sendEmail = $sendEmail;
    }

    /**
     * @return string
     */
    public function getProcessedStatus(): string {
        return $this->processedStatus;
    }

    /**
     * @param string $processedStatus
     */
    public function setProcessedStatus(string $processedStatus): void {
        $this->processedStatus = $processedStatus;
    }

    /**
     * @return string
     */
    public function getProcessedErrorMessage(): string {
        return $this->processedErrorMessage;
    }

    /**
     * @param string $processedErrorMessage
     */
    public function setProcessedErrorMessage(string $processedErrorMessage): void {
        $this->processedErrorMessage = $processedErrorMessage;
    }

    /**
     * @return string
     */
    public function getRepairCenterCode(): string {
        return $this->repairCenterCode;
    }

    /**
     * @param string $repairCenterCode
     */
    public function setRepairCenterCode(string $repairCenterCode): void {
        $this->repairCenterCode = $repairCenterCode;
    }

    /**
     * @return string
     */
    public function getRequestType(): string {
        return $this->requestType;
    }

    /**
     * @param string $requestType
     */
    public function setRequestType(string $requestType): void {
        $this->requestType = $requestType;
    }

    /**
     * @return string
     */
    public function getReferenceNumber(): string {
        return $this->referenceNumber;
    }

    /**
     * @param string $referenceNumber
     */
    public function setReferenceNumber(string $referenceNumber): void {
        $this->referenceNumber = $referenceNumber;
    }

    /**
     * @return string
     */
    public function getRequestStatus(): string {
        return $this->requestStatus;
    }

    /**
     * @param string $requestStatus
     */
    public function setRequestStatus(string $requestStatus): void {
        $this->requestStatus = $requestStatus;
    }

    /**
     * @return string
     */
    public function getDepartment(): string {
        return $this->department;
    }

    /**
     * @param string $department
     */
    public function setDepartment(string $department): void {
        $this->department = $department;
    }

    /**
     * @return string
     */
    public function getTaskCode(): string {
        return $this->taskCode;
    }

    /**
     * @param string $taskCode
     */
    public function setTaskCode(string $taskCode): void {
        $this->taskCode = $taskCode;
    }

    /**
     * @return string
     */
    public function getAccountCode(): string {
        return $this->accountCode;
    }

    /**
     * @param string $accountCode
     */
    public function setAccountCode(string $accountCode): void {
        $this->accountCode = $accountCode;
    }

}