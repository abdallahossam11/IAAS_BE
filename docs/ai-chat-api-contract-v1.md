# AI Chat API Contract — v1

> **Status:** Frozen contract for the AI team to implement.
> **Audience:** AI service engineers, Laravel backend engineers, integrators.
> **Authority:** Derived from `AGENTS.md`, `docs/chatbot-v1-phase0-freeze-addendum.md`, and the
> inspected `StudentPayloadBuilder`, `GuestPayloadBuilder`, `ProcessStudentAiChat`,
> `ProcessGuestAiChat`, and `config/chat.php`.

This document defines the **request/response contract between the Laravel backend and the AI
team's HTTP service**. It is the single source of truth. The machine-readable form is
[`ai-chat-openapi-v1.yaml`](./ai-chat-openapi-v1.yaml). Go-live steps are in
[`ai-chat-team-handoff-checklist.md`](./ai-chat-team-handoff-checklist.md). Test scenarios are in
[`ai-chat-integration-test-cases.md`](./ai-chat-integration-test-cases.md).

---

## 1. Architecture (Frozen)

```
Frontend
  → Laravel API
  → Redis ai-chat queue
  → Laravel worker
  → AI team API
  → Laravel stores result
  → Frontend polls Laravel
```

Frozen rules:

- **Laravel is the only component allowed to access the project database.**
- **The AI team must never receive database credentials.**
- **The AI team must never receive phpMyAdmin credentials.**
- **The frontend must never call the AI API directly.**
- **The AI API must never receive raw Laravel secrets.**

The AI API is a request/response responder. It receives a fully prepared payload, produces an
answer, and returns it. Its application instances may remain stateless, but the AI service must
use a shared idempotency store retaining request results for **at least 24 hours** (see §11). It
does not read or write the project database, does not call back into Laravel, and is never
reachable directly by the frontend.

---

## 2. Endpoint Convention

Recommended AI-team paths:

```
POST /v1/chat/student/respond
POST /v1/chat/guest/respond
GET  /health
```

The Laravel backend reads complete callable endpoint URLs from environment variables:

```env
STUDENT_AI_API_URL=
GUEST_AI_API_URL=
```

Explicit rules:

- **The documented OpenAPI paths are the canonical operations.**
- **Laravel stores complete callable endpoint URLs in environment variables and does not append
  paths.** Each environment variable holds the complete, final URL the worker will `POST` to.
- **The AI team supplies the final full endpoint URLs.**
- **A reverse proxy or gateway may rewrite paths only when the final callable URLs are supplied
  and the documented behavior remains unchanged.**
- **No real domains are invented in this contract.** Placeholders such as
  `https://ai.example.invalid/v1/chat/student/respond` are illustrative only.

The student and guest services are **two separate APIs** with separate URLs and separate tokens.
They may be deployed as one service exposing two paths or as two services; either is acceptable
as long as the two URLs and two tokens remain independently configurable and independently
revocable.

---

## 3. Server-to-Server Headers (Frozen)

Every request from Laravel to the AI API carries:

```http
Authorization: Bearer <service-token>
Content-Type: application/json; charset=utf-8
Accept: application/json
X-Request-ID: <request UUID>
```

Separate service tokens:

```env
STUDENT_AI_API_TOKEN=
GUEST_AI_API_TOKEN=
```

Rules:

- Tokens are stored **only in environment variables**.
- Tokens are **never committed** to version control.
- Tokens are **never logged**.
- **Student and guest tokens must be independently revocable.** Revoking one must not affect the
  other.
- **`X-Request-ID` must equal the JSON body `request_id`.** The AI service should reject or flag
  any request where the header and body identifiers disagree (see §10, `INVALID_REQUEST`).

---

## 4. Synchronous Timeout Model (Frozen)

```
Laravel connect timeout              = 10 seconds
Laravel response timeout             = 420 seconds
AI-team target maximum processing    = 400 seconds
```

