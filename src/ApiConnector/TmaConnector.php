<?php
/**
 * Created by PhpStorm.
 * User: hdsstudent
 * Date: 10/22/18
 * Time: 2:11 PM
 */

namespace Drupal\ucb_tma_interface\ApiConnector;

use GuzzleHttp\Exception\RequestException;

/**
 * Class TmaConnector
 * @package Drupal\tma_interface\ApiConnector
 *
 * The connector directly interacts with TMA's api holding
 * all the logic to send and gather fixit requests.
 */
class TmaConnector {

    private $client;
    private $errorMessage;
    private $config;

    /**
     * TmaConnector constructor.
     *
     * Uses Drupal's httpClient class.
     */
    public function __construct() {
        $this->client = \Drupal::httpClient();
        $this->errorMessage = ['#title' => "Response Error", '#markup' => "Check Drupal's recent log messages under reports."];
        $this->config = \Drupal::config('ucb_tma_interface.settings');
    }

    /**
     * @param $url
     * @return array|\Psr\Http\Message\ResponseInterface
     */
    public function getResponse($url, $headerExtendKey = "", $headerExtendVal = "") {
        $options = [
            'headers' => [
                'Content-Type' => "application/soap+xml",
                'TMAAuth' => $this->tma_cached_key()
            ]
        ];

        // Allows optional extensions to the header values.
        // Primarily used for receiving filtered location data from TMA.
        if($headerExtendKey and $headerExtendVal) {
            $options["headers"][$headerExtendKey] = $headerExtendVal;
        }

        try {
            $response = $this->client->get($url, $options);
        } catch(RequestException $e) {
            \Drupal::logger('TMA web service')->error($e);
            return $this->errorMessage;
        }

        return $response;
    }

    /**
     * @param $url
     * @param $requestBody
     * @return array|\Psr\Http\Message\ResponseInterface
     */
    public function sendRequest($url, $requestBody) {
        $options = [
            'headers' => [
                'Content-Type' => "application/soap+xml",
                'TMAAuth' => $this->tma_cached_key()
            ],
            'body' => $requestBody
        ];

        try {
            $response = $this->client->post($url, $options);
        } catch(RequestException $e) {
            \Drupal::logger('TMA web service')->error($e);
            return $this->errorMessage;
        }

        return $response;
    }

    private function tma_cached_key() {
        $key = &drupal_static(__FUNCTION__);
        $cid = "tma_key:" . \Drupal::languageManager()->getCurrentLanguage()->getId();

        if($cache = \Drupal::cache()->get($cid)) {
            $key = $cache->data;
        } else {
            $key = $this->getTmaKey();
            \Drupal::cache()->set($cid, $key);
        }

        return $key;
    }

    private function getTmaKey() {
        $options = [
            'body' => json_encode([
                'username' => $this->config->get('authentication_user'),
                'password' => $this->config->get('authentication_pass'),
                'client' => 'ucb',
                'language' => '0'
            ])
        ];

        return json_decode($this->client->post($this->config->get('authentication_url'), $options)->getBody(), true)['key'];
    }
}