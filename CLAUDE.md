# aws-sns-php-handler

## What This Is

A PHP library for sending push notifications via AWS SNS. Handles both GCM (Google Cloud Messaging / Firebase Cloud Messaging) and APNS (Apple Push Notification Service) message formatting. Provides a simple interface for publishing notifications to SNS topics and endpoints.

## Tech Stack

- **Language**: PHP 7.0+
- **AWS SDK**: `aws/aws-sdk-php` 3.x — SNS client
- **Autoloading**: PSR-0 (namespace `SA`)

## Quick Start

```bash
# Installation
composer require bfansports/aws-sns-php-handler

# Usage in PHP code
use SA\SnsHandler;

$sns = new SnsHandler($awsRegion, $awsKey, $awsSecret);

// Publish to a topic
$topicArn = "arn:aws:sns:us-east-1:123456789012:MyTopic";
$message = "Hello fans!";
$sns->publishToTopic($topicArn, $message);

// Publish to a specific endpoint (device)
$endpointArn = "arn:aws:sns:us-east-1:123456789012:endpoint/GCM/MyApp/...";
$sns->publishToEndpoint($endpointArn, $message);

// Send platform-specific notifications
$sns->publishGCM($topicArn, "GCM message", ["badge" => 1]);
$sns->publishAPNS($topicArn, "APNS message", ["badge" => 1]);
```

## Project Structure

- `src/SA/SnsHandler.php` — Main handler class
- `composer.json` — Dependencies and autoload config
- `LICENSE` — MIT-style license
- `README.md` — Brief usage description
- `.github/` — GitHub Actions workflow (if present)

## Dependencies

**External:**
- AWS SNS — notification delivery service
- GCM/FCM — Google's push notification infrastructure
- APNS — Apple's push notification infrastructure

**Consumed by:**
- `sa_site_v2` — PHP admin panel for sending notifications to fans
- Backend Lambda functions — for event-driven notifications (new content, game updates, etc.)

<!-- Ask: Which specific Lambda functions or admin panel features use this library? -->

## API / Interface

**Main class**: `SA\SnsHandler`

**Constructor:**
```php
new SnsHandler(string $region, string $key, string $secret)
```
- `$region` — AWS region (e.g., "us-east-1")
- `$key` — AWS access key ID
- `$secret` — AWS secret access key

**Methods:**
- `publishToTopic($topicArn, $message, $subject = null)` — Publish a message to an SNS topic
- `publishToEndpoint($endpointArn, $message)` — Publish directly to a device endpoint
- `publishGCM($target, $message, $data = [])` — Format and publish GCM/FCM notification
- `publishAPNS($target, $message, $data = [])` — Format and publish APNS notification

**Notification payload**:
- `$message` — The notification text (alert body)
- `$data` — Optional payload (badge count, custom data, sound, etc.)

## Key Patterns

- **Platform-specific formatting**: GCM and APNS have different JSON structures. This library abstracts the differences.
- **SNS message structure**: Uses SNS's platform-specific message format (JSON with `default`, `GCM`, `APNS` keys).
- **Basic notification keys**: Supports common fields like badge, sound, and custom data. Does not expose every platform-specific option.
- **Direct AWS credentials**: Constructor takes AWS key/secret directly (not recommended for production; use IAM roles instead).

## Environment

**Required IAM permissions:**
- `sns:Publish` on topics and endpoints
- `sns:CreatePlatformEndpoint` if dynamically registering devices (not exposed by this library)

**AWS credentials**: Must be passed to constructor. Recommended approach:
- For Lambda: Use environment variables or IAM role; pass credentials from Lambda environment
- For EC2/ECS: Use IAM instance role
- For local dev: Use `~/.aws/credentials` and pass via environment variables

**No configuration files**: All config is passed programmatically.

## Deployment

**Distribution**: Packaged via Composer. To publish updates:
1. Tag a new version in GitHub (e.g., `v1.1.0`)
2. Packagist auto-updates if webhook is configured
3. Consumers update with `composer update bfansports/aws-sns-php-handler`

**No CI/CD pipeline**: Manual testing and versioning.

<!-- Ask: Is this library registered on Packagist, or do consumers install from GitHub directly? -->
<!-- Ask: What's the release process? Semantic versioning? Change log? -->

## Testing

<!-- Ask: Does this repo have unit tests? If so, how are they run? -->
<!-- Ask: Is there a test SNS topic for validating changes without spamming production users? -->

**Manual testing:**
- Instantiate `SnsHandler` in a test PHP script
- Point at a dev/staging SNS topic
- Send test notifications to test devices (iOS simulator, Android emulator, test apps)
- Verify notifications arrive with correct formatting

## Gotchas

- **PSR-0 autoloading**: Uses older PSR-0 standard, not PSR-4. Namespace `SA` maps to `src/SA/` directory.
- **Direct credentials**: Constructor takes AWS key/secret directly. For production, refactor to use IAM roles or AWS SDK's default credential chain.
- **GCM vs FCM**: GCM is deprecated by Google in favor of FCM (Firebase Cloud Messaging). SNS now uses FCM endpoints, but the message format is backward-compatible. This library still references "GCM" in method names.
- **Limited payload options**: Only exposes basic notification fields (message, badge, sound, custom data). For advanced features (notification channels, actions, rich media), you'd need to extend the library.
- **No retry logic**: If SNS publish fails, the method throws an exception. No automatic retry.
- **Platform endpoint registration**: This library does NOT handle creating or managing SNS platform endpoints (device tokens). That must be done separately via AWS SDK or another tool.
- **APNS sandbox vs production**: APNS has separate endpoints for development and production certificates. This library does not distinguish between them; the SNS platform application ARN must be configured correctly.

<!-- Ask: Are there any known issues with FCM migration? Does this library need updates for FCM-specific features? -->
<!-- Ask: How are device tokens registered? Is there another library or Lambda function handling that? -->
<!-- Ask: What's the error handling strategy? Are failed notifications logged or retried? -->