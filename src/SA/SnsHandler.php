<?php

namespace SA;

use Aws\Credentials\CredentialProvider;

class SnsHandler {
    // AWS DynamoDB handler
    private $ddb;

    // AWS region for SQS
    private $region;

    // AWS SNS handler
    private $sns;

    public function __construct($region = false) {
        if (!$region && !($region = getenv("AWS_DEFAULT_REGION"))) {
            throw new \Exception(
                "Set 'AWS_DEFAULT_REGION' environment variable!"
            );
        }

        $this->region = $region;

        $provider = CredentialProvider::defaultProvider();

        $this->sns = new \Aws\Sns\SnsClient([
            "region" => $region,
            "version" => "latest",
            "credentials" => $provider,
        ]);

        $this->ddb = new \Aws\DynamoDb\DynamoDbClient([
            "region" => $region,
            "version" => "latest",
            "credentials" => $provider,
        ]);
    }

    // $alert contains default keys handled by both Apple and Google.
    // We follow the Google formatting:
    // https://developers.google.com/cloud-messaging/http-server-ref#notification-payload-support
    public function publishToEndpoint(
        $endpoint,
        $alert,
        $data,
        $options,
        $providers = ["GCM", "APNS", "APNS_SANDBOX"],
        $default = "",
        $save = true,
        $identity_id = null
    ) {
        // To prevent DynamoDB insert error if no endpoints are provided
        if (empty($endpoint) || empty($alert)) {
            $this->log_wrapper(
                "WARNING",
                "No valid ENDPOINT or ALERT data. Aborting sending to SNS."
            );

            return;
        }

        $message = [
            "default" => $default,
        ];

        // Iterate over all providers and inject custom alert message in global message
        foreach ($providers as $provider) {
            if (!method_exists($this, $provider)) {
                throw new \Exception(
                    "Provider function doesn't exists for: " . $provider
                );
            }

            // Insert provider alert message in global SNS message
            $message[$provider] = json_encode(
                $this->$provider($alert, $data, $options)
            );
        }

        // Default TTL one week
        $ttl = 604800;
        if (isset($options['time_to_live'])) {
            $ttl = $options['time_to_live'];
        }

        // Save our message if needed.
        if ($save) {
            $this->saveNotifInDB($data, $alert, [$endpoint], $identity_id);
        }

        return $this->sns->publish([
            'TargetArn' => $endpoint,
            'MessageStructure' => 'json',
            'Message' => json_encode($message),
            'MessageAttributes' => [
                'AWS.SNS.MOBILE.APNS.TTL' => [
                    "DataType" => "String",
                    "StringValue" => "$ttl",
                ],
                'AWS.SNS.MOBILE.APNS_SANDBOX.TTL' => [
                    "DataType" => "String",
                    "StringValue" => "$ttl",
                ],
                'AWS.SNS.MOBILE.GCM.TTL' => [
                    "DataType" => "String",
                    "StringValue" => "$ttl",
                ],
            ],
        ]);
    }

    // Publish to many endpoints the same notifications
    public function publishToEndpoints(
        $endpoints,
        $alert,
        $data,
        $options,
        $providers = ["GCM", "APNS", "APNS_SANDBOX"],
        $default = "",
        $save = true,
        $identity_id = null,
        $segments = null
    ) {
        // To prevent DynamoDB insert error if no endpoints are provided
        if (empty($endpoints) || empty($alert)) {
            $this->log_wrapper(
                "WARNING",
                "No valid ENDPOINTS or ALERT data. Aborting sending to SNS."
            );
            return;
        }

        foreach ($endpoints as $endpoint) {
            try {
                $this->publishToEndpoint(
                    $endpoint,
                    $alert,
                    $data,
                    $options,
                    $providers,
                    $default,
                    false
                );
            } catch (\Exception $e) {
                $this->log_wrapper(
                    "ERROR",
                    "Cannot publish to '$endpoint': " . $e->getMessage() . "\n"
                );
            }
        }

        // Save our message if needed.
        if ($save) {
            $this->saveNotifInDB($data, $alert, $endpoints, $identity_id, $segments);

        }
    }

    private function saveNotifInDB(
        $data,
        $alert,
        $endpoints,
        $identity_id = null,
        $segments = null
    ) {
        if (isset($alert['body'])) {
            if (empty($alert['title'])) {
                $alert['title'] = " ";
            }

            try {
                $data = [
                    "TableName" => "CustomSnsMessages",
                    "Item" => [
                        "body" => ["S" => $alert['body']],
                        "endpoints" => ["SS" => $endpoints],
                        "org_id" => ["S" => $data['org_id']],
                        "timestamp" => ["N" => (string) time()],
                        "title" => ["S" => $alert['title']]
                    ],
                ];

                if (!empty($identity_id)) {
                    $data['Item']['identity_id'] = ["S" => $identity_id];
                }

                if ( !empty($segments) ) {
                    $data['Item']['segments'] = ["SS" => $segments];
                }

                $result = $this->ddb->putItem($data);
            } catch (Exception $e) {
                $this->log_wrapper(
                    "ERROR",
                    "Cannot insert message into dynamo: " .
                        $e->getMessage() .
                        "\n"
                );
            }
        }
    }

    private function log_wrapper($type, $message) {
        if (function_exists('log_message')) {
            log_message($type, $message);
        } else {
            $out = "";
            $out .= "[" . date("Y-m-d H:i:s") . "] ";
            $out .= "sa_site_daemons." . $type . ": ";
            $out .= $message;
            echo $out;
        }
    }

    // Handles Apple notifications. Can be overide if structure needs to be different
    protected function APNS($alert, $data, $options) {
        if (isset($alert['body_loc_key'])) {
            $alert['loc-key'] = $alert['body_loc_key'];
            unset($alert['body_loc_key']);
        }
        if (isset($alert['body_loc_args'])) {
            $alert['loc-args'] = $alert['body_loc_args'];
            unset($alert['body_loc_args']);
        }
        if (isset($alert['title_loc_key'])) {
            $alert['title-loc-key'] = $alert['title_loc_key'];
            unset($alert['title_loc_key']);
        }
        if (isset($alert['title_loc_args'])) {
            $alert['title-loc-args'] = $alert['title_loc_args'];
            unset($alert['title_loc_args']);
        }

        $message = [
            "aps" => [
                "alert" => $alert,
            ],
        ];

        if (isset($options['APNS']) && count($options['APNS'])) {
            $message['aps'] = array_merge($message['aps'], $options['APNS']);
        }

        if (!empty($data)) {
            if (isset($data['media_type']) && isset($data['media_url'])) {
                if (
                    $data['media_type'] == 'image' &&
                    !empty($data['media_url'])
                ) {
                    $message['media-url'] = $data['media_url'];
                    $message['aps']['mutable-content'] = 1;
                }
                unset($data['media_type']);
                unset($data['media_url']);
            }
            $message = array_merge($message, $data);
        }

        return $message;
    }

    protected function APNS_SANDBOX($alert, $data, $options) {
        return $this->APNS($alert, $data, $options);
    }

    // Handles Google notifications. Can be overide if structure needs to be different
    protected function GCM($alert, $data, $options) {
        if (!empty($data)) {
            $payload = array_merge($alert, $data);
        }

        $message = [
            "data" => [
                "payload" => $payload,
            ],
        ];

        if (isset($options['GCM']) && count($options['GCM'])) {
            $message = array_merge($message, $options['GCM']);
        }

        return $message;
    }
}
