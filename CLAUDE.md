# aws-sns-php-handler

## What This Is

A PHP library for **sending** (publishing) push notifications via AWS SNS to mobile devices. Formats platform-specific payloads for APNS (Apple) and GCM/FCM (Google), publishes via the AWS SDK, and logs sent notifications to a DynamoDB table. Despite the name "handler", this is a **publisher only** — it does not receive or validate inbound SNS messages.

## Tech Stack

- **Language**: PHP 7.0+
- **AWS SDK**: `aws/aws-sdk-php` 3.x — SNS client + DynamoDB client
- **Autoloading**: PSR-0 (namespace `SA` maps to `src/SA/`)

## Quick Start

```bash
# Installation
composer require bfansports/aws-sns-php-handler
```

```php
use SA\SnsHandler;

// Constructor uses AWS default credential chain (IAM role, env vars, ~/.aws/credentials)
// Optionally pass region; falls back to AWS_DEFAULT_REGION env var
$sns = new SnsHandler('us-east-1');

// Publish to a single device endpoint
$sns->publishToEndpoint(
    'arn:aws:sns:us-east-1:123456789012:endpoint/GCM/MyApp/...',
    ['title' => 'Game Day!', 'body' => 'Kickoff in 30 minutes'],
    ['org_id' => 'team-123', 'type' => 'event'],
    ['time_to_live' => 3600]
);

// Publish to multiple endpoints (batch)
$sns->publishToEndpoints(
    $endpointArns,
    $alert,
    $data,
    $options
);
```

## Project Structure

- `src/SA/SnsHandler.php` — Main handler class (single file, ~392 lines)
- `composer.json` — Dependencies and PSR-0 autoload config
- `LICENSE` — MIT-style license
- `README.md` — Brief usage description
- `.github/workflows/` — GitHub Actions (S3 backup, currently targets wrong branch)

## Message Flow

```
Caller (sa_site_daemons / Lambda)
  │
  ├─ publishToEndpoint() or publishToEndpoints()
  │    │
  │    ├─ For each provider (GCM, APNS, APNS_SANDBOX):
  │    │    └─ Call $this->$provider() to build platform-specific payload
  │    │         ├─ APNS(): Builds {aps: {alert: ...}, data: ...}
  │    │         ├─ APNS_SANDBOX(): Delegates to APNS()
  │    │         └─ GCM(): Builds {data: {notification: ..., data: ...}, priority: 'high'}
  │    │
  │    ├─ Combine into SNS multi-platform message JSON
  │    │    {"default": "", "GCM": "...", "APNS": "...", "APNS_SANDBOX": "..."}
  │    │
  │    ├─ Set TTL via MessageAttributes (default: 1 week / 604800s)
  │    │
  │    ├─ $this->sns->publish() → AWS SNS API
  │    │
  │    └─ saveNotifInDB() → DynamoDB "CustomSnsMessages" table
  │         ├─ Stores: body, title, endpoints, org_id, timestamp, marketing flag
  │         ├─ Optional: deeplink, identity_id, segments, segment_counts, click_action
  │         └─ On error: logs and continues (but catch block has namespace bug — see FINDINGS.md)
  │
  └─ Returns SNS publish result (single) or void (batch)
```

## Dependencies

**AWS Services Used:**
- **SNS** — `sns:Publish` to endpoint ARNs
- **DynamoDB** — `dynamodb:PutItem` to `CustomSnsMessages` table

**Required IAM Permissions:**
```json
{
  "Effect": "Allow",
  "Action": ["sns:Publish"],
  "Resource": ["arn:aws:sns:*:*:endpoint/*"]
}
```
```json
{
  "Effect": "Allow",
  "Action": ["dynamodb:PutItem"],
  "Resource": ["arn:aws:dynamodb:*:*:table/CustomSnsMessages"]
}
```

**Consumed by (PHP):**
- `sa_site_daemons` — PHP CLI daemons for scheduled notifications, prediction actions, user stats
  - `src/SASite/UserStatsCommand.php`
  - `src/SASite/ScheduledTasksCommand.php`
  - `src/SASite/PredictionActionsCommand.php`

**Note:** Python backend repos have their own `SnsHandler` classes (not this library).

## API / Interface

**Main class**: `SA\SnsHandler`

**Constructor:**
```php
new SnsHandler(?string $region = null)
```
- `$region` — AWS region code. Falls back to `AWS_DEFAULT_REGION` env var. Throws if neither is set.
- Uses `CredentialProvider::defaultProvider()` for AWS credentials (IAM role, env vars, credential file).

**Public Methods:**

| Method | Returns | Description |
|--------|---------|-------------|
| `publishToEndpoint($endpoint, $alert, $data, $options, ...)` | SNS Result | Publish to one device endpoint |
| `publishToEndpoints($endpoints, $alert, $data, $options, ...)` | void | Publish to multiple endpoints (batch) |