(Source: `config/chat.php` — `ai_connect_timeout` = 10, `ai_response_timeout` = 420.)

V1 uses **synchronous server-to-server HTTP calls** from Laravel queue workers. The worker holds
the HTTP connection open until the AI service responds or the response timeout elapses.

V1 does **not** use:

- callbacks
- webhooks
- streaming
- frontend-to-AI calls

A callback-based or otherwise asynchronous design **may be reconsidered in a later phase only if
the AI service cannot reliably respond within the 420-second window.** Until then, the AI service
must return a single complete JSON response within the window, targeting ≤ 400 seconds of
processing time.

---

## 5. Identifier Formats (Frozen)

| Field | Format |
|---|---|
| `schema_version` | exact string `"1.0"` |
| `request_id` | UUID string |
| `X-Request-ID` (header) | UUID string, **identical** to body `request_id` |
| `conversation_id` (student only) | UUID string |
| `guest_session_reference` (guest only) | lowercase SHA-256 hex string — regex `^[a-f0-9]{64}$` |
| `user_reference` (student only) | string beginning with `student:` |

---

## 6. Student Request Schema (Frozen)

`POST` to the student endpoint:

```json
{
  "schema_version": "1.0",
  "request_id": "ai-request-uuid",
  "conversation_id": "chat-conversation-uuid",
  "user_reference": "student:221101715",
  "language": "auto",
  "messages": [
    {
      "role": "user",
      "content": "Student question"
    }
  ],
  "student_context": {
    "student_id": "221101715",
    "full_name": "Student Name",
    "email": "student@example.com",
    "faculty_id": 1,
    "faculty_name": "Computer Science",
    "gpa": 3.2,
    "credits_completed": 90,
    "credits_required": 144
  }
}
```

`student_context` contains **exactly these 8 fields and no others**:

```
student_id
full_name
email
faculty_id
faculty_name
gpa
credits_completed
credits_required
```

Matching the current `StudentPayloadBuilder` behavior:

