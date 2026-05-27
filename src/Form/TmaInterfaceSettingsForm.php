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

        $form["base_url"] = [
            "#type" => "textfield",
            "#title" => $this->t("Platform API Base Url"),
            "#description" => $this->t("Example: https://devtma7.colorado.edu/webTMA7/platformapi (no trailing slash)"),
            "#default_value" => $config->get("base_url")
        ];

        $form["authentication_user"] = [
            "#type" => "textfield",
            "#title" => $this->t("Authentication Username"),
            "#default_value" => $config->get("authentication_user")
        ];

        $form["authentication_client_name"] = [
            "#type" => "textfield",
            "#title" => $this->t("Authentication Client Name"),
            "#default_value" => $config->get("authentication_client_name")
        ];

        $form["authentication_pass"] = [
            "#type" => "password",
            "#title" => $this->t("Authentication Password"),
            "#default_value" => $config->get("authentication_pass")
        ];

        $form["debug_api_logging"] = [
            "#type" => "checkbox",
            "#title" => $this->t("Enable verbose TMA API debug logging"),
            "#description" => $this->t("Logs request/response payloads for v7 WorkOrder submission to Drupal logs. Warning: may include requestor PII and request details."),
            "#default_value" => (bool) $config->get("debug_api_logging"),
        ];

        $form["platform_facility_location_type_id"] = [
            "#type" => "number",
            "#title" => $this->t("Facility LocationTypeId"),
            "#description" => $this->t("RequestLog locationTypeId when Location is a Facility (typically 10)."),
            "#default_value" => (int) ($config->get("platform_facility_location_type_id") ?? 10),
            "#min" => 1,
        ];

        $form["platform_area_location_type_id"] = [
            "#type" => "number",
            "#title" => $this->t("Area LocationTypeId"),
            "#description" => $this->t("RequestLog locationTypeId when Location is an Area (typically 7)."),
            "#default_value" => (int) ($config->get("platform_area_location_type_id") ?? 7),
            "#min" => 1,
        ];

        return parent::buildForm($form, $form_state);
    }

    /**
     * {@inheritdoc}
     */
    public function submitForm(array &$form, FormStateInterface $form_state) {
        $config = $this->config("ucb_tma_interface.settings");

        $this->configFactory->getEditable("ucb_tma_interface.settings")
            ->set("base_url", $form_state->getValue("base_url") ? $form_state->getValue("base_url") : $config->get("base_url"))
            ->set("authentication_user", $form_state->getValue("authentication_user") ? $form_state->getValue("authentication_user") : $config->get("authentication_user"))
            ->set("authentication_client_name", $form_state->getValue("authentication_client_name") ? $form_state->getValue("authentication_client_name") : $config->get("authentication_client_name"))
            ->set("authentication_pass", $form_state->getValue("authentication_pass") ? $form_state->getValue("authentication_pass") : $config->get("authentication_pass"))
            ->set("debug_api_logging", (bool) $form_state->getValue("debug_api_logging"))
            ->set("platform_facility_location_type_id", (int) $form_state->getValue("platform_facility_location_type_id"))
            ->set("platform_area_location_type_id", (int) $form_state->getValue("platform_area_location_type_id"))
            ->save();

        parent::submitForm($form, $form_state);
    }

}