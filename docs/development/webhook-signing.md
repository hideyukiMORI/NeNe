# Webhook Signing (HMAC-SHA256)

NeNe provides `WebhookSigner` for both sending and receiving HMAC-signed webhooks.

## Framework class: `WebhookSigner`

| Method | Description |
|--------|-------------|
| `sign(string $body, ?int $ts): string` | Generate `X-Webhook-Signature` header value |
| `verify(string $body, string $header): bool` | Verify inbound signature |
| `static generateSecret(): string` | Generate a 256-bit random secret |

## Inbound (receiving webhooks)

```php
$signer  = new WebhookSigner((string)getenv('WEBHOOK_SECRET'));
$rawBody = (string)file_get_contents('php://input');
$header  = $_SERVER['HTTP_X_WEBHOOK_SIGNATURE'] ?? '';

if (!$signer->verify($rawBody, $header)) {
    return $this->API_RESPONSE->failure('WEBHOOK-SIGNATURE-INVALID');
}

$payload = json_decode($rawBody, true);
// process $payload
```

## Outbound (sending webhooks)

```php
$signer  = new WebhookSigner($endpoint->secret);
$body    = json_encode($event, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
$header  = $signer->sign($body);

// Use cURL or stream context:
$context = stream_context_create(['http' => [
    'method'  => 'POST',
    'header'  => "Content-Type: application/json\r\nX-Webhook-Signature: {$header}",
    'content' => $body,
    'timeout' => 10,
]]);
file_get_contents($endpoint->url, false, $context);
```

## Signature format

```
X-Webhook-Signature: t=1716800000,v1=a3f4b2...  (64-char HMAC-SHA256 hex)
```

The signed payload is `"{timestamp}.{rawBody}"`. Including the timestamp in the
signed data prevents replay attacks — changing the timestamp invalidates the
signature.

## Secret management

```php
// Generate and store once (show to owner only once)
$secret = WebhookSigner::generateSecret();  // 64-char hex
// Store hash('sha256', $secret) in DB; send $secret to the endpoint owner
```

## Security notes

- `verify()` uses `hash_equals()` (constant-time comparison) — never use `===`.
- Default tolerance: ±300 seconds. Reject requests outside this window.
- Never expose the raw secret in API responses (store only the hash in DB).
- SSRF: validate webhook destination URLs against private IP ranges before sending.
  See `docs/development/idor-prevention.md` for general defence patterns.
