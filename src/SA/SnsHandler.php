<?php

namespace SA;

class SnsHandler {
    /** AWS DynamoDB handler **/
    private $ddb;

    /** AWS region for SQS **/
    private $region;

    /** AWS SNS handler **/
    private $sns;

    public function __construct($region = false) {
        if (!$region &&
            !($region = getenv("AWS_DEFAULT_REGION"))) {
            throw new \Exception("Set 'AWS_DEFAULT_REGION' environment variable!");
        }

        $this->region = $region;

        $this->sns = new \Aws\Sns\SnsClient([
            "region" => $region,
            "version" => "latest",
        ]);

        $this->ddb = new \Aws\DynamoDb\DynamoDbClient([
            "region" => $region,
            "version" => "latest",
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
        $save = true) {
        $message = [
            "default" => $default,
        ];

        // Iterate over all providers and inject custom alert message in global message
        foreach ($providers as $provider) {
            if (!method_exists($this, $provider)) {
                throw new \Exception("Provider function doesn't exists for: " . $provider);
            }

            // Insert provider alert message in global SNS message
            $message[$provider] = json_encode($this->$provider($alert, $data, $options));
        }

        // Default TTL one week
        $ttl = 604800;
        if (isset($options['time_to_live'])) {
            $ttl = $options['time_to_live'];
        }

        // Save our message if needed.
        if ($save && $data['click_action'] == "custom_msg") {
            if (isset($alert['body']) && isset($alert['title'])) {
                try {
                    $this->ddb->putItem([
                        "TableName" => "CustomSnsMessages",
                        "Item" => [
                            "body" => ["S" => $alert['body']],
                            "endpoints" => ["SS" => [$endpoint]],
                            "org_id" => ["S" => $data['org_id']],
                            "timestamp" => ["N" => (string) time()],
                            "title" => ["S" => $alert['title']],
                        ],
                    ]);
                } catch (Exception $e) {
                    if (function_exists('log_message')) {
                        log_message("ERROR", "Cannot insert message into dynamo: " . $e->getMessage() . "\n");
                    } else {
                        echo "[";
                        echo date("Y-m-d H:i:s");
                        echo "] ";
                        echo "sa_site_daemons.ERROR: ";
                        echo "Cannot insert message into dynamo: ";
                        echo $e->getMessage();
                        echo "\n";
                    }
                }
            }
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
        $save = true) {
        foreach ($endpoints as $endpoint) {
            try {
                $this->publishToEndpoint($endpoint, $alert, $data, $options, $providers, $default, false);
            } catch (\Exception $e) {
                if (function_exists('log_message')) {
                    log_message("ERROR", "Cannot publish to '$endpoint': " . $e->getMessage() . "\n");
                } else {
                    echo "[";
                    echo date("Y-m-d H:i:s");
                    echo "] ";
                    echo "sa_site_daemons.ERROR: ";
                    echo "Cannot publish to '$endpoint': ";
                    echo $e->getMessage();
                    echo "\n";
                }
            }
        }

        // Save our message if needed.
        if ($save && $data['click_action'] == "custom_msg") {
            if (isset($alert['body']) && isset($alert['title'])) {
                try {
                    $endpoints = array_unique($endpoints);
                    $this->ddb->putItem([
                        "TableName" => "CustomSnsMessages",
                        "Item" => [
                            "body" => ["S" => $alert['body']],
                            "endpoints" => ["SS" => $endpoints],
                            "org_id" => ["S" => $data['org_id']],
                            "timestamp" => ["N" => (string) time()],
                            "title" => ["S" => $alert['title']],
                        ],
                    ]);
                } catch (Exception $e) {
                    if (function_exists('log_message')) {
                        log_message("ERROR", "Cannot insert message into dynamo: " . $e->getMessage() . "\n");
                    } else {
                        echo "[";
                        echo date("Y-m-d H:i:s");
                        echo "] ";
                        echo "sa_site_daemons.ERROR: ";
                        echo "Cannot insert message into dynamo: ";
                        echo $e->getMessage();
                        echo "\n";
                    }
                }
            }
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
