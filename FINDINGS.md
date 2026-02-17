# Security Audit: aws-sns-php-handler

**Date:** 2026-02-17  
**Auditor:** AI Backend Developer (bFAN Agent Hub)  
**Scope:** `src/SA/SnsHandler.php` — single-file library, 392 lines  
**Method:** Two-pass manual review (architecture + line-by-line)  
**Repo version:** `0c0d997` (tag 2.2.5)

---

## Critical

### C1: No SNS Signature Verification (Spoofing Risk)

**Severity:** Critical  
**File:** N/A — missing entirely  
**Impact:** Any HTTP client can impersonate AWS SNS

Despite the name "handler", this library is a **publisher only** — it sends notifications outbound via the AWS SDK. It does **not** receive or process inbound SNS HTTP(S) notifications.

However, this creates a critical architectural gap: **there is no companion code anywhere in the bFAN ecosystem for verifying inbound SNS messages**. AWS SNS sends HTTP POST callbacks for:
- `SubscriptionConfirmation` — must be verified before confirming
- `Notification` — must be verified before processing
- `UnsubscribeConfirmation` — must be verified before acting

Without signature verification, an attacker can:
1. Forge SNS subscription confirmations to subscribe arbitrary endpoints
2. Inject fake notification payloads that the application trusts as genuine
3. Trigger business logic (push notifications, DB writes) with spoofed data

