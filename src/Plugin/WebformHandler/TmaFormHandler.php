<?php

namespace Drupal\ucb_tma_interface\Plugin\WebformHandler;

use Drupal\Core\Form\FormStateInterface;
use Drupal\ucb_tma_interface\InterfaceController\TmaFrontController;
use Drupal\webform\Plugin\WebformHandlerBase;
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

    public function submitForm(array &$form, FormStateInterface $form_state, WebformSubmissionInterface $webform_submission) {

        if ($form["progress"]["#current_page"] === "webform_preview" && ($form_state->getTriggeringElement()["#value"] ?? NULL) === "Submit") {
            $tmaFrontController = new TmaFrontController();
			$task = $webform_submission->getElementData('task_select');
			$query = \Drupal::database()->select('node__field_task_code', 't');
			$query->addField('t', 'field_task_code_value');
			$query->leftJoin('node__field_repair_center', 'r', 't.entity_id = r.entity_id');
			$query->addField('r', 'field_repair_center_value');
			$query->condition('t.entity_id', $task);
			$results = $query->execute()->fetchAll(\PDO::FETCH_OBJ);

			if (count($results)) {
				$webform_submission->setElementData('task_select',$results[0]->field_task_code_value);
				if ($results[0]->field_repair_center_value) {
					$webform_submission->setElementData('repair_center','FS');
				} else {
					$webform_submission->setElementData('repair_center','');
				}
			} else {
				$webform_submission->setElementData('task_select','');
				$webform_submission->setElementData('repair_center','');
			}

            // add floor code to submission results
            $area = $webform_submission->getElementData('area');
            $area_query = \Drupal::database()->select('taxonomy_term_field_data', 'd');
            $area_query->leftJoin('taxonomy_term__field_floor', 'f', 'd.tid = f.entity_id');
            $area_query->addField('f', 'field_floor_value');
            $area_query->condition('d.name', $area);
            $area_results = $area_query->execute()->fetchAll(\PDO::FETCH_OBJ);
            if (count($area_results)) {
                // set area into webform submission
                if ($area_results[0]->field_floor_value) {
                    $webform_submission->setElementData('floor',$area_results[0]->field_floor_value);
                } else {
                    $webform_submission->setElementData('floor','');
                }
            } else {
                $webform_submission->setElementData('floor','');
            }
            $building = $webform_submission->getElementData('building');
            if ($building == 'Faculty Staff Court') {
	            $webform_submission->setElementData('building','Faculty/Staff Court');
            } elseif ($building == 'Cheyenne Arapaho Hall') {
                $webform_submission->setElementData('building','Chey/Arap Hall');
            }

            $response = $tmaFrontController->submitFixitRequest($webform_submission->getData());
            $ticketresponse = json_decode((string) $response->getBody(), TRUE);
            // `ucb_tma_interface` returns a legacy-shaped response body so downstream webform
            // parsing can consistently read `NewDataSet...ILOG_NUMBER`.
            $ticket_id = $ticketresponse['NewDataSet']['i_WebTMA_Requests'][0]['ILOG_NUMBER']
              ?? '';
			$webform_submission->setElementData('ticket_id', (string) $ticket_id);
			$webform_submission->setElementData('task_select',$task);
        }

        return true;
    }
}
