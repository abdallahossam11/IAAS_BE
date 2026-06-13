# AI Chat API — Integration Test Cases

> **Purpose:** Enumerated, executable scenarios validating the frozen contract in
> [`ai-chat-api-contract-v1.md`](./ai-chat-api-contract-v1.md) against a running AI-team service,
> plus the Laravel-side response validation that Phase 5B will implement.
> **Machine spec:** [`ai-chat-openapi-v1.yaml`](./ai-chat-openapi-v1.yaml).

## Legend

- **Direction** — *AI-service validation* (the AI team's endpoint validates the request) or
  *Laravel-client validation* (the future Phase 5B HTTP client validates the AI response).
- **retryable** — expected value of `error.retryable` where an error body applies; `—` otherwise.
- Headers shorthand: `BEARER` = `Authorization: Bearer <token>`; `CT` =
  `Content-Type: application/json; charset=utf-8`; `ACCEPT` = `Accept: application/json`;
  `XRID` = `X-Request-ID: <uuid equal to body request_id>`.
- `<rid>` denotes a UUID used identically in the header and the body.

> **Honesty note:** Cases marked *Laravel-client validation* describe behavior of the future
> `HttpStudentAiChatClient` / `HttpGuestAiChatClient` (Phase 5B), which do not exist yet. They are
> documented now so the AI team understands how Laravel will react to each response shape.

---

### TC-01 — Valid student request
- **Direction:** AI-service validation
- **Endpoint:** `POST /v1/chat/student/respond`
- **Headers:** BEARER (student), CT, ACCEPT, XRID
- **Body:** Frozen student schema; one `user` message; all 8 `student_context` fields.
- **Expected status:** `200`
- **Expected response / code:** `SuccessResponse`; `request_id` == `<rid>`; `status:"completed"`; non-empty `content`.
- **retryable:** —
- **Notes:** Baseline happy path.

### TC-02 — Valid guest request
- **Direction:** AI-service validation
- **Endpoint:** `POST /v1/chat/guest/respond`
- **Headers:** BEARER (guest), CT, ACCEPT, XRID
- **Body:** Frozen guest schema; `guest_session_reference` matches `^[a-f0-9]{64}$`.
- **Expected status:** `200`
- **Expected response / code:** `SuccessResponse`; `request_id` == `<rid>`; non-empty `content`.
- **retryable:** —
- **Notes:** Baseline happy path (guest).

### TC-03 — Missing Bearer token
- **Direction:** AI-service validation
- **Endpoint:** either responder
- **Headers:** CT, ACCEPT, XRID (no `Authorization`)
- **Body:** Valid request.
- **Expected status:** `401`
- **Expected response / code:** `UNAUTHORIZED`
- **retryable:** `false`
- **Notes:** `request_id` may be `null` or echoed.

### TC-04 — Invalid Bearer token
- **Direction:** AI-service validation
- **Endpoint:** either responder
- **Headers:** `Authorization: Bearer wrong-token`, CT, ACCEPT, XRID
- **Body:** Valid request.
- **Expected status:** `401`
- **Expected response / code:** `UNAUTHORIZED`
- **retryable:** `false`
- **Notes:** Wrong-side token (e.g. guest token on student endpoint) must also fail.

### TC-05 — Missing X-Request-ID
- **Direction:** AI-service validation
- **Endpoint:** either responder
- **Headers:** BEARER, CT, ACCEPT (no `X-Request-ID`)
- **Body:** Valid request with a `request_id`.
- **Expected status:** `400`
- **Expected response / code:** `INVALID_REQUEST`
- **retryable:** `false`
- **Notes:** Header is mandatory per contract §3.

### TC-06 — Body request_id differs from X-Request-ID
- **Direction:** AI-service validation
- **Endpoint:** either responder
- **Headers:** BEARER, CT, ACCEPT, `X-Request-ID: <uuid-A>`
- **Body:** `request_id: <uuid-B>` (B ≠ A).
- **Expected status:** `400`
- **Expected response / code:** `INVALID_REQUEST`
- **retryable:** `false`
- **Notes:** Header and body identifiers must be identical.

### TC-07 — Invalid schema_version
- **Direction:** AI-service validation
- **Endpoint:** either responder
- **Headers:** BEARER, CT, ACCEPT, XRID
- **Body:** `schema_version: "2.0"` (or missing).
- **Expected status:** `422`
- **Expected response / code:** `VALIDATION_ERROR`
- **retryable:** `false`
- **Notes:** Only `"1.0"` is accepted in V1.

### TC-08 — Unsupported message role
- **Direction:** AI-service validation
- **Endpoint:** either responder
- **Headers:** BEARER, CT, ACCEPT, XRID
- **Body:** A message with `role: "system"` (or any value outside `user`/`assistant`).
- **Expected status:** `422`
- **Expected response / code:** `VALIDATION_ERROR`
- **retryable:** `false`
- **Notes:** Role enum is strict.

### TC-09 — Empty request message content
- **Direction:** AI-service validation
- **Endpoint:** either responder
- **Headers:** BEARER, CT, ACCEPT, XRID
- **Body:** A message with `content: ""`.
- **Expected status:** `422`
- **Expected response / code:** `VALIDATION_ERROR`
- **retryable:** `false`
- **Notes:** `content` requires `minLength: 1`.

### TC-10 — Whitespace-only request message content
- **Direction:** AI-service validation
- **Endpoint:** either responder
- **Headers:** BEARER, CT, ACCEPT, XRID
- **Body:** A message with `content: "   "` (spaces/tabs/newlines only).
- **Expected status:** `422`
- **Expected response / code:** `VALIDATION_ERROR`
- **retryable:** `false`
- **Notes:** `content.trim()` must not be empty.

### TC-11 — Student payload unexpected extra field
- **Direction:** AI-service validation
- **Endpoint:** `POST /v1/chat/student/respond`
- **Headers:** BEARER (student), CT, ACCEPT, XRID
- **Body:** Valid student request plus an unexpected field (e.g. `student_context.password` or a top-level `debug: true`).
- **Expected status:** `400` or `422`
- **Expected response / code:** `INVALID_REQUEST` or `VALIDATION_ERROR`
- **retryable:** `false`
- **Notes:** `additionalProperties: false` everywhere. Forbidden fields (password hashes, tokens, vehicle/admin data, DB creds) must never be accepted.

### TC-12 — Guest payload contains raw token
- **Direction:** AI-service validation
- **Endpoint:** `POST /v1/chat/guest/respond`
- **Headers:** BEARER (guest), CT, ACCEPT, XRID
- **Body:** Guest request with an extra `guest_token` field (raw 64-char token) alongside `guest_session_reference`.
- **Expected status:** `400` or `422`
- **Expected response / code:** `INVALID_REQUEST` or `VALIDATION_ERROR`
- **retryable:** `false`
- **Notes:** Raw token is forbidden; only the SHA-256 reference is allowed. Strict schema rejects the extra field.

### TC-13 — Same request_id, same normalized payload → same result
- **Direction:** AI-service validation (idempotency)
- **Endpoint:** either responder
- **Headers:** BEARER, CT, ACCEPT, XRID (`<rid>` reused)
- **Body:** Identical payload sent twice (second may reorder object keys only).
- **Expected status:** `200` both times
- **Expected response / code:** `SuccessResponse`; **identical `content`** both times; processed once.
- **retryable:** —
- **Notes:** Key-order differences must not change semantic equality.

### TC-14 — Same request_id, different normalized payload → 409
- **Direction:** AI-service validation (idempotency)
- **Endpoint:** either responder
- **Headers:** BEARER, CT, ACCEPT, XRID (`<rid>` reused)
- **Body:** First a valid payload, then a **different** payload (e.g. changed message text) with the same `<rid>`.
- **Expected status:** `409`
- **Expected response / code:** `REQUEST_ID_CONFLICT`
- **retryable:** `false`
- **Notes:** Retention ≥ 24h.

### TC-15 — Malformed JSON response
- **Direction:** Laravel-client validation (Phase 5B)
- **Endpoint:** either responder (fault injection)
- **Headers:** BEARER, CT, ACCEPT, XRID
- **Body:** Valid request; AI service returns non-JSON / truncated JSON body with `200`.
- **Expected status:** n/a (response is malformed)
- **Expected response / code:** Laravel treats as invalid → internal `INVALID_AI_RESPONSE`; request marked failed.
- **retryable:** —
- **Notes:** The AI service must never emit malformed JSON; this verifies Laravel's defensive handling.

### TC-16 — Success response with empty content
- **Direction:** Laravel-client validation (Phase 5B)
- **Endpoint:** either responder (fault injection)
- **Headers:** BEARER, CT, ACCEPT, XRID
- **Body:** Valid request; AI returns `200` with `content: ""`.
- **Expected status:** n/a
- **Expected response / code:** Laravel rejects → internal `INVALID_AI_RESPONSE`; request failed.
- **retryable:** —
- **Notes:** Contract requires non-empty `content`; this is a contract violation by the AI service.

### TC-17 — Success response with whitespace-only content
- **Direction:** Laravel-client validation (Phase 5B)
- **Endpoint:** either responder (fault injection)
- **Headers:** BEARER, CT, ACCEPT, XRID
- **Body:** Valid request; AI returns `200` with `content: "   "`.
- **Expected status:** n/a
- **Expected response / code:** Laravel rejects → internal `INVALID_AI_RESPONSE`; request failed.
- **retryable:** —
- **Notes:** `content.trim()` must not be empty.

### TC-18 — Success response with mismatched request_id
- **Direction:** Laravel-client validation (Phase 5B)
- **Endpoint:** either responder (fault injection)
- **Headers:** BEARER, CT, ACCEPT, XRID (`<rid>`)
- **Body:** Valid request; AI returns `200` with a different `request_id`.
- **Expected status:** n/a
- **Expected response / code:** Laravel rejects → internal `INVALID_AI_RESPONSE`; request failed.
- **retryable:** —
- **Notes:** Response `request_id` must match the request.

### TC-19 — 429 response with Retry-After
- **Direction:** AI-service validation
- **Endpoint:** either responder
- **Headers:** BEARER, CT, ACCEPT, XRID
- **Body:** Valid request during rate-limited conditions.
- **Expected status:** `429`
- **Expected response / code:** `RATE_LIMITED`; `Retry-After: <seconds>` header present.
- **retryable:** `true`
- **Notes:** `retryable` is advisory; current jobs use `tries=1` (no auto-retry).

### TC-20 — 503 response with Retry-After
- **Direction:** AI-service validation
- **Endpoint:** either responder
- **Headers:** BEARER, CT, ACCEPT, XRID
- **Body:** Valid request while the service is unavailable.
- **Expected status:** `503`
- **Expected response / code:** `SERVICE_UNAVAILABLE`; `Retry-After: <seconds>` header present.
- **retryable:** `true`
- **Notes:** Advisory only in V1.

### TC-21 — 504 timeout response
- **Direction:** AI-service validation
- **Endpoint:** either responder
- **Headers:** BEARER, CT, ACCEPT, XRID
- **Body:** Valid request where the AI exceeds its internal time budget but still returns a body.
- **Expected status:** `504`
- **Expected response / code:** `AI_TIMEOUT`
- **retryable:** `true`
- **Notes:** Distinct from the transport-level timeout in TC-22.

### TC-22 — Response exceeds Laravel timeout
- **Direction:** Laravel-client validation (Phase 5B)
- **Endpoint:** either responder (fault injection)
- **Headers:** BEARER, CT, ACCEPT, XRID
- **Body:** Valid request; AI does not respond within 420 seconds.
- **Expected status:** n/a (no HTTP response received)
- **Expected response / code:** Laravel HTTP client times out → internal `TIMEOUT`; request failed via `failed()`.
- **retryable:** —
- **Notes:** Verifies the 10s connect / 420s response timeout from `config/chat.php`. AI target ≤ 400s avoids this.

### TC-23 — UTF-8 Arabic request and response
- **Direction:** AI-service validation + Laravel-client validation
- **Endpoint:** either responder
- **Headers:** BEARER, CT, ACCEPT, XRID
- **Body:** Valid request with Arabic `content` (e.g. `"كم عدد الساعات المتبقية لتخرّجي؟"`).
- **Expected status:** `200`
- **Expected response / code:** `SuccessResponse` with correctly encoded Arabic `content`.
- **retryable:** —
- **Notes:** Confirms UTF-8 round-trips without mojibake on both sides.

### TC-24 — Health endpoint returns minimal safe payload
- **Direction:** AI-service validation
- **Endpoint:** `GET /health`
- **Headers:** none (unauthenticated)
- **Body:** none
- **Expected status:** `200`
- **Expected response / code:** exactly `{"status":"ok"}` — no extra fields, no versions, no secrets.
- **retryable:** —
- **Notes:** Unauthenticated; strict schema (`additionalProperties: false`). Network-level restrictions may be applied by infrastructure but do not change the response.

---

## Coverage summary

| Area | Cases |
|---|---|
| Happy path | TC-01, TC-02, TC-23 |
| Authentication | TC-03, TC-04 |
| Request-ID / headers | TC-05, TC-06 |
| Request validation | TC-07, TC-08, TC-09, TC-10, TC-11, TC-12 |
| Idempotency | TC-13, TC-14 |
| Response validation (Laravel 5B) | TC-15, TC-16, TC-17, TC-18, TC-22 |
| Error responses | TC-19, TC-20, TC-21 |
| Health | TC-24 |

## Open decisions affecting tests

The following open items (see contract §16) may add or refine cases once confirmed: maximum
request-body size, maximum history size, supported languages beyond `auto`, and any future
automatic-retry policy.
