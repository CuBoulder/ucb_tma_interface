<?php

namespace Drupal\ucb_tma_interface\Plugin\WebformHandler;

use Drupal\Core\Entity\ContentEntityFormInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\ucb_tma_interface\InterfaceController\TmaFrontController;
use Drupal\webform\Plugin\WebformHandlerBase;
use Drupal\webform\WebformInterface;
use Drupal\webform\WebformSubmissionInterface;

/**
 * Submits Fix It webforms to TMA via `ucb_tma_interface` (Platform API v7).
 *
 * @WebformHandler(
 *   id = "ucb_tma_form_handler",
 *   label = @Translation("UCB TMA form handler"),
 *   category = @Translation("Form Handler"),
 *   description = @Translation("Sends webform submissions to the UCB TMA integration (ucb_tma_interface) and stores the returned ticket id."),
 *   cardinality = \Drupal\webform\Plugin\WebformHandlerInterface::CARDINALITY_SINGLE,
 *   results = \Drupal\webform\Plugin\WebformHandlerInterface::RESULTS_PROCESSED,
 * )
 */
class TmaFormHandler extends WebformHandlerBase {

  /**
   * {@inheritdoc}
   */
  public function alterForm(array &$form, FormStateInterface $form_state, WebformSubmissionInterface $webform_submission) {
    $webform_id = $this->getWebform()->id();
    if ($webform_id === 'report_a_problem' || $webform_id === 'request_services') {
      $form['#attached']['library'][] = 'ucb_tma_interface/report';
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state, WebformSubmissionInterface $webform_submission) {
    if ($this->shouldRunTmaSubmission($form, $form_state, $webform_submission)) {
      $tmaFrontController = new TmaFrontController();
      $task = $webform_submission->getElementData('task_select');
      $taskNid = is_numeric($task) ? (int) $task : 0;
      $taskCode = '';
      $repairCenterFromTask = $taskNid > 0
        ? $tmaFrontController->taskRepairCenterEnabledFromNodeId($taskNid)
        : FALSE;
      if ($taskNid > 0) {
        $query = \Drupal::database()->select('node__field_task_code', 't');
        $query->addField('t', 'field_task_code_value');
        $query->condition('t.entity_id', $taskNid);
        $taskCode = trim((string) $query->execute()->fetchField());
      }
      if ($taskCode === '') {
        $taskCode = $tmaFrontController->resolveFixitTaskCode(['task_select' => (string) $task]);
        if ($repairCenterFromTask === FALSE && $taskCode !== '') {
          $repairCenterFromTask = $tmaFrontController->taskRepairCenterEnabledFromTaskCode($taskCode);
        }
      }
      $webform_submission->setElementData('task_select', $taskCode);
      $webform_submission->setElementData('repair_center', $repairCenterFromTask ? 'FS' : '');

      $area = (string) $webform_submission->getElementData('area');
      $webform_submission->setElementData('floor', $tmaFrontController->getFloorFromAreaTaxonomy($area, NULL));
      $building = $webform_submission->getElementData('building');
      if ($building == 'Faculty Staff Court') {
        $webform_submission->setElementData('building', 'Faculty/Staff Court');
      }
      elseif ($building == 'Cheyenne Arapaho Hall') {
        $webform_submission->setElementData('building', 'Chey/Arap Hall');
      }

      $response = $tmaFrontController->submitFixitRequest($webform_submission->getData());
      $ticketresponse = json_decode((string) $response->getBody(), TRUE);
      // `ucb_tma_interface` returns a legacy-shaped body; ILOG_NUMBER is the TMA request # for
      // confirmation (work order number is only used if the API omits the request number).
      $ticket_id = $ticketresponse['NewDataSet']['i_WebTMA_Requests'][0]['ILOG_NUMBER']
        ?? '';
      $webform_submission->setElementData('ticket_id', (string) $ticket_id);
      $webform_submission->setElementData('task_select', $task);
    }

    return TRUE;
  }

  /**
   * Whether this submit should POST to TMA (once), not wizard "Next" steps.
   *
   * When webform preview is enabled, TMA runs on the preview page Submit.
   * When preview is disabled, the final Submit happens on the last wizard page;
   * the old preview-only check would never fire.
   */
  private function shouldRunTmaSubmission(array $form, FormStateInterface $form_state, WebformSubmissionInterface $webform_submission): bool {
    $trigger = (string) ($form_state->getTriggeringElement()['#value'] ?? '');
    if ($trigger !== 'Submit') {
      return FALSE;
    }
    $current = (string) ($form['progress']['#current_page'] ?? '');
    $previewSetting = (int) $this->getWebform()->getSetting('preview');
    if ($previewSetting !== 0) {
      return $current === WebformInterface::PAGE_PREVIEW;
    }
    // Preview off: run on final wizard Submit. Mirror WebformSubmissionForm::getPages():
    // base pages for this operation, then conditions-based visibility.
    $operation = $this->resolveWebformFormOperation($form_state);
    $pages = $this->getWebform()->getPages($operation);
    if (\Drupal::hasService('webform_submission.conditions_validator')) {
      $pages = \Drupal::service('webform_submission.conditions_validator')->buildPages($pages, $webform_submission);
    }
    $keys = array_keys($pages);
    if ($keys === []) {
      return FALSE;
    }
    $lastPage = $keys[array_key_last($keys)];
    return $current === $lastPage;
  }

  /**
   * Webform uses $operation (e.g. add, edit, test), not always "default".
   */
  private function resolveWebformFormOperation(FormStateInterface $form_state): string {
    $form_object = $form_state->getFormObject();
    if ($form_object instanceof ContentEntityFormInterface) {
      $op = (string) $form_object->getOperation();
      return $op !== '' ? $op : 'default';
    }
    return 'default';
  }

}
