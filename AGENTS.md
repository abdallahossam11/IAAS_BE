# IAAS Backend Agent Rules

## Existing Project

This is an existing Laravel 12 + Filament 3.3 backend.

Do not rebuild the project from scratch.
Do not modify unrelated vehicle-request logic.
Do not expose `.env` secrets.
Do not give the AI team phpMyAdmin or MySQL credentials.

Work phase by phase. Stop after the approved phase.

For every phase:
1. Read the relevant existing files.
2. Report contradictions before editing.
3. Modify only files required for the current phase.
4. Run relevant syntax checks and tests.
5. Report changed files and command results.
6. Stop and wait for approval.

## Approved Chatbot V1 Architecture

Frontend
→ Laravel API
→ Redis queue
→ AI team API
→ Laravel stores result
→ Frontend polls Laravel

Laravel is the only component allowed to read and write the project database.

The frontend must never call the AI API directly.

The AI team must never receive database credentials.

Both guest and student AI responses may take more than five minutes.

Do not hold the frontend HTTP request open while waiting for an AI response.

Use Redis background jobs and return HTTP 202 Accepted immediately.

## Separate AI APIs

There are two separate AI APIs:

1. Guest chatbot API.
2. Authenticated student chatbot API.

Use separate:
- URLs
- Bearer service tokens
- payload builders
- client contracts
- HTTP clients
- deterministic fake clients

Do not use one combined AI client.

## Student Chatbot

A logged-in student can:
- create multiple chats;
- view chats in a sidebar;
- rename chats;
- hide chats from their sidebar;
- send messages;
- retry failed AI responses.

Do not create empty database chats.

Create a conversation only when the student sends the first message.

Generate the initial title using the first 50 Unicode-safe characters of the first message:

```php
Str::limit($content, 50, '')
```

A student cannot send another message in the same chat while an assistant response is pending.

Use UUIDs as public identifiers for:
- conversations;
- messages;
- AI requests.

Require a frontend-generated UUID for every authenticated student message:

```php
'client_message_id' => ['required', 'uuid']
```

`client_message_id` must be globally unique.

When a student hides a chat:

```text
deleted_by_student_at = now()
```

A hidden chat must be inaccessible from all student endpoints:
- show;
- rename;
- send message;
- poll response;
- retry failed response.

## Student Profile Context Sent to AI

Send only:
- student_id
- full_name
- email
- faculty_id
- faculty_name
- gpa
- credits_completed
- credits_required

Never send:
- password hashes;
- Sanctum tokens;
- internal secrets;
- admin data;
- vehicle-request data;
- database credentials.

## Guest Chatbot

Do not save guest history permanently in MySQL.

Use Redis temporary guest state.

Generate a strong opaque guest token:

```php
Str::random(64)
```

Hash the token before using it in Redis keys:

```php
hash('sha256', $token)
```

Use:

```text
guest_chat:{tokenHash}:messages
guest_chat:{tokenHash}:pending
guest_ai_request:{requestId}
```

Guest history TTL:

```text
86400 seconds
```

Guest pending-lock TTL:

```text
600 seconds
```

Acquire the guest pending lock atomically using Redis:

```text
SET key requestId NX EX 600
```

Reject a second guest message while a reply is pending:

```http
409 Conflict
```

If guest setup or dispatch fails after lock acquisition, clear the pending lock immediately.

## Redis Queue

Use a dedicated chatbot queue:

```env
AI_CHAT_QUEUE=ai-chat
```

Dispatch chatbot jobs with:

```php
->onQueue(config('chat.ai_queue'))
```

Timeout hierarchy:

```text
AI HTTP timeout   = 420 seconds
Job timeout       = 450 seconds
Worker timeout    = 460 seconds
Redis retry_after = 510 seconds
```

Each AI job:

```php
public int $timeout = 450;
public int $tries = 1;
public bool $failOnTimeout = true;
```

Use `failed(Throwable $exception)` to store failed status.

Jobs must receive scalar IDs, not serialized Eloquent models.

Dispatch student jobs only after database commit.

## Redis Docker Rules

Add Redis to Docker Compose.

Do not publish Redis to the host.

Do not add:

```yaml
ports:
  - "6379:6379"
```

Use only the Docker internal network.

Enable Redis persistence:

```text
--appendonly yes
```

Require a non-empty Redis password in Docker environments, including local development.

Never commit passwords.

Do not assign a fixed `container_name` to the worker service so it can scale later.

## Admin Dashboard

Only:
- super_admin;
- support_admin;

can access chatbot conversations.

They can:
- view conversations;
- view ordered messages;
- restore chats hidden by students;
- permanently delete one conversation.

Do not add bulk hard deletion.

Show a 100-character preview of each message with an expand modal for full content.

Disable hard deletion while the conversation has AI requests with status:
- queued;
- processing.

Re-check this immediately before permanent deletion.

Permanent deletion hard-deletes:
- conversation;
- related messages;
- related AI request rows.

No deletion audit-log table in V1.

## Student Deletion Protection

Use:

```php
->restrictOnDelete()
```

for:

```text
chat_conversations.student_id
```

Do not cascade-delete chatbot history when deleting a student.

Before deleting a student in Filament, check:

```php
$student->chatConversations()->exists()
```

Block deletion and display:

```text
Cannot delete this student because saved chatbot conversations exist.
```

For bulk student deletion, validate all selected students before deleting any record.

## Message Validation

Use a configurable initial maximum:

```text
4000 characters
```

Students can create unlimited chats in V1.

## Guest Throttle

Apply a named limiter only to guest message submission:

```text
guest-chat-submit
```

Initial configurable values:

```env
GUEST_MESSAGE_THROTTLE_REQUESTS=10
GUEST_MESSAGE_THROTTLE_MINUTES=1
```

## Postponed Features

Do not implement:
- callbacks;
- streaming;
- CAPTCHA;
- cross-chat memory;
- Laravel-side history summarization;
- deletion audit logs;
- vehicle context in chatbot payload;
- unrelated refactors.