**`publishToEndpoint` Parameters:**
- `$endpoint` (string) — SNS endpoint ARN
- `$alert` (array) — Notification alert: `['title' => '...', 'body' => '...']` + optional localization keys
- `$data` (array) — Custom payload: must include `org_id`, optionally `type`, `subtype`, `deeplink`
- `$options` (array) — Platform-specific overrides: `['GCM' => [...], 'APNS' => [...], 'time_to_live' => int]`
- `$providers` (array) — Default: `['GCM', 'APNS', 'APNS_SANDBOX']`
- `$default` (string) — Default message for unsupported platforms
- `$save` (bool) — Whether to save to DynamoDB (default: true)
- `$identity_id` (string|null) — Cognito identity ID
- `$segments` (array|null) — Audience segments (prefixed: `fav_*`, `lang_*`)
- `$marketing` (bool) — Marketing flag for DynamoDB record

**Protected Methods (overridable in subclasses):**
- `APNS($alert, $data, $options, $identity_id)` — Build APNS payload
- `APNS_SANDBOX(...)` — Delegates to `APNS()`
- `GCM($alert, $data, $options, $identity_id)` — Build GCM/FCM payload

## Key Patterns

- **Platform-specific formatting**: GCM and APNS have different JSON structures. The library abstracts this via provider methods.
- **SNS multi-platform message**: Uses `MessageStructure: 'json'` with `default`, `GCM`, `APNS`, `APNS_SANDBOX` keys.
- **APNS localization**: Translates camelCase alert keys (`bodyLocKey`, `titleLocArgs`) to Apple's hyphenated format (`loc-key`, `title-loc-args`).
- **Credential chain**: Uses `CredentialProvider::defaultProvider()` — no hardcoded keys. Works with IAM roles, env vars, credential files.
- **DynamoDB audit trail**: Every notification (when `$save=true`) is recorded in `CustomSnsMessages` with org_id, endpoints, timestamp, segments.

## Environment

**Required environment variable (if region not passed to constructor):**
- `AWS_DEFAULT_REGION` — AWS region code

**AWS credentials** are resolved via the default provider chain. No env vars needed if running on EC2/ECS/Lambda with an IAM role.

**DynamoDB table:** `CustomSnsMessages` must exist in the same region. No automatic table creation.

## Deployment

**Distribution**: Packaged via Composer from GitHub (version `2.2.5` / `dev-master`).

**No CI/CD pipeline**: Manual testing and versioning.
**No unit tests**: All testing is manual against dev/staging SNS topics.

## Gotchas

- **PSR-0 autoloading**: Uses older PSR-0 standard, not PSR-4. Namespace `SA` maps to `src/SA/` directory. Changing the directory structure requires updating `composer.json`.
- **Namespace bug in catch block**: `saveNotifInDB()` catches `Exception` without leading backslash — resolves to `SA\Exception` which doesn't exist. DynamoDB errors are never caught. (See FINDINGS.md H3)
- **GCM is deprecated**: Google shut down GCM; AWS SNS migrated to FCM but kept the `GCM` key in message structure. Code still works but method names are misleading.
- **Hardcoded table name**: DynamoDB table `CustomSnsMessages` is hardcoded — no way to configure per-environment without code changes.
- **Dynamic method dispatch**: Provider methods are called via `$this->$provider()` — the only gate is `method_exists()` which checks ALL methods, not just providers. Should be whitelisted.
- **`array_merge_recursive` gotcha**: Used for merging APNS/GCM options. If caller passes keys that overlap with the base message structure, values become nested arrays instead of overwriting. Use `array_replace_recursive` instead.
- **TTL not validated**: `options['time_to_live']` is used without bounds checking. APNS max is 30 days; GCM/FCM max is 4 weeks.
- **Batch send swallows errors**: `publishToEndpoints()` catches per-endpoint errors but doesn't report which failed. The DynamoDB record still lists all endpoints as if all succeeded.
- **No return from batch**: `publishToEndpoints()` returns void — caller cannot know results.
- **Fallback logger uses echo**: When `log_message()` function doesn't exist, falls back to `echo` which writes to HTTP response in web contexts. Use `error_log()` instead.
- **GitHub Actions targets wrong branch**: `.github/workflows/github-backup.yml` triggers on `develop` but default branch is `master`. Backup never runs.
- **No input validation on ARNs**: Endpoint ARNs are passed directly to SNS without format validation.

## Security Considerations

- **No inbound message verification**: This library only publishes. If your application receives SNS HTTP callbacks, you MUST verify signatures separately. Use [aws/aws-php-sns-message-validator](https://github.com/aws/aws-php-sns-message-validator).
- **Logging leaks payload content**: Full notification payloads (including PII in alert text, custom data, deeplinks) are logged at INFO level. Consider gating behind DEBUG or logging only metadata.
- **No ARN validation**: Endpoint ARNs are not validated before publishing. A buggy caller could publish to arbitrary ARNs.

## Testing

**No automated tests exist.** Manual testing only:
1. Instantiate `SnsHandler` in a test PHP script
2. Point at a dev/staging SNS platform application
3. Send test notifications to test devices
4. Verify notifications arrive with correct formatting
5. Check DynamoDB `CustomSnsMessages` table for the audit record

<!-- Ask: Is this library registered on Packagist, or do consumers install from GitHub directly? -->
<!-- Ask: What's the release process? Semantic versioning? Change log? -->
<!-- Ask: Is there a test SNS platform application for validating changes? -->
<!-- Ask: Are there any known issues with FCM migration? -->
<!-- Ask: How are device tokens (endpoint ARNs) registered and managed? -->
