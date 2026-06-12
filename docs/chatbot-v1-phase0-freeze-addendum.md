# Chatbot V1 — Mandatory Phase 0 Freeze Addendum

This addendum overrides any conflicting assumption in generated implementation plans.

## Required Tables

### chat_conversations

- id
- uuid unique
- student_id foreign key with restrictOnDelete
- title
- status default active
- last_message_at nullable
- deleted_by_student_at nullable
- timestamps
- index on student_id + deleted_by_student_at
- index on last_message_at

### chat_messages

- id
- uuid unique
- chat_conversation_id foreign key with cascadeOnDelete
- role
- content nullable for pending assistant placeholders
- status default completed
- sequence_number
- client_message_id nullable in database but required by authenticated-student API requests
- timestamps
- global unique index on client_message_id
- unique index on chat_conversation_id + sequence_number
- index on chat_conversation_id + role + status

### chat_ai_requests

- id
- uuid unique
- chat_conversation_id foreign key with cascadeOnDelete
- user_message_id foreign key with cascadeOnDelete
- assistant_message_id foreign key with cascadeOnDelete
- status default queued
- attempt_number default 1
- error_code nullable
- error_message nullable
- submitted_at nullable
- completed_at nullable
- failed_at nullable
- timestamps
- index on chat_conversation_id + status
- index on assistant_message_id

## Required Eloquent Relationships

ChatMessage must use:

```php
public function aiRequestsAsUser(): HasMany
public function aiRequestsAsAssistant(): HasMany
```

Do not use `HasOne` because retries create multiple AI-request attempts.

## Separate AI Clients

Create:

```text
StudentAiChatClientContract
GuestAiChatClientContract

HttpStudentAiChatClient
FakeStudentAiChatClient

HttpGuestAiChatClient
FakeGuestAiChatClient
```

Bind stateful test services and fake clients as singletons.

## Guest Storage Abstraction

Create:

```text
GuestChatStore
RedisGuestChatStore
InMemoryGuestChatStore
```

Do not use:

```xml
<env name="REDIS_CLIENT" value="array"/>
```

Use `InMemoryGuestChatStore` for isolated tests.

## Student API

Use:

```text
POST   /api/v1/student/chats
GET    /api/v1/student/chats
GET    /api/v1/student/chats/{chatUuid}
PATCH  /api/v1/student/chats/{chatUuid}
DELETE /api/v1/student/chats/{chatUuid}
POST   /api/v1/student/chats/{chatUuid}/messages
GET    /api/v1/student/chats/{chatUuid}/messages/{messageUuid}/status
POST   /api/v1/student/chats/{chatUuid}/messages/{messageUuid}/retry
```

`POST /api/v1/student/chats` creates:
- conversation;
- first user message;
- pending assistant placeholder;
- AI request.

It returns:

```http
202 Accepted
```

## Guest API

Use:

```text
POST /api/v1/guest/chat/messages
GET  /api/v1/guest/chat/messages/{requestId}/status
GET  /api/v1/guest/chat/history
```

## AI Queue

Use:

```env
AI_CHAT_QUEUE=ai-chat
```

Worker:

```bash
php artisan queue:work redis \
  --queue=ai-chat \
  --tries=1 \
  --timeout=460 \
  --sleep=3
```

## Admin Dashboard

No bulk conversation hard deletion.

Disable single hard deletion while an AI request is queued or processing.

## Test Requirements

Test:
- required client_message_id;
- global idempotency uniqueness;
- Unicode Arabic title;
- restrict student deletion;
- cascade conversation deletion;
- hidden chat access blocking;
- retry attempt history;
- dedicated ai-chat queue;
- singleton test store;
- guest opaque token hashing;
- atomic guest pending lock;
- pending-lock cleanup after setup failure;
- guest TTL;
- guest named throttle;
- Redis internal-only Docker service;
- Redis password requirement;
- no fixed worker container name;
- Filament active-request delete blocking;
- StudentResource deletion pre-check.
