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

class SnsHandler
{
    /** AWS region for SQS **/
    private $region;
    /** AWS SNS handler **/
    private $sns;

    public function __construct($region = false)
    {
        if (!$region &&
            !($region = getenv("AWS_DEFAULT_REGION")))
            throw new \Exception("Set 'AWS_DEFAULT_REGION' environment variable!");
        
        $this->region = $region;
        $this->debug  = $debug;

        $this->sns = SnsClient::factory([
                'region' => $region,
            ]);
    }

    public function createTopic($name)
    {
        return $this->sns->createTopic(array(
                'Name' => $name,
            ));
    }

    // $alert contains default keys handled by both Apple and Google.
    // We follow the Google formatting:
    // https://developers.google.com/cloud-messaging/http-server-ref#notification-payload-support
    public function publishToEndpoint(
        $endpoint,
        $alert,
        $data,
        $providers = ["GCM","APNS"],
        $default = "")
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
            $message[$provider] = $this->$provider($alert, $data);
        }

        return $this->sns->publish([
                'TargetArn'        => $endpoint,
                'MessageStructure' => 'json',
                'Message'          => $message
            ]);
    }

    // Handles Google notifications. Can be overide if structure needs to be different
    protected function GCM($alert, $data)
    {
        $payload = array_merge($alert, $data);
        
        $message = [
            "data" => [
                "payload" => $payload
            ]
        ];

        return $message;
    }

    // Handles Apple notifications. Can be overide if structure needs to be different
    protected function APNS($alert, $data)
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

        if (!empty($data))
            $message = array_merge($message, $data);

        return $message;
    }
}
