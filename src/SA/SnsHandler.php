<?php

/**
 * Copyright (C) 2015, Sport Archive Inc.
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License (version 3) as published by
 * the Free Software Foundation;
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * For a complete version of the license, head to:
 * http://www.gnu.org/licenses/gpl-3.0.en.html
 *
 * Cloud Processing Engine, Copyright (C) 2015, Sport Archive Inc
 * Cloud Processing Engine comes with ABSOLUTELY NO WARRANTY;
 * This is free software, and you are welcome to redistribute it
 * under certain conditions;
 *
 * June 29th 2015
 * Sport Archive Inc.
 * info@sportarchive.tv
 *
 */

namespace SA;

use Aws\Sns\SnsClient;
use Aws\DynamoDb\DynamoDbClient;

class SnsHandler
{
    /** AWS region for SQS **/
    private $region;
    /** AWS SNS handler **/
    private $sns;
    /** AWS DynamoDB handler **/
    private $ddb;

    public function __construct($region = false)
    {
        if (!$region &&
            !($region = getenv("AWS_DEFAULT_REGION")))
            throw new \Exception("Set 'AWS_DEFAULT_REGION' environment variable!");

        $this->region = $region;

        $this->sns = SnsClient::factory([
                'region' => $region,
            ]);

        $this->ddb = DynamoDbClient::factory([
                'region' => $region,
            ]);
    }

    // Publish to many endpoints the same notifications
    public function publishToEndpoints(
        $endpoints,
        $alert,
        $data,
        $options,
        $providers = ["GCM","APNS","APNS_SANDBOX"],
        $default = "",
        $save = true)
    {
        foreach ($endpoints as $endpoint)
        {
            try {
                $this->publishToEndpoint($endpoint, $alert, $data, $options, $providers, $default, false);
            }
            catch (\Exception $e) {
                print "ERROR publish to '$endpoint': ".$e->getMessage()."\n";
            }
        }

        // Save our message if needed.
        if($save && $data['click_action'] == "custom_msg") {
            if(isset($alert['body']) && isset($alert['title'])) {
                try {
                    $endpoints = array_unique($endpoints);
                    $this->ddb->putItem([
                        "TableName" => "CustomSnsMessages",
                        "Item" => [
                            "body"      => ["S" => $alert['body']],
                            "endpoints" => ["SS" => $endpoints],
                            "org_id"    => ["S" => $data['org_id']],
                            "timestamp" => ["N" => time()],
                            "title"     => ["S" => $alert['title']],
                        ],
                    ]);
                } catch(Exception $e) {
                }
            }
        }
    }

    // $alert contains default keys handled by both Apple and Google.
    // We follow the Google formatting:
    // https://developers.google.com/cloud-messaging/http-server-ref#notification-payload-support
    public function publishToEndpoint(
        $endpoint,
        $alert,
        $data,
        $options,
        $providers = ["GCM","APNS","APNS_SANDBOX"],
        $default = "",
        $save = true)
    {
        $message = [
            "default" => $default,
        ];

        // Iterate over all providers and inject custom alert message in global message
        foreach ($providers as $provider)
        {
            if (!method_exists($this, $provider))
                throw new \Exception("Provider function doesn't exists for: ".$provider);

            // Insert provider alert message in global SNS message
            $message[$provider] = json_encode($this->$provider($alert, $data, $options));
        }

        // Default TTL one week
        $ttl = 604800;
        if (isset($options['time_to_live'])) {
            $ttl = $options['time_to_live'];
        }

        // Save our message if needed.
        if($save && $data['click_action'] == "custom_msg") {
            if(isset($alert['body']) && isset($alert['title'])) {
                try {
                    $this->ddb->putItem([
                        "TableName" => "CustomSnsMessages",
                        "Item" => [
                            "body"      => ["S" => $alert['body']],
                            "endpoints" => ["SS" => [$endpoint]],
                            "org_id"    => ["S" => $data['org_id']],
                            "timestamp" => ["N" => time()],
                            "title"     => ["S" => $alert['title']],
                        ],
                    ]);
                } catch(Exception $e) {
                }
            }
        }

        return $this->sns->publish([
                'TargetArn'         => $endpoint,
                'MessageStructure'  => 'json',
                'Message'           => json_encode($message),
                'MessageAttributes' => [
                    'AWS.SNS.MOBILE.APNS.TTL'         => [
                        "DataType"    => "String",
                        "StringValue" => "$ttl"
                    ],
                    'AWS.SNS.MOBILE.APNS_SANDBOX.TTL' =>[
                        "DataType"    => "String",
                        "StringValue" => "$ttl"
                    ],
                    'AWS.SNS.MOBILE.GCM.TTL'          =>[
                        "DataType"    => "String",
                        "StringValue" => "$ttl"
                    ]
                ]
            ]);
    }

    // Handles Google notifications. Can be overide if structure needs to be different
    protected function GCM($alert, $data, $options)
    {
        if (!empty($data))
            $payload = array_merge($alert, $data);

        $message = [
            "data" => [
                "payload" => $payload
            ]
        ];

        if (isset($options['GCM']))
            $message = array_merge($message, $options['GCM']);

        /* print("GCM MESSAGE\n"); */
        /* print_r($message); */

        return $message;
    }

    // Handles Apple notifications. Can be overide if structure needs to be different
    protected function APNS($alert, $data, $options)
    {

        if (isset($alert['body_loc_key'])) {
            $alert['loc_key'] = $alert['body_loc_key'];
            unset($alert['body_loc_key']);
        }
        if (isset($alert['body_loc_args'])) {
            $alert['loc_args'] = $alert['body_loc_args'];
            unset($alert['body_loc_args']);
        }

        $message = [
            "aps" => [
                "alert" => $alert
            ]
        ];

        if (isset($options['APNS']))
            $message['aps'] = array_merge($message['aps'], $options['APNS']);

        if (!empty($data))
            $message = array_merge($message, $data);

        /* print("APNS MESSAGE\n"); */
        /* print_r($message); */

        return $message;
    }

    protected function APNS_SANDBOX($alert, $data, $options)
    {
        return $this->APNS($alert, $data, $options);
    }
}
