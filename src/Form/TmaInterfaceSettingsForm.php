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

        $form["feeds_base_url"] = [
            "#type" => "textfield",
            "#title" => $this->t("Feeds source site URL (optional)"),
            "#description" => $this->t("Base URL Feeds uses when importing from /tma/location/*. Required for Drush Feeds imports when the site is not reached via a real HTTP request (e.g. DDEV: https://yourproject.ddev.site). No trailing slash. Leave empty to auto-detect (DDEV_PRIMARY_URL, request context, or fixit.colorado.edu fallback)."),
            "#default_value" => $config->get("feeds_base_url") ?? '',
        ];

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

        return parent::buildForm($form, $form_state);
    }

    /**
     * {@inheritdoc}
     */
    public function submitForm(array &$form, FormStateInterface $form_state) {
        $config = $this->config("ucb_tma_interface.settings");

        $this->configFactory->getEditable("ucb_tma_interface.settings")
            ->set("base_url", $form_state->getValue("base_url") ? $form_state->getValue("base_url") : $config->get("base_url"))
            ->set("feeds_base_url", trim((string) ($form_state->getValue("feeds_base_url") ?? '')))
            ->set("request_url", $form_state->getValue("request_url") ? $form_state->getValue("request_url") : $config->get("request_url"))
            ->set("locations_url", $form_state->getValue("locations_url") ? $form_state->getValue("locations_url") : $config->get("locations_url"))
            ->set("authentication_url", $form_state->getValue("authentication_url") ? $form_state->getValue("authentication_url") : $config->get("authentication_url"))
            ->set("authentication_user", $form_state->getValue("authentication_user") ? $form_state->getValue("authentication_user") : $config->get("authentication_user"))
            ->set("authentication_client_name", $form_state->getValue("authentication_client_name") ? $form_state->getValue("authentication_client_name") : $config->get("authentication_client_name"))
            ->set("authentication_pass", $form_state->getValue("authentication_pass") ? $form_state->getValue("authentication_pass") : $config->get("authentication_pass"))
            ->save();

        parent::submitForm($form, $form_state);
    }

}