**Recommendation:**  
Add an `SnsMessageValidator` class to this library (or create a separate one). AWS provides the [SNS Message Signing](https://docs.aws.amazon.com/sns/latest/dg/sns-verify-signature-of-message.html) specification. A proper implementation must:
1. Download the signing certificate from the `SigningCertURL` field
2. Validate the certificate URL is from `sns.<region>.amazonaws.com` (prevent SSRF)
3. Verify the signature using `openssl_verify()` with the certificate's public key
4. Validate the `SignatureVersion` is `"1"` or `"2"`
5. Build the canonical string-to-sign from the correct fields based on message type

Alternatively, use the official [aws/aws-php-sns-message-validator](https://github.com/aws/aws-php-sns-message-validator) package.

---

### C2: Hardcoded DynamoDB Table Name

**Severity:** Critical (operational)  
**File:** `src/SA/SnsHandler.php`, line 209  
**Code:** `"TableName" => "CustomSnsMessages"`

The DynamoDB table name is hardcoded as `"CustomSnsMessages"`. This means:
- Cannot use different tables per environment (dev/staging/prod)
- Cannot run multiple orgs on different tables
- Table name changes require code changes and redeployment
- No way to override without modifying source

**Recommendation:**  
Accept table name via constructor parameter or environment variable (`SNS_MESSAGES_TABLE`). Fall back to `"CustomSnsMessages"` for backward compatibility.

---

## High

### H1: No Input Validation on Endpoint ARN

**Severity:** High  
**File:** `src/SA/SnsHandler.php`, lines 50-61, 114  
**Impact:** Allows publishing to arbitrary ARNs including cross-account targets

The `$endpoint` parameter in `publishToEndpoint()` is passed directly to `$this->sns->publish()` as `TargetArn` without any validation. A malicious or buggy caller could:
- Publish to an ARN in a different AWS account
- Publish to an SNS topic ARN instead of an endpoint ARN
- Pass malformed strings causing unclear AWS SDK errors

**Recommendation:**  
Validate that `$endpoint` matches the expected ARN pattern:
```php
if (!preg_match('/^arn:aws:sns:[a-z0-9-]+:\d{12}:endpoint\/(APNS|APNS_SANDBOX|GCM)\//', $endpoint)) {
    throw new \InvalidArgumentException('Invalid SNS endpoint ARN format');
}
```

---

### H2: Dynamic Method Invocation via User-Controlled Provider Names

**Severity:** High  
**File:** `src/SA/SnsHandler.php`, lines 77-87  
**Code:**
```php
foreach ($providers as $provider) {
    if (!method_exists($this, $provider)) {
        throw new \Exception("Provider function doesn't exists for: " . $provider);
    }
    $message[$provider] = json_encode(
        $this->$provider($alert, $data, $options, $identity_id)
    );
}
```

The `$providers` array controls which methods are called via `$this->$provider(...)`. While `method_exists()` provides a gate, it checks **all** methods on the object, including:
- `__construct`, `__destruct`, `__toString` (magic methods)
- `saveNotifInDB` (private, but `method_exists` returns true for private methods)
- `log_wrapper`, `getMarshalledSegmentCounts`
- Any methods from parent classes if this class is extended

An attacker who controls the `$providers` parameter could invoke `saveNotifInDB` or `log_wrapper` with unexpected arguments, or trigger magic methods.

**Recommendation:**  
Whitelist valid providers explicitly:
```php
$validProviders = ['GCM', 'APNS', 'APNS_SANDBOX'];
foreach ($providers as $provider) {
    if (!in_array($provider, $validProviders, true)) {
        throw new \InvalidArgumentException("Invalid provider: $provider");
    }
    // ...
}
```

---

### H3: Incorrect Exception Catch in saveNotifInDB

**Severity:** High (silent data loss)  
**File:** `src/SA/SnsHandler.php`, line 253  
**Code:** `} catch (Exception $e) {`

The class is namespaced (`namespace SA;`), so `Exception` resolves to `SA\Exception`, which does not exist. This catch block will **never** catch anything. All DynamoDB errors (throttling, missing table, permission errors, validation errors) will propagate as unhandled exceptions, potentially crashing the calling process.

Should be `\Exception` (with leading backslash) to reference the global PHP exception class.

**Recommendation:**  
```php
} catch (\Exception $e) {
```

---

### H4: No Type Validation on Constructor Parameter

**Severity:** High  
**File:** `src/SA/SnsHandler.php`, line 21  
**Code:** `public function __construct($region = false)`

The constructor accepts any type for `$region`. If a non-string truthy value is passed (array, object, integer), it will be passed to the AWS SDK clients, causing unclear downstream errors. The `false` default is also non-standard — `null` is the conventional PHP default for optional parameters.

**Recommendation:**  
Add type declaration: `public function __construct(?string $region = null)` and validate the region format.

---

## Medium

### M1: GCM References Are Deprecated

**Severity:** Medium (technical debt)  
**File:** `src/SA/SnsHandler.php`, lines 55, 85, 98, 127-130, 366-391  

Google Cloud Messaging (GCM) was fully shut down by Google. AWS SNS migrated GCM platform applications to FCM (Firebase Cloud Messaging) but kept the `GCM` key in the SNS message structure for backward compatibility. While the current code still works, it causes confusion:
- New developers don't understand why it says "GCM"
- The `GCM()` method builds a legacy message format
- FCM v1 API features (analytics labels, notification channels) cannot be used with the old GCM format

**Recommendation:**  
Add FCM-aware formatting. Consider an `FCM()` method alias. Update comments to explain the GCM/FCM relationship.

---

### M2: TTL Not Validated

**Severity:** Medium  
**File:** `src/SA/SnsHandler.php`, lines 91-94  
**Code:**
```php
$ttl = 604800;
if (isset($options['time_to_live'])) {
    $ttl = $options['time_to_live'];
}
```

The TTL value is taken from user input without validation. A negative TTL, zero TTL, extremely large TTL, or non-numeric value could cause unexpected behavior:
- APNS has a maximum TTL of 30 days
- GCM/FCM has a maximum TTL of 4 weeks (2,419,200 seconds)
- Non-numeric values would be passed as strings to SNS message attributes

**Recommendation:**  
```php
$ttl = 604800; // Default: 1 week
if (isset($options['time_to_live'])) {
    $ttl = max(0, min((int)$options['time_to_live'], 2419200));
}
```

---

### M3: Logging Leaks Notification Content

**Severity:** Medium  
**File:** `src/SA/SnsHandler.php`, lines 96-99  
**Code:**
```php
$this->log_wrapper("INFO", 'SNS_Messages:' . json_encode([
    'APNS' => json_decode($message['APNS']),
    'GCM' => json_decode($message['GCM'])
]));
```

Full notification payloads (including alert text, custom data, org_id, identity_id, deeplinks) are logged at INFO level. In production, this means:
- PII in notification bodies ends up in log files
- Custom data payloads (potentially containing tokens, URLs) are logged
- Log aggregation systems (CloudWatch, ELK) store sensitive notification content

**Recommendation:**  
Log only metadata (org_id, endpoint count, provider types). If full payload logging is needed, gate it behind a DEBUG level or environment flag.

---

### M4: `publishToEndpoints` Silently Swallows Individual Failures

**Severity:** Medium  
**File:** `src/SA/SnsHandler.php`, lines 157-177  

When publishing to multiple endpoints, individual failures are caught and logged but:
- No failure count is tracked
- No return value indicates which endpoints failed
- The caller has no way to know if 1 or all endpoints failed
- The `saveNotifInDB` call at line 181 still records the notification as if all succeeded

**Recommendation:**  
Return a results array with success/failure per endpoint. Track failure count. Only save to DynamoDB with actual successful endpoint list.

---

### M5: DynamoDB Write Uses Timestamp as Sort Key Without Uniqueness

**Severity:** Medium  
**File:** `src/SA/SnsHandler.php`, line 214  
**Code:** `"timestamp" => ["N" => (string) time()]`

`time()` returns Unix seconds. If two notifications are sent in the same second for the same org, the second write could overwrite the first (depending on table key schema). No UUID or unique identifier is included.

**Recommendation:**  
Add a UUID field or use millisecond timestamps (`microtime(true) * 1000`) to reduce collision risk.

---

### M6: `array_merge_recursive` Can Produce Unexpected Nested Arrays

**Severity:** Medium  
**File:** `src/SA/SnsHandler.php`, lines 351, 374  

`array_merge_recursive()` merges values with the same key into arrays rather than overwriting. If `$options['APNS']` contains an `aps.alert` key that already exists in `$message`, the result will be an array of arrays instead of a clean override. This can produce malformed APNS payloads that iOS silently drops.

**Recommendation:**  
Use `array_replace_recursive()` instead, which overwrites duplicate keys.

---

## Low

### L1: No PHP Version Constraint in composer.json Beyond 7.0

**Severity:** Low  
**File:** `composer.json`, line 14  
**Code:** `"php": ">=7.0"`

PHP 7.0 reached end-of-life in December 2018. The code uses no features that require 7.0+, but also doesn't use newer features (typed properties, union types, named arguments) that could improve safety. The constraint should reflect the actually-tested minimum.

---

### L2: Fallback Logger Echoes Directly

**Severity:** Low  
**File:** `src/SA/SnsHandler.php`, lines 264-274  

When `log_message()` function doesn't exist, the fallback uses `echo`. In a web request context, this writes directly to the HTTP response body, potentially leaking internal details to clients. In a CLI/daemon context, it goes to stdout which may not be captured.

**Recommendation:**  
Use `error_log()` instead of `echo` for the fallback, so output goes to the PHP error log regardless of SAPI.

---

### L3: AWS SDK Version Pinned to "latest"

**Severity:** Low  
**File:** `src/SA/SnsHandler.php`, lines 34, 40  
**Code:** `"version" => "latest"`

Using `"latest"` for the AWS SDK API version means behavior can change when the SDK is updated. AWS recommends pinning to a specific API date version (e.g., `"2010-03-31"` for SNS, `"2012-08-10"` for DynamoDB).

---

### L4: Missing Return Type Declarations

**Severity:** Low  
**File:** `src/SA/SnsHandler.php` — all public methods  

No return types are declared on any method. `publishToEndpoint` returns the SNS SDK result or `null` (implicit, via early return on line 69). `publishToEndpoints` returns nothing. This makes the API contract unclear for callers.

---

### L5: GitHub Actions Workflow Targets Wrong Branch

**Severity:** Low  
**File:** `.github/workflows/github-backup.yml`, line 5  
**Code:** `branches: - develop`

The workflow triggers on push to `develop`, but the repo's default branch is `master`. The backup workflow never runs.

---

## Agent Skill Improvements

### CLAUDE.md Inaccuracies Found

The existing `CLAUDE.md` contains several inaccuracies that will mislead AI agents:

1. **Constructor signature is wrong.** CLAUDE.md documents `new SnsHandler(string $region, string $key, string $secret)` but the actual constructor is `new SnsHandler($region = false)` — it uses `CredentialProvider::defaultProvider()`, not explicit key/secret parameters.

2. **Methods listed don't exist.** CLAUDE.md lists `publishToTopic()`, `publishGCM()`, `publishAPNS()` — none of these methods exist in the code. The actual public methods are `publishToEndpoint()` and `publishToEndpoints()`.

3. **"Direct credentials" gotcha is wrong.** CLAUDE.md warns about direct AWS credentials in the constructor, but the code uses `CredentialProvider::defaultProvider()` (the recommended approach). This gotcha doesn't apply.

4. **Missing DynamoDB dependency.** The library writes to DynamoDB (`CustomSnsMessages` table) but CLAUDE.md doesn't mention this dependency or the required `dynamodb:PutItem` IAM permission.

5. **Consumer list is incomplete.** Code search shows `sa_site_daemons` (not `sa_site_v2`) consumes this library. Multiple Python `SnsHandler` classes exist in other repos but are separate implementations, not consumers of this PHP library.

These are corrected in the updated CLAUDE.md submitted with this PR.

---

## Positive Observations

1. **Uses AWS SDK credential chain.** The constructor uses `CredentialProvider::defaultProvider()`, which is the recommended approach for credential management. No hardcoded keys.

2. **Provider pattern is extensible.** The `APNS()`, `APNS_SANDBOX()`, and `GCM()` methods are `protected`, allowing subclasses to override message formatting per-platform.

3. **Defensive empty checks.** Both `publishToEndpoint()` and `publishToEndpoints()` check for empty endpoint/alert data before proceeding.

4. **Error isolation in batch sends.** `publishToEndpoints()` catches exceptions per-endpoint so one failure doesn't abort the entire batch.

5. **Clean namespace usage.** Single class, clear namespace (`SA`), no global function pollution.

6. **Minimal dependency footprint.** Only depends on `aws/aws-sdk-php` — no unnecessary third-party libraries.
