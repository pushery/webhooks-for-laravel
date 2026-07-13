# Standard Webhooks interop vectors

`standard-webhooks-vectors.json` publishes golden known-answer vectors for the
signature dialects this package sends: the symmetric [Standard
Webhooks](https://www.standardwebhooks.com) `v1` dialect (the default) and its
asymmetric Ed25519 `v1a` variant. They exist so a third-party receiver — or a port of
the verifier to another language — can prove it is byte-for-byte compatible with the
signatures this package produces, without trusting this package's own code.

Every key in the file is test material published on purpose. Never use one for real
traffic.

## The wire format

Every delivery carries three headers:

| Header              | Value                                               |
| ------------------- | --------------------------------------------------- |
| `webhook-id`        | The stable message id (also the idempotency key).   |
| `webhook-timestamp` | The Unix timestamp (seconds) the delivery was signed. |
| `webhook-signature` | One or more space-separated entries: `v1,<base64>` (symmetric) or `v1a,<base64>` (Ed25519). |

Both dialects sign the exact same string

```
{webhook-id}.{webhook-timestamp}.{rawBody}
```

`v1` computes `HMAC-SHA256` over it and base64-encodes the digest. The signing key is
derived from the secret by stripping an optional `whsec_` prefix and base64-decoding
the remainder to the raw key bytes.

`v1a` computes an Ed25519 detached signature over the same string and base64-encodes
it. Its keys are base64 of the raw libsodium key bytes, with an optional `whpk_`
(public) or `whsk_` (secret) prefix that is stripped before decoding. A receiver only
ever holds the public key.

During a secret rotation the header presents more than one entry (current plus
previous), separated by a single space; a receiver accepts the request if **any**
entry verifies. Verifiers must ignore entries whose version tag they do not implement
— a `v1` verifier skips a `v1a` entry, and the other way round.

## The file

```jsonc
{
  "_comment": "What the file is — and that every key in it is test material.",

  // Symmetric v1 known-answer vectors.
  "vectors": [
    {
      "name":      "standard-webhooks-spec-example",
      "secret":    "whsec_…",    // the endpoint secret
      "id":        "msg_…",      // webhook-id
      "timestamp": 1614265330,   // webhook-timestamp (Unix seconds)
      "payload":   "{\"test\": 2432232314}", // the exact raw body bytes
      "signature": "v1,g0hM9SsE+…"           // the expected webhook-signature value
    }
  ],

  // Asymmetric v1a (Ed25519) known-answer vectors. Ed25519 signing is
  // deterministic, so the secret key reproduces the signature exactly.
  "ed25519_vectors": [
    {
      "name":       "ed25519-v1a-known-answer",
      "public_key": "whpk_…",    // what a receiver holds
      "secret_key": "whsk_…",    // what the sender signs with
      "id":         "msg_…",
      "timestamp":  1614265330,
      "payload":    "{\"test\": 2432232314}",
      "signature":  "v1a,2mI3zbNZ…"
    }
  ],

  // Vectors that MUST FAIL to verify. Each carries its "dialect" ("v1" or "v1a"),
  // the key to verify under ("secret" or "public_key"), and a "reason".
  "negative_vectors": [
    {
      "name":      "v1-tampered-payload",
      "dialect":   "v1",
      "secret":    "whsec_…",
      "id":        "msg_…",
      "timestamp": 1614265330,
      "payload":   "{\"test\": 2432232315}",
      "signature": "v1,g0hM9SsE+…",
      "reason":    "The signature belongs to a different payload."
    }
  ]
}
```

## How to verify

**`vectors` (v1).** For each vector, compute
`base64(hmac_sha256(key, "{id}.{timestamp}.{payload}"))`, where `key` is the
base64-decoded secret (minus the `whsec_` prefix), and confirm the result equals the
base64 portion of the vector's `signature` (the part after `v1,`). Use a constant-time
comparison in production code.

**`ed25519_vectors` (v1a).** For each vector, verify the base64-decoded signature (the
part after `v1a,`) over the same signed string with the base64-decoded `public_key`
(minus the `whpk_` prefix). If your port signs as well as verifies, signing that string
with the decoded `secret_key` must reproduce the published signature byte for byte.

**`negative_vectors`.** Run each through the verification of its `dialect` — with the
timestamp tolerance relaxed, since these vectors are deliberately old — and confirm
that **every one is rejected**. A verifier that accepts everything passes the positive
vectors too, so this half of the file is what proves an implementation is real.

The first `vectors` entry is the canonical example from the Standard Webhooks
specification, so a correct implementation in any language reproduces its signature
exactly.
