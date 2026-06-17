# Frontend Integration Guide — Galala IAAS API

> **Complete, verified integration reference for the Galala IAAS backend.**
> All APIs documented here are verified from `routes/api.php`, controllers, and FormRequests.
> No APIs are invented. Unknown behavior is clearly marked.
> Last updated: 2026-06-17

---

## 1. Base URL

| Environment | Base URL |
|---|---|
| Local development (bare PHP) | `http://127.0.0.1:8000` |
| Local development (Docker) | `http://localhost:8000` |
| Server production | `https://your-production-domain.example.com` |

> All API paths are prefixed with `/api/v1`.
> Example: `POST http://127.0.0.1:8000/api/v1/student/login`

> **Note**: A specific production URL has not been confirmed in any config file. Update this with the real server URL before going live.

---

## 2. Authentication Model

### Student APIs

Students authenticate with a **Laravel Sanctum Bearer Token**.

Login returns a token. All protected student endpoints require:

```http
Authorization: Bearer <student_token>
Accept: application/json
Content-Type: application/json
```

- Token is created per login via `POST /api/v1/student/login`.
- Token is revoked via `POST /api/v1/student/logout`.
- Token does not expire automatically (until logout).

### Guest Chat APIs

Guest chat does **not** use `Authorization`. It uses a separate custom header:

```http
X-Guest-Token: <64-character alphanumeric token>
Accept: application/json
Content-Type: application/json
```

- The first guest message is sent **without** any token.
- The backend returns a `guest_token` in the first response.
- All subsequent guest requests must include `X-Guest-Token: <token>`.
- **Do not send `Authorization` for guest requests.**

### Gate API

The gate hardware API requires:

```http
X-Gate-Api-Key: <server-configured gate API key>
```

This is a backend-to-backend API (gate hardware → Laravel). Frontend apps should not call this endpoint.

---

## 3. Global Frontend Error Handling

### HTTP Status Codes

