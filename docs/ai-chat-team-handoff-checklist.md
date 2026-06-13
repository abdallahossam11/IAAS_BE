# AI Chat API — AI-Team Handoff Checklist

> **Purpose:** A go/no-go checklist confirming the AI team's service satisfies the frozen
> contract in [`ai-chat-api-contract-v1.md`](./ai-chat-api-contract-v1.md) before integration.
> **Machine spec:** [`ai-chat-openapi-v1.yaml`](./ai-chat-openapi-v1.yaml).
> **Test matrix:** [`ai-chat-integration-test-cases.md`](./ai-chat-integration-test-cases.md).

Each item is checked off only when verified against a running AI-team environment. "Confirmed by"
records the responsible person; "Date" records when.

---

## 1. Connectivity & Authentication

| # | Item | Status | Confirmed by | Date |
|---|---|---|---|---|
| 1 | **Full student endpoint URL supplied** (complete callable URL for `STUDENT_AI_API_URL`) | ☐ | | |
| 2 | **Full guest endpoint URL supplied** (complete callable URL for `GUEST_AI_API_URL`) | ☐ | | |
| 3 | **Separate Bearer tokens exchanged securely** (student + guest, out-of-band, never committed) | ☐ | | |
| 4 | **HTTPS confirmed** on both endpoints outside local development | ☐ | | |
| 5 | **Auth tested** — valid token accepted; missing/invalid token → `401 UNAUTHORIZED` | ☐ | | |
| 6 | **Tokens independently revocable** — revoking one side does not affect the other | ☐ | | |

## 2. Request Validation

| # | Item | Status | Confirmed by | Date |
|---|---|---|---|---|
| 7 | **Student payload validated** — accepts the frozen student schema incl. all 8 `student_context` fields | ☐ | | |
| 8 | **Guest payload validated** — accepts the frozen guest schema | ☐ | | |
| 9 | **`X-Request-ID` = body `request_id` enforced** — mismatch → `400 INVALID_REQUEST` | ☐ | | |
| 10 | **Strict extra-field rejection confirmed** — any unexpected field rejected (`additionalProperties: false`) on student request, guest request, `student_context`, message, success response, error response, error object, health response | ☐ | | |
| 11 | **`schema_version` enforced** — value other than `"1.0"` rejected | ☐ | | |
| 12 | **Message roles enforced** — only `user`/`assistant`; empty/whitespace-only `content` rejected | ☐ | | |
| 13 | **`faculty_name: null` accepted**; **`gpa` numeric accepted** | ☐ | | |

## 3. Response Contract

| # | Item | Status | Confirmed by | Date |
|---|---|---|---|---|
| 14 | **Success schema validated** — `{schema_version:"1.0", request_id (matches), status:"completed", content (non-empty)}` | ☐ | | |
| 15 | **Error schema validated** — `{schema_version, request_id|null, error:{code, message, retryable}}` | ☐ | | |
| 16 | **Error mapping correct** — 400/401/409/422/429/500/503/504 → frozen codes + `retryable` values | ☐ | | |
| 17 | **`Retry-After` behavior confirmed** on `429` and `503` | ☐ | | |
| 18 | **No leakage** — error/health bodies never expose stack traces, DB errors, file paths, secrets, raw prompts, or model paths | ☐ | | |

## 4. Idempotency

| # | Item | Status | Confirmed by | Date |
|---|---|---|---|---|
| 19 | **`request_id` idempotency implemented** — same id + identical normalized payload → same stored result, processed once | ☐ | | |
| 20 | **Conflict behavior** — same id + different normalized payload → `409 REQUEST_ID_CONFLICT` | ☐ | | |
| 21 | **Key-order independence** — JSON object-key ordering does not affect semantic equality | ☐ | | |
| 22 | **24-hour retention confirmed** (minimum) | ☐ | | |

## 5. Performance & Operations

| # | Item | Status | Confirmed by | Date |
|---|---|---|---|---|
| 23 | **400-second target confirmed** — service targets ≤ 400s processing, within Laravel's 420s response timeout | ☐ | | |
| 24 | **Secret-safe logging reviewed** — no secrets, no raw guest token; `request_id`/`conversation_id`/`guest_session_reference` hash only | ☐ | | |
| 25 | **Full-history strategy documented** — service accepts full completed history each turn | ☐ | | |
| 26 | **Context-window strategy documented** — service truncates/summarizes internally when needed | ☐ | | |
| 27 | **Integration environment provided** — reachable non-production environment for joint testing | ☐ | | |
| 28 | **`GET /health` behavior confirmed** — unauthenticated, returns exactly `{"status":"ok"}`, no extra fields (network-level restrictions allowed) | ☐ | | |
| 29 | **Observability / on-call contact supplied** | ☐ | | |

---

## Open Decisions (must be resolved before go-live)

These are not yet frozen and require AI-team input (mirrors §16 of the contract):

- [ ] final full student AI URL
- [ ] final full guest AI URL
- [ ] service-token exchange process
- [ ] maximum request-body size
- [ ] maximum history size
- [ ] AI-team context-window strategy
- [ ] expected average latency
- [ ] hard maximum latency
- [ ] supported languages beyond `auto`
- [ ] integration-environment details
- [ ] observability / on-call contact
- [ ] automatic retry policy (deferred to a later phase)

---

## Laravel-Side Readiness (Phase 5B — not part of this handoff)

For transparency, the Laravel side is **not yet wired** to call a real service. Before production
integration, Phase 5B must:

- [ ] add `schema_version` to `StudentPayloadBuilder` and `GuestPayloadBuilder`
- [ ] create `HttpStudentAiChatClient` and `HttpGuestAiChatClient`
- [ ] send `X-Request-ID` equal to the body `request_id`
- [ ] validate success envelopes (schema_version, request_id match, status, non-empty content)
- [ ] validate error envelopes and map to safe `AiClientException` values
- [ ] switch `AI_CHAT_DRIVER` from `fake` to the real driver in the target environment

Phase 5B is **not** implemented at the time of this handoff document.