- `faculty_name` **may be `null`** (the student's faculty relationship may be absent).
- `gpa` is **numeric** (a JSON number, e.g. `3.2`).

The student request **must never contain**:

```
password hashes
Sanctum tokens
vehicle-request data
admin data
database credentials
pending placeholders
failed placeholders
system messages
internal errors
```

---

## 7. Guest Request Schema (Frozen)

`POST` to the guest endpoint:

```json
{
  "schema_version": "1.0",
  "request_id": "guest-request-uuid",
  "guest_session_reference": "lowercase-sha256-token-hash",
  "language": "auto",
  "messages": [
    {
      "role": "user",
      "content": "Guest question"
    }
  ]
}
```

`guest_session_reference` is the **lowercase SHA-256 hash** of the opaque guest token. The raw
guest token is never transmitted.

The guest request **must never contain**:

```
raw guest token
Redis keys
Redis password
internal Redis request_id fields inside message items
database credentials
```

### Language (V1, frozen)

Both `StudentRequest.language` and `GuestRequest.language` are frozen to the constant value:

```
language = "auto"
```

V1 always sends `"auto"`. **Languages beyond `auto` require a later contract revision after
AI-team confirmation.**

---

## 8. Message Schema (Frozen)

Each message item:

```json
{
  "role": "user",
  "content": "Text"
}
```

Allowed roles:

```
user
assistant
```

Rules:

- Laravel sends **completed messages only**.
- Order is **oldest to newest**.
- **Full completed history is sent in V1.** Laravel does not truncate or summarize.
- The **AI team handles context-window truncation or summarization internally** when the history
  exceeds the model's limits.
- `content` must be a **string**.
- `content.trim()` **must not be empty**.

Message items contain **only** `role` and `content`. No internal identifiers, timestamps, or
sequence numbers are included (this is enforced by the existing payload builders).

---

## 9. Success Response (Frozen)

HTTP `200 OK`:

```json
{
  "schema_version": "1.0",
  "request_id": "same-request-uuid",
  "status": "completed",
  "content": "AI-generated response"
}
```

The AI service must guarantee, and the Laravel HTTP client (Phase 5B) will validate:

- `schema_version` = `"1.0"`
- `request_id` **matches the incoming `request_id`**
- `status` = `"completed"`
- `content` is a **string**
- `content.trim()` is **not empty**

> **Note on current code:** today the Laravel jobs consume only `content` from the response and
> verify that it exists and is a string. The current jobs do **not** reject empty or
> whitespace-only strings. The Phase 5B HTTP clients will add the stricter trimmed non-empty
> validation (and validate the richer envelope — `schema_version`, `request_id`, `status`) before
> handing `content` to the job. See §15.

---

## 10. Error Response (Frozen)

Non-2xx responses use:

```json
{
  "schema_version": "1.0",
  "request_id": "same-request-uuid-or-null",
  "error": {
    "code": "VALIDATION_ERROR",
    "message": "Safe human-readable error message.",
    "retryable": false
  }
}
```

`request_id` echoes the incoming identifier when known, or is `null` when the request could not
be parsed far enough to recover it.

### Frozen status → code → retryable mapping

| HTTP | `error.code` | `retryable` |
|---|---|---|
| 400 | `INVALID_REQUEST` | `false` |
| 401 | `UNAUTHORIZED` | `false` |
| 409 | `REQUEST_ID_CONFLICT` | `false` |
| 422 | `VALIDATION_ERROR` | `false` |
| 429 | `RATE_LIMITED` | `true` |
| 500 | `INTERNAL_ERROR` | `false` unless explicitly documented otherwise |
| 503 | `SERVICE_UNAVAILABLE` | `true` |
| 504 | `AI_TIMEOUT` | `true` |

### Retry-After

The AI service **should** include:

```http
Retry-After: <seconds>
```

on `429` and `503` responses.

### Meaning of `retryable`

- `retryable` is **advisory metadata** describing whether the condition is transient.
- The **current Laravel jobs use `tries = 1`** (no automatic retry).
- **Automatic retry behavior is a separate future decision** (see §19, open decisions). For now,
  `retryable` informs operators and the frontend; it does not trigger automatic job retries.

### Never expose

Error messages and bodies must **never** expose:

```
stack traces
database errors
internal file paths
secret tokens
raw protected prompts
model filesystem paths
```

`error.message` must be a safe, human-readable summary suitable for surfacing to operators.

---

## 11. Idempotency (Frozen)

The AI API must treat **`request_id` as an idempotency key**.

Rules:

- **Same `request_id` + semantically identical normalized JSON payload** → return the same stored
  result, processing only once.
- **Same `request_id` + different normalized JSON payload** → HTTP `409` `REQUEST_ID_CONFLICT`.

Recommended retention: **at least 24 hours.**

**JSON object-key ordering must not affect semantic equality.** Two payloads that differ only in
the order of object keys are the same payload. Normalization for comparison should canonicalize
object-key order before hashing/comparing.

---

## 12. Strict Schema Behavior (Frozen)

In both this contract and the OpenAPI schema, the following objects use
`additionalProperties: false` and **reject unexpected fields**:

```
student request
guest request
student_context
message
success response
error response
nested error object
health response
```

Any request carrying an unexpected field is rejected (`400 INVALID_REQUEST` or
`422 VALIDATION_ERROR`, per the AI team's validation layer). Any response carrying an unexpected
field is treated by Laravel (Phase 5B) as an invalid response shape.

---

## 13. Privacy, Transport, and Logging (Frozen)

```
HTTPS required outside local development.
JSON uses UTF-8.
No database access by the AI service.
No phpMyAdmin access by the AI service.
No frontend-to-AI requests.
No secrets in logs.
No raw guest token in logs.
Safe structured logs may include request_id and conversation_id.
Guest logs may include guest_session_reference hash but never the raw token.
Maximum request-body size remains an open integration item.
Maximum history size remains an open integration item.
```

---

## 14. Health Endpoint (Frozen)

```
GET /health
→ unauthenticated
→ returns exactly {"status":"ok"}
```

Response:

```json
{
  "status": "ok"
}
```

Rules:

- **Unauthenticated.** No application-level authentication is applied.
- **Network-level restrictions may be applied by infrastructure** (e.g. firewall, internal-only
  exposure) without changing the response contract.
- The health response **must not expose** versions, secrets, stack traces, model paths, or
  internal configuration. It is exactly `{"status":"ok"}` with no additional fields.

---

## 15. Laravel-Side Implementation Status (Phase 5B — Complete)

Phase 5B is **implemented**. The following items are done:

- **`schema_version: "1.0"` emitted** by both `StudentPayloadBuilder` and `GuestPayloadBuilder`.
- **`X-Request-ID` sent** on every outbound request (header equals body `request_id`).
- **`HttpStudentAiChatClient`** and **`HttpGuestAiChatClient`** created, bound via
  `AI_CHAT_DRIVER=http`.
- **Strict outbound validation** (`AiOutboundPayloadValidator`) — exact key sets, value types,
  UUID/SHA-256 formats, trimmed non-empty content — enforced before any network call.
- **Strict success-envelope validation** (`AiHttpResponseValidator`) — exact key set,
  `schema_version`, `request_id` match, `status:"completed"`, trimmed non-empty `content`.
- **Strict error-envelope validation and mapping** (`AiHttpErrorMapper`) — frozen HTTP ↔ code ↔
  retryable table; local safe messages only; remote `error.message` is never persisted.
- **`AiHttpTransport`** — explicit `Content-Type: application/json; charset=utf-8`,
  `withoutRedirecting()`, URL/token guard (no credentials/fragment/query), HTTPS required outside
  local/testing, `ConnectionException`-only transport failure handling.

**Still pending (outside Phase 5B scope):**

- Real endpoint URLs and tokens require environment configuration (`STUDENT_AI_API_URL`,
  `STUDENT_AI_API_TOKEN`, `GUEST_AI_API_URL`, `GUEST_AI_API_TOKEN`).
- Joint integration testing against the AI-team service.
- Real timeout behaviour remains an integration-test item — `Http::fake()` cannot reproduce
  every cURL timeout condition.

### Laravel internal error code mapping (implemented)

| HTTP | AI-team `error.code` | Laravel `AiClientException` code |
|---|---|---|
| 400 | `INVALID_REQUEST` | `AI_INVALID_REQUEST` |
| 401 | `UNAUTHORIZED` | `AI_UNAUTHORIZED` |
| 409 | `REQUEST_ID_CONFLICT` | `AI_REQUEST_ID_CONFLICT` |
| 422 | `VALIDATION_ERROR` | `AI_VALIDATION_ERROR` |
| 429 | `RATE_LIMITED` | `AI_RATE_LIMITED` |
| 500 | `INTERNAL_ERROR` | `AI_INTERNAL_ERROR` |
| 503 | `SERVICE_UNAVAILABLE` | `AI_SERVICE_UNAVAILABLE` |
| 504 | `AI_TIMEOUT` | `TIMEOUT` |
| any other status / malformed envelope | — | `INVALID_AI_RESPONSE` |

---

## 16. Open Decisions (Require AI-Team Confirmation)

These items are **not yet frozen** and must be confirmed with the AI team before or during
integration:

```
final full student AI URL
final full guest AI URL
service-token exchange process
maximum request-body size
maximum history size
AI-team context-window strategy
expected average latency
hard maximum latency
supported languages beyond auto
integration-environment details
observability/on-call contact
automatic retry policy for a later phase
```
