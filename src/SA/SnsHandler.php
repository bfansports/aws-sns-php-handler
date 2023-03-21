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
        $identity_id = null,
        $segments = null,
        $marketing = false
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
                $this->$provider($alert, $data, $options, $identity_id)
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
        $segments = null,
        $marketing = false
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
                    false,
                    $identity_id,
                    $segments,
                    $marketing
                );
            } catch (\Exception $e) {
                $this->log_wrapper(
                    "WARNING",
                    "Cannot publish to '$endpoint': " . $e->getMessage() . "\n"
                );
            }
        }

        // Save our message if needed.
        if ($save) {
            $this->saveNotifInDB(
                $data,
                $alert,
                $endpoints,
                $identity_id,
                $segments,
                $marketing
            );
        }
    }

    private function saveNotifInDB(
        $data,
        $alert,
        $endpoints,
        $identity_id = null,
        $segments = null,
        $marketing = false
    ) {
        if (isset($alert['body'])) {
            if (empty($alert['title'])) {
                $alert['title'] = " ";
            }

            try {
                $dbData = [
                    "TableName" => "CustomSnsMessages",
                    "Item" => [
                        "body" => ["S" => $alert['body']],
                        "endpoints" => ["SS" => $endpoints],
                        "org_id" => ["S" => $data['org_id']],
                        "timestamp" => ["N" => (string) time()],
                        "title" => ["S" => $alert['title']],
                        "marketing" => ["BOOL" => $marketing]
                    ],
                ];

                if ( !empty($data['click_action']) ) {
                    $dbData['Item']['click_action'] = ["S" => $data["click_action"]];
                }

                if ( !empty($data["link_type"]) ) {
                    $dbData['Item']['link_type'] = ["S" => $data["link_type"]];
                    if ( !empty($data["link_url"]) ) {
                        $dbData['Item']['link_url'] = ["S" => $data["link_url"]];
                    }
                }

                if (!empty($identity_id)) {
                    $dbData['Item']['identity_id'] = ["S" => $identity_id];
                }

                if (!empty($segments)) {
                    $dbData['Item']['segments'] = ["SS" => $segments];
                    $dbData['Item'][
                        'segment_counts'
                    ] = $this->getMarshalledSegmentCounts($segments);
                }

                $result = $this->ddb->putItem($dbData);
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

    private static function getMarshalledSegmentCounts($segments) {
        $counts = [
            "M" => [
                "default" => [
                    "N" => 0,
                ],
                "favorite" => [
                    "N" => 0,
                ],
                "language" => [
                    "N" => 0,
                ],
            ],
        ];

        if (isset($segments)) {
            foreach ($segments as $seg) {
                $temp = explode("_", $seg);

                if ($temp[0] == "fav") {
                    $counts['M']['favorite']['N']++;
                } elseif ($temp[0] == "lang") {
                    $counts['M']['language']['N']++;
                } else {
                    $counts['M']['default']['N']++;
                }
            }
        }

        $counts['M']['favorite']['N'] = (string) $counts['M']['favorite']['N'];
        $counts['M']['language']['N'] = (string) $counts['M']['language']['N'];
        $counts['M']['default']['N'] = (string) $counts['M']['default']['N'];

        return $counts;
    }

    // Handles Apple notifications. Can be overide if structure needs to be different
    protected function APNS($alert, $data, $options, $identity_id = null) {

        if ( array_key_exists('body_loc_key', $alert) ) {
            if ( !empty($alert['body_loc_key']) ) {
                $alert['loc-key'] = $alert['body_loc_key'];
            }
            unset($alert['body_loc_key']);
        }

        if ( array_key_exists('body_loc_args', $alert) ) {
            if ( !empty($alert['body_loc_args']) ) {
                $alert['loc-args'] = $alert['body_loc_args'];
            }
            unset($alert['body_loc_args']);
        }

        if ( array_key_exists('title_loc_key', $alert) ) {
            if ( !empty($alert['title_loc_key']) ) {
                $alert['title-loc-key'] = $alert['title_loc_key'];
            }
            unset($alert['title_loc_key']);
        }

        if ( array_key_exists('title_loc_args', $alert) ) {
            if ( !empty($alert['title_loc_args']) ) {
                $alert['title-loc-args'] = $alert['title_loc_args'];
            }
            unset($alert['title_loc_args']);
        }

        $message = [
            "aps" => [
                "alert" => $alert,
            ],
            "data" => $data
        ];

        if (isset($options['APNS']) && count($options['APNS'])) {
            $message = array_merge_recursive($message, $options['APNS']);
        }

        if ( !empty($identity_id) ) {
            $message['identity_id'] = $identity_id;
        }

        return $message;
    }

    protected function APNS_SANDBOX($alert, $data, $options, $identity_id = null) {
        return $this->APNS($alert, $data, $options, $identity_id);
    }

    // Handles Google notifications. Can be overide if structure needs to be different
    protected function GCM($alert, $data, $options, $identity_id = null) {

        $message = [
            "notification" => $alert,
            "data" => $data,
        ];

        if ( !empty($options['GCM']) ) {
            $message = array_merge_recursive($message, $options['GCM']);
        }

        if ( !empty($identity_id) ) {
            $message['data']['identity_id'] = $identity_id;
        }

        return [
            'data' => $message
        ];
    }
}