| Status | Meaning |
|---|---|
| `200` | Success |
| `202` | Accepted / Queued — the message was accepted and an AI job was dispatched |
| `401` | Unauthenticated — missing or invalid Sanctum token; or missing/invalid `X-Guest-Token` |
| `403` | Forbidden — authenticated but not authorized (e.g., accessing another student's chat) |
| `404` | Not found — resource does not exist or belongs to another user |
| `409` | Conflict — a response is already pending for this conversation; or `client_message_id` collision |
| `422` | Validation error — invalid request body; response contains `errors` |
| `429` | Throttle exceeded — login: 10 req/min; guest send: 10 req/min per guest token |
| `500` | Unexpected server error (possible framework error) |
| `503` | AI service unavailable — mapped internally to `error_code: "AI_SERVICE_UNAVAILABLE"` in the message status response |
| `504` | AI service timeout — mapped internally to `error_code: "TIMEOUT"` in the message status response |

> **Note**: 503 and 504 are not returned directly to the frontend as HTTP statuses on the primary endpoints. They are represented as a `failed` assistant message with an `error_code` in the `GET .../status` response.

### TypeScript Error Types

```typescript
type LaravelValidationError = {
  message: string;
  errors?: Record<string, string[]>;
};

type ApiResponse<T = unknown> = {
  success: boolean;
  data?: T;
  message?: string;
};
```

### AI Error Codes (in message status responses)

When an AI job fails, the `messageStatus` / `guestStatus` response contains an `error_code`:

| Error Code | Meaning |
|---|---|
| `AI_SERVICE_UNAVAILABLE` | AI service returned 503 |
| `TIMEOUT` | AI did not respond within the timeout window |
| `AI_INTERNAL_ERROR` | AI service returned 500 |
| `AI_VALIDATION_ERROR` | AI rejected the request (400/422) |
| `AI_UNAUTHORIZED` | AI returned 401 — token mismatch between backend and AI |
| `AI_RATE_LIMITED` | AI rate limited (429) |
| `INVALID_AI_RESPONSE` | AI returned an unexpected/malformed response |
| `PENDING_LOCK_LOST` | Guest only: the pending lock expired mid-flight |
| `UNEXPECTED_ERROR` | Any other unexpected error |

---

## 4. Endpoint Inventory

### 4.1 Student Auth

---

#### `POST /api/v1/student/login`

| Field | Value |
|---|---|
| **Auth** | None |
| **Throttle** | 10 requests per minute (per IP) |

**Request body:**
```json
{
  "student_id": "20220001",
  "password": "your_password"
}
```

| Field | Type | Required | Rules |
|---|---|---|---|
| `student_id` | string | yes | must exist in students table |
| `password` | string | yes | bcrypt-checked against DB |

**Success (200):**
```json
{
  "success": true,
  "message": "Login successful",
  "data": {
    "token": "1|abc123...",
    "student": {
      "id": 1,
      "student_id": "20220001",
      "full_name": "Ahmed Ali",
      "email": "ahmed@student.galala.edu.eg"
    }
  }
}
```

**Failure (401):**
```json
{
  "success": false,
  "message": "Invalid student ID or password"
}
```

> **Source**: [app/Http/Controllers/Api/V1/Student/AuthController.php](app/Http/Controllers/Api/V1/Student/AuthController.php)

---

#### `POST /api/v1/student/logout`

| Field | Value |
|---|---|
| **Auth** | `Authorization: Bearer <token>` required |

No request body.

**Success (200):**
```json
{
  "success": true,
  "message": "Logged out successfully"
}
```

> **Source**: [app/Http/Controllers/Api/V1/Student/AuthController.php](app/Http/Controllers/Api/V1/Student/AuthController.php)

---

### 4.2 Student Profile

---

#### `GET /api/v1/student/profile`

| Field | Value |
|---|---|
| **Auth** | `Authorization: Bearer <token>` required |

No request body.

**Success (200):**
```json
{
  "success": true,
  "data": {
    "full_name": "Ahmed Ali",
    "student_id": "20220001",
    "email": "ahmed@student.galala.edu.eg",
    "faculty": {
      "id": 3,
      "name": "Faculty of Engineering"
    },
    "gpa": 3.75,
    "credits_completed": 90,
    "credits_required": 132
  }
}
```

> **Source**: [app/Http/Controllers/Api/V1/Student/ProfileController.php](app/Http/Controllers/Api/V1/Student/ProfileController.php)

---

### 4.3 Student Vehicle

---

#### `GET /api/v1/student/vehicle`

| Field | Value |
|---|---|
| **Auth** | `Authorization: Bearer <token>` required |

No request body.

**Success (200) — no request:**
```json
{ "success": true, "status": "none", "data": null }
```

**Success (200) — pending:**
```json
{
  "success": true,
  "status": "pending",
  "data": {
    "id": 5,
    "vehicle_type": "Car",
    "vehicle_model": "Toyota Corolla",
    "vehicle_color": "White",
    "plate_number": "ABC 1234",
    "submitted_at": "2026-01-15"
  }
}
```

**Success (200) — approved (active):**
```json
{
  "success": true,
  "status": "approved",
  "data": {
    "id": 5,
    "vehicle_type": "Car",
    "vehicle_model": "Toyota Corolla",
    "vehicle_color": "White",
    "plate_number": "ABC 1234",
    "approved_at": "2026-01-20",
    "valid_from": "2026-01-20",
    "valid_until": "2026-06-30"
  }
}
```

**Success (200) — rejected:**
```json
{
  "success": true,
  "status": "rejected",
  "data": {
    "id": 5,
    "vehicle_type": "Car",
    "vehicle_model": "Toyota Corolla",
    "vehicle_color": "White",
    "plate_number": "ABC 1234",
    "rejection_reason": "Incomplete documents",
    "rejected_at": "2026-01-18"
  }
}
```

> **Source**: [app/Http/Controllers/Api/V1/Student/VehicleController.php](app/Http/Controllers/Api/V1/Student/VehicleController.php)

---

#### `POST /api/v1/student/vehicle-requests`

| Field | Value |
|---|---|
| **Auth** | `Authorization: Bearer <token>` required |

**Request body:**
```json
{
  "vehicle_type": "Car",
  "vehicle_model": "Toyota Corolla",
  "vehicle_color": "White",
  "plate_number": "ABC 1234"
}
```

All fields required, string.

**Success (200):**
```json
{
  "success": true,
  "message": "Vehicle request submitted successfully",
  "data": {
    "id": 5,
    "status": "pending"
  }
}
```

**Failure (422) — already has pending or active permit:**
```json
{
  "success": false,
  "message": "You already have a pending vehicle request or active permit."
}
```

> **Source**: [app/Http/Controllers/Api/V1/Student/VehicleController.php](app/Http/Controllers/Api/V1/Student/VehicleController.php)

---

#### `GET /api/v1/student/vehicle-requests/history`

| Field | Value |
|---|---|
| **Auth** | `Authorization: Bearer <token>` required |

No request body.

**Success (200):**
```json
{
  "success": true,
  "data": [
    {
      "id": 5,
      "vehicle_type": "Car",
      "vehicle_model": "Toyota Corolla",
      "vehicle_color": "White",
      "plate_number": "ABC 1234",
      "status": "approved",
      "valid_from": "2026-01-20",
      "valid_until": "2026-06-30",
      "rejection_reason": null,
      "created_at": "2026-01-15"
    }
  ]
}
```

> `valid_from` / `valid_until` are `null` if not set (pending/rejected). `rejection_reason` is `null` unless rejected.

> **Source**: [app/Http/Controllers/Api/V1/Student/VehicleController.php](app/Http/Controllers/Api/V1/Student/VehicleController.php)

---

### 4.4 Student Chat

---

#### `POST /api/v1/student/chats`

Create a new chat conversation and send the first message.

| Field | Value |
|---|---|
| **Auth** | `Authorization: Bearer <token>` required |
| **Success status** | `202 Accepted` |

**Request body:**
```json
{
  "message": "What courses do I need to graduate?",
  "client_message_id": "a1b2c3d4-e5f6-7890-abcd-ef1234567890"
}
```

| Field | Type | Required | Rules |
|---|---|---|---|
| `message` | string | yes | max 3000 characters |
| `client_message_id` | string (UUID) | yes | valid UUID v4; must be unique per message |

**Success (202):**
```json
{
  "success": true,
  "data": {
    "chat": {
      "uuid": "chat-uuid-here",
      "title": "What courses do I need to gr",
      "last_message_at": "2026-06-17T12:00:00+00:00"
    },
    "user_message": {
      "uuid": "user-msg-uuid",
      "role": "user",
      "content": "What courses do I need to graduate?",
      "status": "completed",
      "sequence_number": 1
    },
    "assistant_message": {
      "uuid": "assistant-msg-uuid",
      "role": "assistant",
      "content": null,
      "status": "pending",
      "sequence_number": 2
    },
    "ai_request": {
      "uuid": "ai-request-uuid",
      "status": "queued",
      "attempt_number": 1
    }
  }
}
```

**Idempotency (202):** Re-sending with the same `client_message_id` returns the existing message pair without creating a duplicate.

**Conflict (409):** `client_message_id` was used for a different student/conversation — rare collision.

> **Save `chat.uuid`** — you need it for all subsequent requests in this conversation.
> **Save `assistant_message.uuid`** — you need it to poll for the AI response.

> **Source**: [app/Http/Controllers/Api/V1/Student/ChatController.php](app/Http/Controllers/Api/V1/Student/ChatController.php)

---

#### `GET /api/v1/student/chats`

List all visible chat conversations for the student.

| Field | Value |
|---|---|
| **Auth** | `Authorization: Bearer <token>` required |

**Success (200):**
```json
{
  "success": true,
  "data": {
    "conversations": [
      {
        "uuid": "chat-uuid-here",
        "title": "What courses do I need to gr",
        "status": "active",
        "last_message_at": "2026-06-17T12:00:00+00:00"
      }
    ],
    "pagination": {
      "current_page": 1,
      "last_page": 3,
      "per_page": 20,
      "total": 55
    }
  }
}
```

> Conversations soft-deleted by the student (`deleted_by_student_at` set) are excluded.
> **`status`** values: `"active"` is the only value currently used.
> Page size is fixed at 20.

> **Source**: [app/Http/Controllers/Api/V1/Student/ChatController.php](app/Http/Controllers/Api/V1/Student/ChatController.php)

---

#### `GET /api/v1/student/chats/{chatUuid}`

Get a single conversation and all its messages.

| Field | Value |
|---|---|
| **Auth** | `Authorization: Bearer <token>` required |

**Success (200):**
```json
{
  "success": true,
  "data": {
    "conversation": {
      "uuid": "chat-uuid-here",
      "title": "What courses do I need to gr",
      "status": "active",
      "last_message_at": "2026-06-17T12:00:00+00:00"
    },
    "messages": [
      {
        "uuid": "user-msg-uuid",
        "role": "user",
        "content": "What courses do I need to graduate?",
        "status": "completed",
        "sequence_number": 1
      },
      {
        "uuid": "assistant-msg-uuid",
        "role": "assistant",
        "content": null,
        "status": "pending",
        "sequence_number": 2
      }
    ]
  }
}
```

> **`content`** for assistant messages is `null` while `status` is `"pending"`.
> **`status`** values: `"pending"`, `"completed"`, `"failed"`.

> **Source**: [app/Http/Controllers/Api/V1/Student/ChatController.php](app/Http/Controllers/Api/V1/Student/ChatController.php)

---

#### `PATCH /api/v1/student/chats/{chatUuid}`

Rename a conversation.

| Field | Value |
|---|---|
| **Auth** | `Authorization: Bearer <token>` required |

**Request body:**
```json
{
  "title": "My graduation plan"
}
```

| Field | Type | Required | Rules |
|---|---|---|---|
| `title` | string | yes | max 255 characters |

**Success (200):**
```json
{
  "success": true,
  "data": {
    "uuid": "chat-uuid-here",
    "title": "My graduation plan"
  }
}
```

> **Source**: [app/Http/Controllers/Api/V1/Student/ChatController.php](app/Http/Controllers/Api/V1/Student/ChatController.php)

---

#### `DELETE /api/v1/student/chats/{chatUuid}`

Soft-delete (hide) a conversation for the student. Does not permanently delete.

| Field | Value |
|---|---|
| **Auth** | `Authorization: Bearer <token>` required |

No request body.

**Success (200):**
```json
{
  "success": true
}
```

> The conversation is hidden from the student but remains in the database for admin access.

> **Source**: [app/Http/Controllers/Api/V1/Student/ChatController.php](app/Http/Controllers/Api/V1/Student/ChatController.php)

---

#### `POST /api/v1/student/chats/{chatUuid}/messages`

Send a follow-up message in an existing conversation.

| Field | Value |
|---|---|
| **Auth** | `Authorization: Bearer <token>` required |
| **Success status** | `202 Accepted` |

**Request body:** same as `POST /api/v1/student/chats`
```json
{
  "message": "How many credits do I have left?",
  "client_message_id": "new-uuid-for-this-message"
}
```

**Success (202):** Same shape as `POST /api/v1/student/chats` (section 4.4).

**Conflict (409):** A previous message in this conversation is still pending (`status: "pending"`). Wait for it to complete before sending the next message.

> **Source**: [app/Http/Controllers/Api/V1/Student/ChatController.php](app/Http/Controllers/Api/V1/Student/ChatController.php)

---

#### `GET /api/v1/student/chats/{chatUuid}/messages/{messageUuid}/status`

Poll for the AI response status of a specific assistant message.

| Field | Value |
|---|---|
| **Auth** | `Authorization: Bearer <token>` required |

No request body.

**`{messageUuid}`**: The `assistant_message.uuid` returned when sending a message.

**Success (200) — still pending:**
```json
{
  "success": true,
  "data": {
    "assistant_message": {
      "uuid": "assistant-msg-uuid",
      "content": null,
      "status": "pending"
    },
    "ai_request": {
      "uuid": "ai-request-uuid",
      "status": "processing",
      "attempt_number": 1,
      "error_code": null
    }
  }
}
```

**Success (200) — completed:**
```json
{
  "success": true,
  "data": {
    "assistant_message": {
      "uuid": "assistant-msg-uuid",
      "content": "You need the following courses to graduate: ...",
      "status": "completed"
    },
    "ai_request": {
      "uuid": "ai-request-uuid",
      "status": "completed",
      "attempt_number": 1,
      "error_code": null
    }
  }
}
```

**Success (200) — failed:**
```json
{
  "success": true,
  "data": {
    "assistant_message": {
      "uuid": "assistant-msg-uuid",
      "content": null,
      "status": "failed"
    },
    "ai_request": {
      "uuid": "ai-request-uuid",
      "status": "failed",
      "attempt_number": 1,
      "error_code": "TIMEOUT"
    }
  }
}
```

> **`ai_request`** may be `null` in an edge case where no AI request exists yet (should not happen in normal flow).

> **Source**: [app/Http/Controllers/Api/V1/Student/ChatController.php](app/Http/Controllers/Api/V1/Student/ChatController.php)

---

#### `POST /api/v1/student/chats/{chatUuid}/messages/{messageUuid}/retry`

Retry a failed assistant message.

| Field | Value |
|---|---|
| **Auth** | `Authorization: Bearer <token>` required |
| **Success status** | `202 Accepted` |

No request body.

**`{messageUuid}`**: The `assistant_message.uuid` of a **failed** message.

**Success (202):** Same shape as `POST /api/v1/student/chats` — returns the updated assistant message (now `pending`) and a new `ai_request`.

**Failure (422):** Message is not in `failed` status.

**Conflict (409):** An AI request is already queued/processing for this message.

> **Source**: [app/Http/Controllers/Api/V1/Student/ChatController.php](app/Http/Controllers/Api/V1/Student/ChatController.php)

---

### 4.5 Guest Chat

---

#### `POST /api/v1/guest/chat/messages`

Send a guest chat message. First message: no token. Follow-up: include `X-Guest-Token`.

| Field | Value |
|---|---|
| **Auth** | None. `X-Guest-Token` for follow-up messages only |
| **Throttle** | 10 requests per minute (per guest token / IP) |
| **Success status** | `202 Accepted` |

**Request body:**
```json
{
  "message": "What are the admission requirements?"
}
```

| Field | Type | Required | Rules |
|---|---|---|---|
| `message` | string | yes | max 3000 characters |

**First message — no token (202):**
```json
{
  "success": true,
  "data": {
    "request_id": "a1b2c3d4-e5f6-7890-abcd-ef1234567890",
    "guest_token": "AbCdEf1234567890AbCdEf1234567890AbCdEf1234567890AbCdEf1234567890",
    "status": "queued"
  }
}
```

> `guest_token` is **only returned once** (on first message). Store it immediately in sessionStorage or memory.

**Follow-up message — with token (202):**
```json
{
  "success": true,
  "data": {
    "request_id": "b2c3d4e5-f6a7-8901-bcde-f12345678901",
    "status": "queued"
  }
}
```

> `guest_token` is **not** returned on follow-up messages.

**Conflict (409) — already processing:**
```json
{
  "success": false,
  "message": "A response is already being processed."
}
```

> **Source**: [app/Http/Controllers/Api/V1/Guest/GuestChatController.php](app/Http/Controllers/Api/V1/Guest/GuestChatController.php)

---

#### `GET /api/v1/guest/chat/messages/{requestId}/status`

Poll for the status of a guest message.

| Field | Value |
|---|---|
| **Auth** | `X-Guest-Token: <token>` **required** |

**`{requestId}`**: The `request_id` from the send response.

**Success (200) — pending/processing:**
```json
{
  "success": true,
  "data": {
    "request_id": "a1b2c3d4-...",
    "status": "processing",
    "content": null,
    "error_code": null
  }
}
```

**Success (200) — completed:**
```json
{
  "success": true,
  "data": {
    "request_id": "a1b2c3d4-...",
    "status": "completed",
    "content": "Admission requires a minimum GPA of 2.5...",
    "error_code": null
  }
}
```

**Success (200) — failed:**
```json
{
  "success": true,
  "data": {
    "request_id": "a1b2c3d4-...",
    "status": "failed",
    "content": null,
    "error_code": "TIMEOUT"
  }
}
```

**Unauthorized (401):** Missing or invalid `X-Guest-Token`.

**Not Found (404):** `requestId` not found (expired or wrong token).

> **Source**: [app/Http/Controllers/Api/V1/Guest/GuestChatController.php](app/Http/Controllers/Api/V1/Guest/GuestChatController.php)

---

#### `GET /api/v1/guest/chat/history`

Get the full conversation history for a guest session.

| Field | Value |
|---|---|
| **Auth** | `X-Guest-Token: <token>` **required** |

No request body.

**Success (200):**
```json
{
  "success": true,
  "data": {
    "messages": [
      {
        "role": "user",
        "content": "What are the admission requirements?",
        "created_at": "2026-06-17T12:00:00.000000Z"
      },
      {
        "role": "assistant",
        "content": "Admission requires a minimum GPA of 2.5...",
        "created_at": "2026-06-17T12:00:05.000000Z"
      }
    ]
  }
}
```

> Only `user` and `assistant` messages are included (internal Redis metadata is filtered out).
> Calling this endpoint also **refreshes the guest session TTL**.
> Returns empty `messages: []` if the session has expired.

> **Source**: [app/Http/Controllers/Api/V1/Guest/GuestChatController.php](app/Http/Controllers/Api/V1/Guest/GuestChatController.php)

---

### 4.6 Gate API (Backend/Hardware Only)

---

#### `POST /api/v1/gate/vehicle-access/check`

**This endpoint is for gate hardware, not for the student/guest frontend.**

| Field | Value |
|---|---|
| **Auth** | `X-Gate-Api-Key: <gate-api-key>` required |
| **Throttle** | 60 requests per minute |

**Request body:**
```json
{
  "OCR": "ABC 1234"
}
```

**Success (200) — access allowed:**
```json
{
  "success": true,
  "access": "allowed",
  "message": "Vehicle permit is approved and valid.",
  "data": {
    "plate_number": "ABC 1234",
    "normalized_plate": "abc1234",
    "student": {
      "student_id": "20220001",
      "full_name": "Ahmed Ali",
      "faculty": "Faculty of Engineering"
    },
    "permit": {
      "id": 5,
      "valid_from": "2026-01-20",
      "valid_until": "2026-06-30"
    }
  }
}
```

**Success (200) — access denied:**
```json
{
  "success": true,
  "access": "denied",
  "message": "No approved valid vehicle permit found for this plate.",
  "data": {
    "plate_number": "XYZ 9999",
    "normalized_plate": "xyz9999"
  }
}
```

> **Source**: [app/Http/Controllers/Api/V1/Gate/VehicleAccessController.php](app/Http/Controllers/Api/V1/Gate/VehicleAccessController.php)

---

## 5. Student Chat Frontend Flow

### Complete step-by-step flow:

```
1. User logs in
   POST /api/v1/student/login → { token }
   Store token securely (e.g., in-memory or httpOnly cookie — avoid localStorage).

2. Create a new chat with the first message
   POST /api/v1/student/chats
   Body: { message: "...", client_message_id: generateClientMessageId() }
   → 202 { chat.uuid, assistant_message.uuid, ai_request.status: "queued" }
   Save: chat.uuid, assistant_message.uuid

3. Start polling for AI response
   Every 2–5 seconds:
   GET /api/v1/student/chats/{chat.uuid}/messages/{assistant_message.uuid}/status
   Until: status === "completed" OR status === "failed"

4. Stop polling and show result
   - completed: show assistant_message.content
   - failed: show error with option to retry

5. Follow-up message
   POST /api/v1/student/chats/{chat.uuid}/messages
   Body: { message: "...", client_message_id: generateClientMessageId() }
   → 202 (new assistant_message.uuid)
   Repeat steps 3–4.

6. Retry a failed message
   POST /api/v1/student/chats/{chat.uuid}/messages/{failed_assistant_message.uuid}/retry
   → 202 (same assistant_message.uuid, new ai_request)
   Repeat steps 3–4.

7. List all chats
   GET /api/v1/student/chats
   Returns paginated list. Load page 2+ by appending ?page=2.

8. Open a chat
   GET /api/v1/student/chats/{chat.uuid}
   Returns full message history.

9. Rename a chat
   PATCH /api/v1/student/chats/{chat.uuid}
   Body: { title: "My new title" }

10. Delete (hide) a chat
    DELETE /api/v1/student/chats/{chat.uuid}
```

### `client_message_id` explained

`client_message_id` is a **UUID you generate per message on the frontend**.

```typescript
function createClientMessageId(): string {
  return crypto.randomUUID();
}
```

**Rules:**
- Generate a new UUID for every new message the user sends.
- **Never reuse** a `client_message_id` for a different message.
- Re-sending a request with the **same** `client_message_id` returns the existing result without creating a duplicate (idempotency guarantee — handles network retries safely).

---

## 6. Guest Chat Frontend Flow

### Complete step-by-step flow:

```
1. Guest sends first message (NO token)
   POST /api/v1/guest/chat/messages
   Headers: Accept: application/json, Content-Type: application/json
   Body: { message: "..." }
   → 202 { request_id, guest_token, status: "queued" }
   !! Store guest_token immediately in memory or sessionStorage !!

2. Poll for AI response
   Every 2–5 seconds:
   GET /api/v1/guest/chat/messages/{request_id}/status
   Headers: X-Guest-Token: <guest_token>
   Until: status === "completed" OR status === "failed"

3. Show result
   - completed: show data.content
   - failed: show error with option to retry by re-sending the same message

4. Guest sends follow-up
   POST /api/v1/guest/chat/messages
   Headers: X-Guest-Token: <guest_token>
   Body: { message: "..." }
   → 202 { request_id, status: "queued" }  (no guest_token in response)
   Repeat steps 2–3.

5. View conversation history
   GET /api/v1/guest/chat/history
   Headers: X-Guest-Token: <guest_token>
   → 200 { data.messages: [ {role, content, created_at}, ... ] }
   (Also refreshes the session TTL)
```

### Important guest rules:

- **Never send `Authorization: Bearer`** for guest requests.
- **`guest_token`** is only returned once. If lost, the session is unrecoverable (no re-issue endpoint).
- Guest sessions expire after **7200 seconds (2 hours) of inactivity** by default (`GUEST_SESSION_TTL_SECONDS`).
- There is **no persistent guest chat history** after TTL expiration.
- There is **no "sign in as guest" or guest registration** — guests are fully anonymous.
- The guest token is **64 alphanumeric characters** (pattern: `[A-Za-z0-9]{64}`).

---

## 7. Polling Strategy

```typescript
type MessageStatus = "queued" | "processing" | "completed" | "failed";

interface PollResult {
  status: MessageStatus;
  content: string | null;
  errorCode: string | null;
}

async function pollStudentMessageStatus(
  chatUuid: string,
  assistantMessageUuid: string,
  token: string,
  options?: {
    intervalMs?: number;      // default: 3000
    timeoutMs?: number;       // default: 480000 (8 minutes)
    onLongWait?: () => void;  // called after 30s to show "still processing"
  }
): Promise<PollResult> {
  const intervalMs = options?.intervalMs ?? 3000;
  const timeoutMs  = options?.timeoutMs  ?? 480000;
  const startTime  = Date.now();

  let longWaitNotified = false;

  while (true) {
    const elapsed = Date.now() - startTime;

    if (elapsed > timeoutMs) {
      throw new Error("Polling timeout: AI response took too long.");
    }

    if (!longWaitNotified && elapsed > 30000) {
      longWaitNotified = true;
      options?.onLongWait?.();
    }

    const resp = await fetch(
      `/api/v1/student/chats/${chatUuid}/messages/${assistantMessageUuid}/status`,
      { headers: { Authorization: `Bearer ${token}`, Accept: "application/json" } }
    );

    if (!resp.ok) throw new Error(`Status check failed: ${resp.status}`);

    const data = await resp.json();
    const status: MessageStatus = data.data.assistant_message.status;

    if (status === "completed") {
      return {
        status: "completed",
        content: data.data.assistant_message.content,
        errorCode: null,
      };
    }

    if (status === "failed") {
      return {
        status: "failed",
        content: null,
        errorCode: data.data.ai_request?.error_code ?? null,
      };
    }

    // "pending" or "processing" — continue polling
    await new Promise(resolve => setTimeout(resolve, intervalMs));
  }
}

// Equivalent function for guest polling (replace the fetch URL and headers):
async function pollGuestMessageStatus(
  requestId: string,
  guestToken: string,
  options?: { intervalMs?: number; timeoutMs?: number; onLongWait?: () => void; }
): Promise<{ status: MessageStatus; content: string | null; errorCode: string | null }> {
  const intervalMs = options?.intervalMs ?? 3000;
  const timeoutMs  = options?.timeoutMs  ?? 480000;
  const startTime  = Date.now();
  let longWaitNotified = false;

  while (true) {
    const elapsed = Date.now() - startTime;
    if (elapsed > timeoutMs) throw new Error("Polling timeout.");
    if (!longWaitNotified && elapsed > 30000) {
      longWaitNotified = true;
      options?.onLongWait?.();
    }

    const resp = await fetch(
      `/api/v1/guest/chat/messages/${requestId}/status`,
      { headers: { "X-Guest-Token": guestToken, Accept: "application/json" } }
    );

    if (!resp.ok) throw new Error(`Status check failed: ${resp.status}`);

    const data = await resp.json();
    const status: MessageStatus = data.data.status;

    if (status === "completed") return { status: "completed", content: data.data.content, errorCode: null };
    if (status === "failed")    return { status: "failed",    content: null,              errorCode: data.data.error_code };

    await new Promise(resolve => setTimeout(resolve, intervalMs));
  }
}
```

### Polling rules:
- Poll every **2–5 seconds** (3s recommended).
- Stop when status is `"completed"` or `"failed"`.
- Frontend timeout should be **7–8 minutes** (the AI backend timeout is ~7 minutes; give the job a few seconds to update status after completion).
- Show a **"still processing..."** indicator after 30 seconds.
- **Do not send a new message** while the previous assistant message is `"pending"` — the backend will return `409`. Disable the send button while pending.

---

## 8. TypeScript API Client Examples

```typescript
// ─── Types ────────────────────────────────────────────────────────────────────

interface ApiResponse<T = unknown> {
  success: boolean;
  data?: T;
  message?: string;
}

interface LoginResponse {
  token: string;
  student: {
    id: number;
    student_id: string;
    full_name: string;
    email: string;
  };
}

interface StudentProfile {
  full_name: string;
  student_id: string;
  email: string;
  faculty: { id: number; name: string };
  gpa: number;
  credits_completed: number;
  credits_required: number;
}

interface ChatConversation {
  uuid: string;
  title: string;
  status: string;
  last_message_at: string | null;
}

interface ChatMessage {
  uuid: string;
  role: "user" | "assistant";
  content: string | null;
  status: "pending" | "completed" | "failed";
  sequence_number: number;
}

interface ChatCycleResponse {
  chat: { uuid: string; title: string; last_message_at: string | null };
  user_message: ChatMessage;
  assistant_message: ChatMessage;
  ai_request: { uuid: string; status: string; attempt_number: number };
}

interface GuestSendResponse {
  request_id: string;
  guest_token?: string; // only on first message
  status: string;
}

interface GuestStatusResponse {
  request_id: string;
  status: "queued" | "processing" | "completed" | "failed";
  content: string | null;
  error_code: string | null;
}

// ─── Base fetch ───────────────────────────────────────────────────────────────

async function apiFetch<T>(
  path: string,
  options: RequestInit & { token?: string; guestToken?: string } = {}
): Promise<T> {
  const { token, guestToken, ...fetchOptions } = options;
  const headers: Record<string, string> = {
    Accept: "application/json",
    ...(fetchOptions.body ? { "Content-Type": "application/json" } : {}),
    ...(token      ? { Authorization: `Bearer ${token}` }  : {}),
    ...(guestToken ? { "X-Guest-Token": guestToken }        : {}),
  };

  const resp = await fetch(`/api/v1${path}`, { ...fetchOptions, headers });

  if (!resp.ok) {
    const body = await resp.json().catch(() => ({}));
    const err = new Error(body.message ?? `HTTP ${resp.status}`);
    (err as any).status = resp.status;
    (err as any).errors = body.errors;
    throw err;
  }

  return resp.json() as Promise<T>;
}

// ─── Auth ─────────────────────────────────────────────────────────────────────

async function loginStudent(studentId: string, password: string) {
  return apiFetch<ApiResponse<LoginResponse>>("/student/login", {
    method: "POST",
    body: JSON.stringify({ student_id: studentId, password }),
  });
}

async function logoutStudent(token: string) {
  return apiFetch<ApiResponse>("/student/logout", { method: "POST", token });
}

// ─── Profile ──────────────────────────────────────────────────────────────────

async function getStudentProfile(token: string) {
  return apiFetch<ApiResponse<StudentProfile>>("/student/profile", { token });
}

// ─── Chat list / detail ───────────────────────────────────────────────────────

async function listStudentChats(token: string, page = 1) {
  return apiFetch<ApiResponse<{
    conversations: ChatConversation[];
    pagination: { current_page: number; last_page: number; per_page: number; total: number };
  }>>(`/student/chats?page=${page}`, { token });
}

async function getStudentChat(token: string, chatUuid: string) {
  return apiFetch<ApiResponse<{
    conversation: ChatConversation;
    messages: ChatMessage[];
  }>>(`/student/chats/${chatUuid}`, { token });
}

async function renameStudentChat(token: string, chatUuid: string, title: string) {
  return apiFetch<ApiResponse<{ uuid: string; title: string }>>(
    `/student/chats/${chatUuid}`,
    { method: "PATCH", token, body: JSON.stringify({ title }) }
  );
}

async function deleteStudentChat(token: string, chatUuid: string) {
  return apiFetch<ApiResponse>(`/student/chats/${chatUuid}`, { method: "DELETE", token });
}

// ─── Send message / poll ──────────────────────────────────────────────────────

function createClientMessageId(): string {
  return crypto.randomUUID();
}

async function createStudentChat(token: string, message: string) {
  return apiFetch<ApiResponse<ChatCycleResponse>>("/student/chats", {
    method: "POST",
    token,
    body: JSON.stringify({ message, client_message_id: createClientMessageId() }),
  });
}

async function sendStudentMessage(token: string, chatUuid: string, message: string) {
  return apiFetch<ApiResponse<ChatCycleResponse>>(
    `/student/chats/${chatUuid}/messages`,
    { method: "POST", token, body: JSON.stringify({ message, client_message_id: createClientMessageId() }) }
  );
}

async function getStudentMessageStatus(token: string, chatUuid: string, messageUuid: string) {
  return apiFetch<ApiResponse<{
    assistant_message: { uuid: string; content: string | null; status: string };
    ai_request: { uuid: string; status: string; attempt_number: number; error_code: string | null } | null;
  }>>(`/student/chats/${chatUuid}/messages/${messageUuid}/status`, { token });
}

async function retryStudentMessage(token: string, chatUuid: string, messageUuid: string) {
  return apiFetch<ApiResponse<ChatCycleResponse>>(
    `/student/chats/${chatUuid}/messages/${messageUuid}/retry`,
    { method: "POST", token }
  );
}

// ─── Guest chat ───────────────────────────────────────────────────────────────

async function sendGuestMessage(message: string, guestToken?: string) {
  return apiFetch<ApiResponse<GuestSendResponse>>("/guest/chat/messages", {
    method: "POST",
    guestToken,
    body: JSON.stringify({ message }),
  });
}

async function getGuestMessageStatus(requestId: string, guestToken: string) {
  return apiFetch<ApiResponse<GuestStatusResponse>>(
    `/guest/chat/messages/${requestId}/status`,
    { guestToken }
  );
}

async function getGuestHistory(guestToken: string) {
  return apiFetch<ApiResponse<{
    messages: Array<{ role: string; content: string; created_at: string }>
  }>>("/guest/chat/history", { guestToken });
}
```

---

## 9. Recommended Frontend State Model

```typescript
interface AppState {
  // Auth
  studentToken: string | null;        // Sanctum Bearer token
  student: StudentProfile | null;     // logged-in student info

  // Chat list
  chats: ChatConversation[];
  chatsPagination: {
    currentPage: number;
    lastPage: number;
    perPage: number;
    total: number;
  } | null;
  chatsLoading: boolean;

  // Active chat
  activeChatUuid: string | null;
  activeMessages: ChatMessage[];
  activeMessagesLoading: boolean;

  // Pending message tracking (student)
  // Key: assistantMessageUuid → polling status
  pendingMessages: Map<string, {
    chatUuid: string;
    status: "polling" | "completed" | "failed";
    errorCode: string | null;
  }>;

  // Guest chat
  guestToken: string | null;          // from first message; null = no session started
  guestMessages: Array<{ role: string; content: string; created_at: string }>;
  guestPendingRequestId: string | null; // requestId being polled
  guestPendingStatus: "polling" | "completed" | "failed" | null;

  // Global UI
  loading: boolean;
  error: string | null;
}
```

---

## 10. Missing / Not Working / Needs Verification

### ✅ Implemented and verified

| API | Verified Source |
|---|---|
| `POST /api/v1/student/login` | AuthController.php + routes/api.php |
| `POST /api/v1/student/logout` | AuthController.php |
| `GET /api/v1/student/profile` | ProfileController.php |
| `GET /api/v1/student/vehicle` | VehicleController.php |
| `POST /api/v1/student/vehicle-requests` | VehicleController.php |
| `GET /api/v1/student/vehicle-requests/history` | VehicleController.php |
| `POST /api/v1/student/chats` | ChatController.php |
| `GET /api/v1/student/chats` | ChatController.php |
| `GET /api/v1/student/chats/{chatUuid}` | ChatController.php |
| `PATCH /api/v1/student/chats/{chatUuid}` | ChatController.php |
| `DELETE /api/v1/student/chats/{chatUuid}` | ChatController.php |
| `POST /api/v1/student/chats/{chatUuid}/messages` | ChatController.php |
| `GET /api/v1/student/chats/{chatUuid}/messages/{messageUuid}/status` | ChatController.php |
| `POST /api/v1/student/chats/{chatUuid}/messages/{messageUuid}/retry` | ChatController.php |
| `POST /api/v1/guest/chat/messages` | GuestChatController.php |
| `GET /api/v1/guest/chat/messages/{requestId}/status` | GuestChatController.php |
| `GET /api/v1/guest/chat/history` | GuestChatController.php |

### ❌ Not implemented (frontend may expect these)

| Feature | Status |
|---|---|
| Chat message pagination | **Not implemented.** `GET /api/v1/student/chats/{chatUuid}` returns ALL messages for a chat. No cursor/page param. If a chat has thousands of messages, this could be slow. No fix planned currently. |
| Chat search / filter | **Not implemented.** `GET /api/v1/student/chats` has no search param. |
| Cancel a pending message | **Not implemented.** There is no endpoint to cancel a queued/processing AI request. The frontend can only wait or refresh. |
| WebSocket / SSE streaming | **Not implemented.** Responses are delivered via polling only. No real-time push. |
| Student registration | **Not implemented as API.** Students are created by admins via the Filament admin panel. There is no `POST /api/v1/student/register` endpoint. |
| Password reset | **Not implemented as API.** |
| Guest conversation persist / export | **Not implemented.** Guest chats live in Redis only and expire. |
| Re-issue lost guest token | **Not implemented.** If the guest token is lost, the conversation cannot be recovered. There is no "resend token" or "recover session" endpoint. |

### ⚠️ Needs live server verification

| Item | Notes |
|---|---|
| AI responses in production | Tests used a fake AI driver locally. Live AI responses require `AI_CHAT_DRIVER=http` and the FastAPI AI service running on port 8010. |
| Guest session TTL behavior | Verified in unit tests. Live Redis expiry behavior (rolling TTL refresh on history fetch) needs confirmation on production Redis. |
| Queue worker stability | `queue:work --tries=1 --timeout=460` verified in docker-compose. Long-term reliability under load needs production monitoring. |
| `chat_summaries` table populated | The AI team's `/api/chat/summarize` endpoint must be implemented for this to work. The backend correctly dispatches summarization; the AI response must match the contract. |
| `pagination` on chat list | Verify that `?page=2` query param works correctly. Implemented via standard Laravel paginator. |

### ⚠️ Postman collection

The existing `postman_collection.json` **only covers student auth, profile, vehicle, and vehicle-access/check**.  
It **does not include** any chat APIs (student chat or guest chat).  
**Do not use the Postman collection as the authoritative API reference.** Use this document and the cURL examples in Section 11.

---

## 11. Live Verification Checklist (cURL Examples)

Replace `BASE_URL`, `TOKEN`, and `GUEST_TOKEN` with real values.

```bash
BASE_URL="http://localhost:8000"
TOKEN=""         # filled after login
GUEST_TOKEN=""   # filled after first guest message

# ── 1. Student Login ──────────────────────────────────────────────────────────
curl -s -X POST "$BASE_URL/api/v1/student/login" \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -d '{"student_id":"YOUR_STUDENT_ID","password":"YOUR_PASSWORD"}' | python -m json.tool

# → Copy data.token into TOKEN

# ── 2. Student Profile ────────────────────────────────────────────────────────
curl -s "$BASE_URL/api/v1/student/profile" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Accept: application/json" | python -m json.tool

# ── 3. Create Student Chat ────────────────────────────────────────────────────
curl -s -X POST "$BASE_URL/api/v1/student/chats" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -d '{
    "message": "Hello, what can you help me with?",
    "client_message_id": "11111111-1111-1111-1111-111111111111"
  }' | python -m json.tool

# → Copy data.chat.uuid into CHAT_UUID
# → Copy data.assistant_message.uuid into ASSISTANT_UUID

# ── 4. Poll Student Message Status ───────────────────────────────────────────
CHAT_UUID="<from above>"
ASSISTANT_UUID="<from above>"

curl -s "$BASE_URL/api/v1/student/chats/$CHAT_UUID/messages/$ASSISTANT_UUID/status" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Accept: application/json" | python -m json.tool

# → Repeat until status is "completed" or "failed"

# ── 5. Send Follow-up Message ─────────────────────────────────────────────────
curl -s -X POST "$BASE_URL/api/v1/student/chats/$CHAT_UUID/messages" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -d '{
    "message": "Tell me more about graduation requirements.",
    "client_message_id": "22222222-2222-2222-2222-222222222222"
  }' | python -m json.tool

# ── 6. List Student Chats ─────────────────────────────────────────────────────
curl -s "$BASE_URL/api/v1/student/chats" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Accept: application/json" | python -m json.tool

# ── 7. Guest First Message (no token) ────────────────────────────────────────
curl -s -X POST "$BASE_URL/api/v1/guest/chat/messages" \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -d '{"message": "What are the admission requirements?"}' | python -m json.tool

# → Copy data.guest_token into GUEST_TOKEN
# → Copy data.request_id into GUEST_REQUEST_ID

# ── 8. Poll Guest Message Status ─────────────────────────────────────────────
GUEST_REQUEST_ID="<from above>"

curl -s "$BASE_URL/api/v1/guest/chat/messages/$GUEST_REQUEST_ID/status" \
  -H "X-Guest-Token: $GUEST_TOKEN" \
  -H "Accept: application/json" | python -m json.tool

# ── 9. Guest Follow-up Message ────────────────────────────────────────────────
curl -s -X POST "$BASE_URL/api/v1/guest/chat/messages" \
  -H "X-Guest-Token: $GUEST_TOKEN" \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -d '{"message": "What scholarships are available?"}' | python -m json.tool

# ── 10. Guest History ─────────────────────────────────────────────────────────
curl -s "$BASE_URL/api/v1/guest/chat/history" \
  -H "X-Guest-Token: $GUEST_TOKEN" \
  -H "Accept: application/json" | python -m json.tool

# ── 11. Validation Error Scenario ─────────────────────────────────────────────
curl -s -X POST "$BASE_URL/api/v1/student/chats" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -d '{"message": ""}' | python -m json.tool
# → 422 { errors: { message: ["The message field is required."], client_message_id: [...] } }

# ── 12. AI Unavailable Scenario ──────────────────────────────────────────────
# (Only testable when AI service is down on server)
# The message will be created (202), polling will eventually show:
# { status: "failed", error_code: "AI_SERVICE_UNAVAILABLE" }
# or: { status: "failed", error_code: "TIMEOUT" }
```

---

## 12. Prompt for Frontend AI Tool

---

### Prompt for Frontend AI Tool

```
You are implementing the frontend for the Galala University IAAS application.
Use the existing UI design, component library, and routing system already in place.
Do NOT invent new backend endpoints.
Do NOT use localStorage for sensitive tokens (use memory or httpOnly cookies).

You must implement API integration using only the APIs documented in FRONTEND_INTEGRATION_GUIDE.md.
All APIs begin with /api/v1.

== WHAT TO IMPLEMENT ==

1. API CLIENT
   Create a reusable apiFetch() wrapper that:
   - Prepends /api/v1 to all paths
   - Adds Accept: application/json header always
   - Adds Content-Type: application/json when a body is present
   - Adds Authorization: Bearer <token> for student endpoints
   - Adds X-Guest-Token: <token> for guest chat endpoints
   - Throws typed errors for 401, 403, 404, 409, 422, 429, 500
   - Parses Laravel validation errors: { message, errors: Record<string, string[]> }

2. AUTH TOKEN HANDLING
   - On login, store the student Sanctum token securely in memory (NOT localStorage).
   - On logout, call POST /api/v1/student/logout and clear the token.
   - On 401 response, redirect to login.
   - On app load, restore token from memory/session if available and re-fetch profile.

3. STUDENT CHAT PAGES / COMPONENTS
   - Chat list page: GET /api/v1/student/chats (paginated, 20 per page)
   - Chat detail page: GET /api/v1/student/chats/{chatUuid}
   - Send message:
     a. For new chat: POST /api/v1/student/chats { message, client_message_id }
     b. For follow-up: POST /api/v1/student/chats/{chatUuid}/messages { message, client_message_id }
     c. Generate client_message_id using crypto.randomUUID() per message.
     d. Disable the send button while any assistant message in the conversation is pending.
   - Message status polling: poll GET .../messages/{assistantUuid}/status every 3s.
     - Stop when status === "completed" or "failed"
     - Show "AI is thinking..." indicator after 30s
     - Frontend timeout: 8 minutes
   - On failure: show error message + "Retry" button.
     - Retry calls: POST .../messages/{assistantUuid}/retry
   - Rename chat: PATCH /api/v1/student/chats/{chatUuid} { title }
   - Delete chat: DELETE /api/v1/student/chats/{chatUuid}

4. GUEST CHAT WIDGET / PAGE
   - Show a chat widget (e.g., a floating button or a dedicated page) for non-logged-in users.
   - First message: POST /api/v1/guest/chat/messages (NO token)
     - Save the returned guest_token in sessionStorage or memory immediately.
     - Save the returned request_id.
   - Follow-up messages: POST /api/v1/guest/chat/messages + X-Guest-Token header.
   - Polling: poll GET /api/v1/guest/chat/messages/{requestId}/status + X-Guest-Token.
     - Same interval/timeout rules as student chat.
   - History on load: GET /api/v1/guest/chat/history + X-Guest-Token.
   - IMPORTANT: Do NOT send Authorization: Bearer for guest requests.
   - IMPORTANT: guest_token is only returned on the first message; if lost, the session is gone.
   - Display a warning: "Your chat history will expire after 2 hours of inactivity."

5. POLLING IMPLEMENTATION
   - Poll every 3 seconds.
   - Stop polling when status is "completed" or "failed".
   - Show "Still processing..." after 30 seconds.
   - Timeout after 8 minutes with a user-facing error.
   - Do NOT send a new message while a previous one is still pending (disable send button).

6. ERROR HANDLING
   - 401: show session expired, redirect to login (student) or clear guest token (guest).
   - 403: show "Access denied."
   - 404: show "Not found."
   - 409 on send: show "Please wait for the previous response before sending a new message."
   - 422: show field-level validation errors next to each input.
   - 429: show "Too many requests. Please wait a moment and try again."
   - AI failed (error_code in status response): show "The AI could not respond. [Retry]"
   - Network error: show "Connection error. Please check your internet."

7. VERIFY WITH CURL
   Before marking a feature as done, test the actual HTTP flow using the cURL commands
   in Section 11 of FRONTEND_INTEGRATION_GUIDE.md with the real backend running.

8. MISSING FEATURES — DO NOT FAKE THESE
   The following features do NOT have backend API support yet. Do not implement them
   or invent endpoints for them. Log them as "Coming soon" in the UI if needed:
   - Student chat message pagination (all messages returned at once)
   - Chat message search
   - Cancel a pending AI request
   - Real-time streaming (WebSocket/SSE)
   - Student self-registration
   - Password reset
   - Guest token recovery after expiration
```

---

*End of Frontend Integration Guide*
