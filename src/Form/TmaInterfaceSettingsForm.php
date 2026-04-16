<?php
/**
 * Created by PhpStorm.
 * User: hdsstudent
 * Date: 1/10/19
 * Time: 10:58 AM
 */

namespace Drupal\ucb_tma_interface\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

class TmaInterfaceSettingsForm extends ConfigFormBase {

    /**
     * {@inheritdoc}
     */
    public function getFormId() {
        return "tma_interface_admin_settings";
    }

    /**
     * {@inheritdoc}
     */
    protected function getEditableConfigNames() {
        return [
            "ucb_tma_interface.settings"
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function buildForm(array $form, FormStateInterface $form_state) {
        $config = $this->config("ucb_tma_interface.settings");

        $form["request_url"] = [
            "#type" => "textfield",
            "#title" => $this->t("Request Url"),
            "#default_value" => $config->get("request_url")
        ];

        $form["locations_url"] = [
            "#type" => "textfield",
            "#title" => $this->t("Locations Url"),
            "#default_value" => $config->get("locations_url")
        ];

        $form["authentication_url"] = [
            "#type" => "textfield",
            "#title" => $this->t("Authentication Url"),
            "#default_value" => $config->get("authentication_url")
        ];

        $form["authentication_user"] = [
            "#type" => "textfield",
            "#title" => $this->t("Authentication Username"),
            "#default_value" => $config->get("authentication_user")
        ];

        $form["authentication_pass"] = [
            "#type" => "password",
            "#title" => $this->t("Authentication Password"),
            "#default_value" => $config->get("authentication_pass")
        ];

        return parent::buildForm($form, $form_state);
    }

    /**
     * {@inheritdoc}
     */
    public function submitForm(array &$form, FormStateInterface $form_state) {
        $config = $this->config("ucb_tma_interface.settings");

        \Drupal::logger('Config set')->notice($form_state->getValue("authentication_pass"));

        $this->configFactory->getEditable("ucb_tma_interface.settings")
            ->set("request_url", $form_state->getValue("request_url") ? $form_state->getValue("request_url") : $config->get("request_url"))
            ->set("locations_url", $form_state->getValue("locations_url") ? $form_state->getValue("locations_url") : $config->get("locations_url"))
            ->set("authentication_url", $form_state->getValue("authentication_url") ? $form_state->getValue("authentication_url") : $config->get("authentication_url"))
            ->set("authentication_user", $form_state->getValue("authentication_user") ? $form_state->getValue("authentication_user") : $config->get("authentication_user"))
            ->set("authentication_pass", $form_state->getValue("authentication_pass") ? $form_state->getValue("authentication_pass") : $config->get("authentication_pass"))
            ->save();

        parent::submitForm($form, $form_state);
    }

}