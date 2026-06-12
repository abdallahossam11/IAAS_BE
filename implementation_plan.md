# Chatbot V1 — Complete Phase 0 Freeze Implementation Plan

> **Authority**: This plan is derived exclusively from two frozen documents:
> - [AGENTS.md](file:///s:/grad%20project/GRAD_PROJECT_2/grad_project_BE/IAAS_B.E/AGENTS.md)
> - [chatbot-v1-phase0-freeze-addendum.md](file:///s:/grad%20project/GRAD_PROJECT_2/grad_project_BE/IAAS_B.E/docs/chatbot-v1-phase0-freeze-addendum.md)
>
> and from direct inspection of the existing repository at `s:\grad project\GRAD_PROJECT_2\grad_project_BE\IAAS_B.E`.

---

## 1. Existing Architecture Summary (Inspected Code)

### 1.1 Technology Stack

| Component | Version |
|---|---|
| PHP | 8.3 (Dockerfile `php:8.3-fpm`) |
| Laravel | 12 (`laravel/framework ^12.0`) |
| Filament | 3.3 (`filament/filament 3.3`) |
| Sanctum | 4 (`laravel/sanctum ^4.0`) |
| Database | MySQL 8.0 (Docker) |
| Queue | `database` driver (current default) |
| Cache | `database` driver |
| Session | `database` driver |

### 1.2 Existing Models

| Model | File | Auth | Notes |
|---|---|---|---|
| [Admin](file:///s:/grad%20project/GRAD_PROJECT_2/grad_project_BE/IAAS_B.E/app/Models/Admin.php) | `app/Models/Admin.php` | Filament session (`web` guard, `admins` provider) | Roles: `super_admin`, `vehicle_admin`, `academic_admin`, `support_admin` |
| [Student](file:///s:/grad%20project/GRAD_PROJECT_2/grad_project_BE/IAAS_B.E/app/Models/Student.php) | `app/Models/Student.php` | Sanctum API tokens (`students` provider) | Has `student_id`, `full_name`, `email`, `password`, `faculty_id`, `gpa`, `credits_completed`, `credits_required` |
| [Faculty](file:///s:/grad%20project/GRAD_PROJECT_2/grad_project_BE/IAAS_B.E/app/Models/Faculty.php) | `app/Models/Faculty.php` | N/A | Simple `name` field |
| [VehicleRequest](file:///s:/grad%20project/GRAD_PROJECT_2/grad_project_BE/IAAS_B.E/app/Models/VehicleRequest.php) | `app/Models/VehicleRequest.php` | N/A | Unrelated vehicle-request logic — **do not modify** |
| [User](file:///s:/grad%20project/GRAD_PROJECT_2/grad_project_BE/IAAS_B.E/app/Models/User.php) | `app/Models/User.php` | N/A | Default Laravel User — unused by chatbot |

### 1.3 Existing Migrations (8 files)

| Migration | Table |
|---|---|
| `0001_01_01_000000_create_users_table.php` | `users`, `password_reset_tokens`, `sessions` |
| `0001_01_01_000001_create_cache_table.php` | `cache`, `cache_locks` |
| `0001_01_01_000002_create_jobs_table.php` | `jobs`, `job_batches`, `failed_jobs` |
| `2026_04_27_171144_create_personal_access_tokens_table.php` | `personal_access_tokens` |
| `2026_04_27_200000_create_admins_table.php` | `admins` |
| `2026_04_27_200001_create_faculties_table.php` | `faculties` |
| `2026_04_27_200002_create_students_table.php` | `students` |
| `2026_04_27_200003_create_vehicle_requests_table.php` | `vehicle_requests` |

### 1.4 Existing API Routes ([api.php](file:///s:/grad%20project/GRAD_PROJECT_2/grad_project_BE/IAAS_B.E/routes/api.php))

```
POST   /api/v1/student/login           (throttle:10,1)
POST   /api/v1/student/logout          (auth:sanctum, ensure.student)
GET    /api/v1/student/profile          (auth:sanctum, ensure.student)
GET    /api/v1/student/vehicle          (auth:sanctum, ensure.student)
POST   /api/v1/student/vehicle-requests (auth:sanctum, ensure.student)
GET    /api/v1/student/vehicle-requests/history (auth:sanctum, ensure.student)
POST   /api/v1/gate/vehicle-access/check (ensure.gate, throttle:60,1)
```

### 1.5 Existing Controllers

| Controller | File |
|---|---|
| [AuthController](file:///s:/grad%20project/GRAD_PROJECT_2/grad_project_BE/IAAS_B.E/app/Http/Controllers/Api/V1/Student/AuthController.php) | `app/Http/Controllers/Api/V1/Student/AuthController.php` |
| [ProfileController](file:///s:/grad%20project/GRAD_PROJECT_2/grad_project_BE/IAAS_B.E/app/Http/Controllers/Api/V1/Student/ProfileController.php) | `app/Http/Controllers/Api/V1/Student/ProfileController.php` |
| [VehicleController](file:///s:/grad%20project/GRAD_PROJECT_2/grad_project_BE/IAAS_B.E/app/Http/Controllers/Api/V1/Student/VehicleController.php) | `app/Http/Controllers/Api/V1/Student/VehicleController.php` |
| [VehicleAccessController](file:///s:/grad%20project/GRAD_PROJECT_2/grad_project_BE/IAAS_B.E/app/Http/Controllers/Api/V1/Gate/VehicleAccessController.php) | `app/Http/Controllers/Api/V1/Gate/VehicleAccessController.php` |

### 1.6 Existing Middleware

| Middleware | File | Purpose |
|---|---|---|
| [EnsureIsStudent](file:///s:/grad%20project/GRAD_PROJECT_2/grad_project_BE/IAAS_B.E/app/Http/Middleware/EnsureIsStudent.php) | `app/Http/Middleware/EnsureIsStudent.php` | Verifies Sanctum token belongs to `Student` model |
| [EnsureGateApiKey](file:///s:/grad%20project/GRAD_PROJECT_2/grad_project_BE/IAAS_B.E/app/Http/Middleware/EnsureGateApiKey.php) | `app/Http/Middleware/EnsureGateApiKey.php` | Validates Gate API key header |

### 1.7 Existing Filament Resources

| Resource | Model | Access Policy |
|---|---|---|
| [AdminResource](file:///s:/grad%20project/GRAD_PROJECT_2/grad_project_BE/IAAS_B.E/app/Filament/Resources/AdminResource.php) | Admin | `super_admin` only |
| [FacultyResource](file:///s:/grad%20project/GRAD_PROJECT_2/grad_project_BE/IAAS_B.E/app/Filament/Resources/FacultyResource.php) | Faculty | `super_admin`, `academic_admin` |
| [StudentResource](file:///s:/grad%20project/GRAD_PROJECT_2/grad_project_BE/IAAS_B.E/app/Filament/Resources/StudentResource.php) | Student | `super_admin`, `academic_admin` |
| [VehicleRequestResource](file:///s:/grad%20project/GRAD_PROJECT_2/grad_project_BE/IAAS_B.E/app/Filament/Resources/VehicleRequestResource.php) | VehicleRequest | `super_admin`, `vehicle_admin` |

### 1.8 Existing Docker Setup ([docker-compose.yml](file:///s:/grad%20project/GRAD_PROJECT_2/grad_project_BE/IAAS_B.E/docker-compose.yml))

- **app** service: PHP 8.3-FPM, `container_name: galala_iaas_app`
- **nginx** service: nginx:alpine, port `8000:80`
- **mysql** service: MySQL 8.0, port `3307:3306`, healthcheck
- **Network**: `galala_network` (bridge)
- **No Redis service** currently exists
- **No worker service** currently exists
- **Queue connection**: `database` (not Redis)

### 1.9 Existing Auth Configuration ([auth.php](file:///s:/grad%20project/GRAD_PROJECT_2/grad_project_BE/IAAS_B.E/config/auth.php))

- Default guard: `web` (session, `admins` provider → `Admin` model)
- Sanctum resolves API tokens via `students` provider → `Student` model
- Sanctum guard config: `['web']`

> [!IMPORTANT]
> **Key Observation**: The existing `students` migration uses `cascadeOnDelete()` for `faculty_id`. The chatbot feature requires `restrictOnDelete()` on `chat_conversations.student_id`. These are separate FK constraints — no conflict.

### 1.10 Existing `.env.example` Notable Values

```env
QUEUE_CONNECTION=database
CACHE_STORE=database
SESSION_DRIVER=database
# No Redis variables present
# No AI-related variables present
```

---

## 2. Approved Frozen Decisions

These decisions are final and may not be changed without explicit user approval.

### 2.1 Architecture Flow

```
Frontend → Laravel API → Redis queue → AI team API → Laravel stores result → Frontend polls Laravel
```

- Laravel is the **sole** component that reads/writes the project database.
- Frontend **never** calls AI API directly.
- AI team **never** receives database credentials.

### 2.2 Two Separate AI APIs

Two completely separate AI integrations, each with its own:
- URL
- Bearer service token
- Payload builder
- Client contract (interface)
- HTTP client implementation
- Deterministic fake client for testing

**No combined/shared AI client.**

### 2.3 Queue

- Dedicated queue name: `ai-chat` (env var `AI_CHAT_QUEUE`)
- Driver: Redis (changing from current `database` driver)
- Dispatch via: `->onQueue(config('chat.ai_queue'))`

### 2.4 Timeout Hierarchy (Frozen)

| Layer | Value |
|---|---|
| AI HTTP timeout | 420 seconds |
| Job timeout | 450 seconds |
| Worker `--timeout` | 460 seconds |
| Redis `retry_after` | 510 seconds |

### 2.5 Job Properties (Frozen)

```php
public int $timeout = 450;
public int $tries = 1;
public bool $failOnTimeout = true;
```

- Use `failed(Throwable $exception)` to store failed status.
- Jobs receive **scalar IDs**, not serialized Eloquent models.
- Student jobs dispatched **only after database commit**.

### 2.6 Guest Storage

- **No MySQL** for guest history — Redis only.
- Guest token: `Str::random(64)`
- Hash before Redis key: `hash('sha256', $token)`
- TTL: 86,400 seconds (24 hours)
- Pending-lock TTL: 600 seconds
- Atomic lock: `SET key requestId NX EX 600`
- Reject duplicate: `409 Conflict`
- Cleanup pending lock on setup/dispatch failure

### 2.7 Student Chat Rules

- UUIDs as public identifiers for conversations, messages, AI requests
- `client_message_id` required (`['required', 'uuid']`), globally unique
- Title from first message: `Str::limit($content, 50, '')`
- Soft-hide: `deleted_by_student_at = now()`
- Hidden chats blocked from all student endpoints
- Message max: 4,000 characters (configurable)
- Unlimited chats per student in V1
- No second message while assistant response is pending

### 2.8 Admin Dashboard Rules

- Access: `super_admin` and `support_admin` only
- View conversations, view ordered messages
- Restore hidden chats, permanently delete single conversation
- **No bulk hard deletion**
- 100-char preview with expand modal
- Hard deletion blocked while AI requests are `queued` or `processing`
- Re-check immediately before permanent deletion
- Hard-delete cascades: conversation → messages → AI request rows
- No deletion audit-log table in V1

### 2.9 Student Deletion Protection

- `restrictOnDelete()` on `chat_conversations.student_id`
- Pre-check before Filament delete: `$student->chatConversations()->exists()`
- Block with message: `Cannot delete this student because saved chatbot conversations exist.`
- Validate all selected students before bulk deletion

### 2.10 Guest Throttle

- Named limiter: `guest-chat-submit`
- Default: 10 requests / 1 minute
- Applied only to guest message submission

### 2.11 Redis Docker Rules

- Add Redis to Docker Compose
- **No published ports** (`ports:` block forbidden)
- Docker internal network only
- Persistence: `--appendonly yes`
- Non-empty Redis password required (even local dev)
- Never commit passwords
- **No fixed `container_name`** for worker service (scalability)

### 2.12 Postponed Features (Do Not Implement)

- Callbacks
- Streaming
- CAPTCHA
- Cross-chat memory
- Laravel-side history summarization
- Deletion audit logs
- Vehicle context in chatbot payload
- Unrelated refactors

---

## 3. Existing Files Reused Without Changes

These files are used by the chatbot feature but require **zero modifications**:

| File | Reason |
|---|---|
| [Admin.php](file:///s:/grad%20project/GRAD_PROJECT_2/grad_project_BE/IAAS_B.E/app/Models/Admin.php) | Role helpers (`isSuperAdmin()`, `isSupportAdmin()`) reused by chatbot policy |
| [Faculty.php](file:///s:/grad%20project/GRAD_PROJECT_2/grad_project_BE/IAAS_B.E/app/Models/Faculty.php) | Faculty name sent in student profile context via existing `->faculty` relationship |
| [VehicleRequest.php](file:///s:/grad%20project/GRAD_PROJECT_2/grad_project_BE/IAAS_B.E/app/Models/VehicleRequest.php) | Untouched — AGENTS.md rule |
| [EnsureIsStudent.php](file:///s:/grad%20project/GRAD_PROJECT_2/grad_project_BE/IAAS_B.E/app/Http/Middleware/EnsureIsStudent.php) | Reused for chatbot authenticated routes |
| [EnsureGateApiKey.php](file:///s:/grad%20project/GRAD_PROJECT_2/grad_project_BE/IAAS_B.E/app/Http/Middleware/EnsureGateApiKey.php) | Untouched |
| [AuthController.php](file:///s:/grad%20project/GRAD_PROJECT_2/grad_project_BE/IAAS_B.E/app/Http/Controllers/Api/V1/Student/AuthController.php) | Untouched |
| [ProfileController.php](file:///s:/grad%20project/GRAD_PROJECT_2/grad_project_BE/IAAS_B.E/app/Http/Controllers/Api/V1/Student/ProfileController.php) | Untouched |
| [VehicleController.php](file:///s:/grad%20project/GRAD_PROJECT_2/grad_project_BE/IAAS_B.E/app/Http/Controllers/Api/V1/Student/VehicleController.php) | Untouched |
| [VehicleAccessController.php](file:///s:/grad%20project/GRAD_PROJECT_2/grad_project_BE/IAAS_B.E/app/Http/Controllers/Api/V1/Gate/VehicleAccessController.php) | Untouched |
| [AdminPolicy.php](file:///s:/grad%20project/GRAD_PROJECT_2/grad_project_BE/IAAS_B.E/app/Policies/AdminPolicy.php) | Untouched |
| [FacultyPolicy.php](file:///s:/grad%20project/GRAD_PROJECT_2/grad_project_BE/IAAS_B.E/app/Policies/FacultyPolicy.php) | Untouched |
| [VehicleRequestPolicy.php](file:///s:/grad%20project/GRAD_PROJECT_2/grad_project_BE/IAAS_B.E/app/Policies/VehicleRequestPolicy.php) | Untouched |
| [AdminPanelProvider.php](file:///s:/grad%20project/GRAD_PROJECT_2/grad_project_BE/IAAS_B.E/app/Providers/Filament/AdminPanelProvider.php) | Filament auto-discovers resources, no change needed |
| [AdminResource.php](file:///s:/grad%20project/GRAD_PROJECT_2/grad_project_BE/IAAS_B.E/app/Filament/Resources/AdminResource.php) | Untouched |
| [FacultyResource.php](file:///s:/grad%20project/GRAD_PROJECT_2/grad_project_BE/IAAS_B.E/app/Filament/Resources/FacultyResource.php) | Untouched |
| [VehicleRequestResource.php](file:///s:/grad%20project/GRAD_PROJECT_2/grad_project_BE/IAAS_B.E/app/Filament/Resources/VehicleRequestResource.php) | Untouched |
| [Dockerfile](file:///s:/grad%20project/GRAD_PROJECT_2/grad_project_BE/IAAS_B.E/Dockerfile) | Base Dockerfile reused; needs `phpredis` extension added |

---

## 4. Existing Files Requiring Modification

### 4.1 [Student.php](file:///s:/grad%20project/GRAD_PROJECT_2/grad_project_BE/IAAS_B.E/app/Models/Student.php)

**Add relationship:**
```php
public function chatConversations(): HasMany
{
    return $this->hasMany(\App\Models\ChatConversation::class, 'student_id');
}
```

### 4.2 [AppServiceProvider.php](file:///s:/grad%20project/GRAD_PROJECT_2/grad_project_BE/IAAS_B.E/app/Providers/AppServiceProvider.php)

**Add to `register()`:**
- Singleton bindings for `StudentAiChatClientContract` → `HttpStudentAiChatClient` (or `FakeStudentAiChatClient` in testing)
- Singleton bindings for `GuestAiChatClientContract` → `HttpGuestAiChatClient` (or `FakeGuestAiChatClient` in testing)
- Singleton binding for `GuestChatStore` → `RedisGuestChatStore` (or `InMemoryGuestChatStore` in testing)

**Add to `boot()`:**
- `Gate::policy(ChatConversation::class, ChatConversationPolicy::class);`
- Rate limiter definition for `guest-chat-submit`

### 4.3 [api.php](file:///s:/grad%20project/GRAD_PROJECT_2/grad_project_BE/IAAS_B.E/routes/api.php)

**Add** student chatbot routes (inside existing `v1/student` protected group):
```
POST   /api/v1/student/chats
GET    /api/v1/student/chats
GET    /api/v1/student/chats/{chatUuid}
PATCH  /api/v1/student/chats/{chatUuid}
DELETE /api/v1/student/chats/{chatUuid}
POST   /api/v1/student/chats/{chatUuid}/messages
GET    /api/v1/student/chats/{chatUuid}/messages/{messageUuid}/status
POST   /api/v1/student/chats/{chatUuid}/messages/{messageUuid}/retry
```

**Add** guest chatbot routes (new group):
```
POST   /api/v1/guest/chat/messages        (throttle:guest-chat-submit)
GET    /api/v1/guest/chat/messages/{requestId}/status
GET    /api/v1/guest/chat/history
```

### 4.4 [StudentResource.php](file:///s:/grad%20project/GRAD_PROJECT_2/grad_project_BE/IAAS_B.E/app/Filament/Resources/StudentResource.php)

**Modify** delete action and bulk delete to pre-check `$student->chatConversations()->exists()` and block with notification message.

### 4.5 [StudentPolicy.php](file:///s:/grad%20project/GRAD_PROJECT_2/grad_project_BE/IAAS_B.E/app/Policies/StudentPolicy.php)

No schema change needed — delete permission remains policy-controlled. The pre-check is added at the Filament resource/page level.

### 4.6 [docker-compose.yml](file:///s:/grad%20project/GRAD_PROJECT_2/grad_project_BE/IAAS_B.E/docker-compose.yml)

**Add:**
- `redis` service (no published ports, `--appendonly yes`, password from env)
- `worker` service (no fixed `container_name`, runs `queue:work redis --queue=ai-chat --tries=1 --timeout=460 --sleep=3`)
- Update `app` service environment to include `REDIS_HOST`, `REDIS_PASSWORD`, `QUEUE_CONNECTION=redis`

### 4.7 [Dockerfile](file:///s:/grad%20project/GRAD_PROJECT_2/grad_project_BE/IAAS_B.E/Dockerfile)

**Add** `pecl install redis && docker-php-ext-enable redis` to install the phpredis extension.

### 4.8 [.env.example](file:///s:/grad%20project/GRAD_PROJECT_2/grad_project_BE/IAAS_B.E/.env.example)

**Add** chatbot-specific environment variables (see §14 below).

### 4.9 [config/queue.php](file:///s:/grad%20project/GRAD_PROJECT_2/grad_project_BE/IAAS_B.E/config/queue.php)

**Modify** Redis connection `retry_after` to `510`.

---

## 5. New Files Requiring Creation

### 5.1 Migrations

| File | Tables |
|---|---|
| `database/migrations/YYYY_MM_DD_HHMMSS_create_chat_conversations_table.php` | `chat_conversations` |
| `database/migrations/YYYY_MM_DD_HHMMSS_create_chat_messages_table.php` | `chat_messages` |
| `database/migrations/YYYY_MM_DD_HHMMSS_create_chat_ai_requests_table.php` | `chat_ai_requests` |

### 5.2 Models

| File | Model |
|---|---|
| `app/Models/ChatConversation.php` | `ChatConversation` |
| `app/Models/ChatMessage.php` | `ChatMessage` |
| `app/Models/ChatAiRequest.php` | `ChatAiRequest` |

### 5.3 Controllers

| File | Controller |
|---|---|
| `app/Http/Controllers/Api/V1/Student/ChatController.php` | `ChatController` |
| `app/Http/Controllers/Api/V1/Guest/GuestChatController.php` | `GuestChatController` |

### 5.4 Form Requests

| File | Request |
|---|---|
| `app/Http/Requests/Student/CreateChatRequest.php` | Create chat + first message |
| `app/Http/Requests/Student/RenameChatRequest.php` | Rename conversation |
| `app/Http/Requests/Student/SendMessageRequest.php` | Send message in existing chat |
| `app/Http/Requests/Guest/SendGuestMessageRequest.php` | Guest message |

### 5.5 AI Client Contracts

| File | Interface |
|---|---|
| `app/Contracts/StudentAiChatClientContract.php` | Student AI client interface |
| `app/Contracts/GuestAiChatClientContract.php` | Guest AI client interface |

### 5.6 AI Client Implementations

| File | Class |
|---|---|
| `app/Services/Ai/HttpStudentAiChatClient.php` | Real HTTP student AI client |
| `app/Services/Ai/FakeStudentAiChatClient.php` | Deterministic fake student client |
| `app/Services/Ai/HttpGuestAiChatClient.php` | Real HTTP guest AI client |
| `app/Services/Ai/FakeGuestAiChatClient.php` | Deterministic fake guest client |

### 5.7 Guest Chat Store

| File | Class |
|---|---|
| `app/Contracts/GuestChatStore.php` | Guest store interface |
| `app/Services/Guest/RedisGuestChatStore.php` | Redis-backed implementation |
| `app/Services/Guest/InMemoryGuestChatStore.php` | In-memory test implementation |

### 5.8 Queue Jobs

| File | Job |
|---|---|
| `app/Jobs/ProcessStudentAiChat.php` | Student AI request job |
| `app/Jobs/ProcessGuestAiChat.php` | Guest AI request job |

### 5.9 Payload Builders

| File | Class |
|---|---|
| `app/Services/Ai/StudentPayloadBuilder.php` | Builds student context payload |
| `app/Services/Ai/GuestPayloadBuilder.php` | Builds guest payload |

### 5.10 Policies

| File | Policy |
|---|---|
| `app/Policies/ChatConversationPolicy.php` | Filament admin access to conversations |

### 5.11 Filament Resources

| File | Resource |
|---|---|
| `app/Filament/Resources/ChatConversationResource.php` | Conversation list |
| `app/Filament/Resources/ChatConversationResource/Pages/ListChatConversations.php` | List page |
| `app/Filament/Resources/ChatConversationResource/Pages/ViewChatConversation.php` | View page with messages |

### 5.12 Configuration

| File | Purpose |
|---|---|
| `config/chat.php` | Chatbot config (queue name, timeouts, TTLs, max message length, AI URLs, throttle) |

### 5.13 Tests

| File | Suite |
|---|---|
| `tests/Feature/StudentChatTest.php` | Student chat API tests |
| `tests/Feature/GuestChatTest.php` | Guest chat API tests |
| `tests/Feature/ChatAdminTest.php` | Filament admin tests |
| `tests/Unit/StudentPayloadBuilderTest.php` | Student context allowlist test |
| `tests/Unit/GuestChatStoreTest.php` | InMemoryGuestChatStore tests |

---

## 6. Exact Migration Schemas

### 6.1 `chat_conversations`

```php
Schema::create('chat_conversations', function (Blueprint $table) {
    $table->id();
    $table->uuid('uuid')->unique();
    $table->foreignId('student_id')
          ->constrained('students')
          ->restrictOnDelete();
    $table->string('title');
    $table->string('status')->default('active');        // active
    $table->timestamp('last_message_at')->nullable();
    $table->timestamp('deleted_by_student_at')->nullable();
    $table->timestamps();

    $table->index(['student_id', 'deleted_by_student_at']);
    $table->index('last_message_at');
});
```

### 6.2 `chat_messages`

```php
Schema::create('chat_messages', function (Blueprint $table) {
    $table->id();
    $table->uuid('uuid')->unique();
    $table->foreignId('chat_conversation_id')
          ->constrained('chat_conversations')
          ->cascadeOnDelete();
    $table->string('role');                              // user, assistant
    $table->text('content')->nullable();                 // nullable for pending assistant placeholders
    $table->string('status')->default('completed');      // completed, pending, failed
    $table->unsignedInteger('sequence_number');
    $table->uuid('client_message_id')->nullable();       // nullable in DB, required by API
    $table->timestamps();

    $table->unique('client_message_id');                 // global uniqueness
    $table->unique(['chat_conversation_id', 'sequence_number']);
    $table->index(['chat_conversation_id', 'role', 'status']);
});
```

### 6.3 `chat_ai_requests`

```php
Schema::create('chat_ai_requests', function (Blueprint $table) {
    $table->id();
    $table->uuid('uuid')->unique();
    $table->foreignId('chat_conversation_id')
          ->constrained('chat_conversations')
          ->cascadeOnDelete();
    $table->foreignId('user_message_id')
          ->constrained('chat_messages')
          ->cascadeOnDelete();
    $table->foreignId('assistant_message_id')
          ->constrained('chat_messages')
          ->cascadeOnDelete();
    $table->string('status')->default('queued');         // queued, processing, completed, failed
    $table->unsignedInteger('attempt_number')->default(1);
    $table->string('error_code')->nullable();
    $table->text('error_message')->nullable();
    $table->timestamp('submitted_at')->nullable();
    $table->timestamp('completed_at')->nullable();
    $table->timestamp('failed_at')->nullable();
    $table->timestamps();

    $table->index(['chat_conversation_id', 'status']);
    $table->index('assistant_message_id');
});
```

---

## 7. Exact Indexes and Constraints

### 7.1 Foreign Keys

| Table | Column | References | On Delete |
|---|---|---|---|
| `chat_conversations` | `student_id` | `students.id` | **RESTRICT** |
| `chat_messages` | `chat_conversation_id` | `chat_conversations.id` | CASCADE |
| `chat_ai_requests` | `chat_conversation_id` | `chat_conversations.id` | CASCADE |
| `chat_ai_requests` | `user_message_id` | `chat_messages.id` | CASCADE |
| `chat_ai_requests` | `assistant_message_id` | `chat_messages.id` | CASCADE |

### 7.2 Unique Indexes

| Table | Column(s) | Type |
|---|---|---|
| `chat_conversations` | `uuid` | UNIQUE |
| `chat_messages` | `uuid` | UNIQUE |
| `chat_messages` | `client_message_id` | UNIQUE (global) |
| `chat_messages` | `(chat_conversation_id, sequence_number)` | UNIQUE (composite) |
| `chat_ai_requests` | `uuid` | UNIQUE |

### 7.3 Regular Indexes

| Table | Column(s) |
|---|---|
| `chat_conversations` | `(student_id, deleted_by_student_at)` |
| `chat_conversations` | `last_message_at` |
| `chat_messages` | `(chat_conversation_id, role, status)` |
| `chat_ai_requests` | `(chat_conversation_id, status)` |
| `chat_ai_requests` | `assistant_message_id` |

---

## 8. Exact Eloquent Relationships

### 8.1 `ChatConversation`

```php
public function student(): BelongsTo
{
    return $this->belongsTo(Student::class, 'student_id');
}

public function messages(): HasMany
{
    return $this->hasMany(ChatMessage::class, 'chat_conversation_id');
}

public function aiRequests(): HasMany
{
    return $this->hasMany(ChatAiRequest::class, 'chat_conversation_id');
}
```

**Scopes:**
```php
public function scopeVisibleToStudent(Builder $query): Builder
{
    return $query->whereNull('deleted_by_student_at');
}
```

### 8.2 `ChatMessage`

```php
public function conversation(): BelongsTo
{
    return $this->belongsTo(ChatConversation::class, 'chat_conversation_id');
}

// HasMany because retries create multiple AI-request attempts
public function aiRequestsAsUser(): HasMany
{
    return $this->hasMany(ChatAiRequest::class, 'user_message_id');
}

// HasMany because retries create multiple AI-request attempts
public function aiRequestsAsAssistant(): HasMany
{
    return $this->hasMany(ChatAiRequest::class, 'assistant_message_id');
}
```

> [!IMPORTANT]
> Use `HasMany`, not `HasOne`, for both `aiRequestsAsUser()` and `aiRequestsAsAssistant()` because retries create multiple AI-request attempts for the same message pair.

### 8.3 `ChatAiRequest`

```php
public function conversation(): BelongsTo
{
    return $this->belongsTo(ChatConversation::class, 'chat_conversation_id');
}

public function userMessage(): BelongsTo
{
    return $this->belongsTo(ChatMessage::class, 'user_message_id');
}

public function assistantMessage(): BelongsTo
{
    return $this->belongsTo(ChatMessage::class, 'assistant_message_id');
}
```

### 8.4 `Student` (addition)

```php
public function chatConversations(): HasMany
{
    return $this->hasMany(ChatConversation::class, 'student_id');
}
```

---

## 9. Exact Student API Routes

All under `auth:sanctum` + `ensure.student` middleware group:

| Method | URI | Controller Method | Purpose |
|---|---|---|---|
| `POST` | `/api/v1/student/chats` | `ChatController@store` | Create conversation + first message + pending assistant + AI request → **202 Accepted** |
| `GET` | `/api/v1/student/chats` | `ChatController@index` | List visible conversations (ordered by `last_message_at` desc) |
| `GET` | `/api/v1/student/chats/{chatUuid}` | `ChatController@show` | Show conversation with messages |
| `PATCH` | `/api/v1/student/chats/{chatUuid}` | `ChatController@update` | Rename conversation title |
| `DELETE` | `/api/v1/student/chats/{chatUuid}` | `ChatController@destroy` | Soft-hide: sets `deleted_by_student_at = now()` |
| `POST` | `/api/v1/student/chats/{chatUuid}/messages` | `ChatController@sendMessage` | Send message in existing chat → **202 Accepted** |
| `GET` | `/api/v1/student/chats/{chatUuid}/messages/{messageUuid}/status` | `ChatController@messageStatus` | Poll AI response status |
| `POST` | `/api/v1/student/chats/{chatUuid}/messages/{messageUuid}/retry` | `ChatController@retryMessage` | Retry failed AI response |

---

## 10. Exact Guest API Routes

No authentication middleware. Guest token passed via header or request body.

| Method | URI | Middleware | Controller Method | Purpose |
|---|---|---|---|---|
| `POST` | `/api/v1/guest/chat/messages` | `throttle:guest-chat-submit` | `GuestChatController@send` | Send guest message → **202 Accepted** |
| `GET` | `/api/v1/guest/chat/messages/{requestId}/status` | — | `GuestChatController@status` | Poll AI response status |
| `GET` | `/api/v1/guest/chat/history` | — | `GuestChatController@history` | Retrieve guest chat history |

---

## 11. Exact Controllers and Responsibilities

### 11.1 `ChatController` (Student)

**File:** `app/Http/Controllers/Api/V1/Student/ChatController.php`

| Method | Responsibility |
|---|---|
| `store()` | Validate via `CreateChatRequest`. In DB transaction: create `ChatConversation` (uuid, title from `Str::limit`), create user `ChatMessage` (uuid, sequence 1, `client_message_id`), create assistant `ChatMessage` (uuid, sequence 2, status=`pending`, content=null), create `ChatAiRequest` (uuid, status=`queued`), update `last_message_at`. After commit: dispatch `ProcessStudentAiChat` job with scalar IDs. Return 202. |
| `index()` | Return paginated visible conversations (`whereNull('deleted_by_student_at')`) for authenticated student, ordered by `last_message_at` desc. |
| `show()` | Find conversation by UUID, verify ownership + visible, eager-load messages ordered by `sequence_number`. |
| `update()` | Validate via `RenameChatRequest`. Update `title` on owned visible conversation. |
| `destroy()` | Set `deleted_by_student_at = now()` on owned visible conversation. |
| `sendMessage()` | Validate via `SendMessageRequest`. Verify no pending assistant message exists. In DB transaction: create user message, create pending assistant placeholder, create AI request, update `last_message_at`. After commit: dispatch job. Return 202. |
| `messageStatus()` | Find assistant message by UUID in owned visible conversation. Return latest AI request status. |
| `retryMessage()` | Find failed assistant message. In DB transaction: reset assistant message status to `pending`, create new `ChatAiRequest` with incremented `attempt_number`. After commit: dispatch job. Return 202. |

### 11.2 `GuestChatController` (Guest)

**File:** `app/Http/Controllers/Api/V1/Guest/GuestChatController.php`

| Method | Responsibility |
|---|---|
| `send()` | Validate via `SendGuestMessageRequest`. If no guest token in header: generate token (`Str::random(64)`), hash it. Acquire atomic pending lock (`SET guest_chat:{hash}:pending requestId NX EX 600`). If lock fails: return 409. Store user message in Redis list (`guest_chat:{hash}:messages`). Dispatch `ProcessGuestAiChat` job with scalar IDs (tokenHash, requestId). Touch TTL on messages key. Return 202 with guest token (first time) or request ID. On failure after lock: clear pending lock immediately. |
| `status()` | Read `guest_ai_request:{requestId}` from Redis. Return status, response if completed. |
| `history()` | Read `guest_chat:{hash}:messages` list from Redis. Return messages array. |

---

## 12. Exact Form Request Validation Rules

### 12.1 `CreateChatRequest`

```php
public function rules(): array
{
    return [
        'message' => ['required', 'string', 'max:' . config('chat.max_message_length', 4000)],
        'client_message_id' => ['required', 'uuid', 'unique:chat_messages,client_message_id'],
    ];
}
```

### 12.2 `RenameChatRequest`

```php
public function rules(): array
{
    return [
        'title' => ['required', 'string', 'max:255'],
    ];
}
```

### 12.3 `SendMessageRequest`

```php
public function rules(): array
{
    return [
        'message' => ['required', 'string', 'max:' . config('chat.max_message_length', 4000)],
        'client_message_id' => ['required', 'uuid', 'unique:chat_messages,client_message_id'],
    ];
}
```

### 12.4 `SendGuestMessageRequest`

```php
public function rules(): array
{
    return [
        'message' => ['required', 'string', 'max:' . config('chat.max_message_length', 4000)],
    ];
}
```

---

## 13. Exact Separate AI Client Contracts and Implementations

### 13.1 `StudentAiChatClientContract`

```php
namespace App\Contracts;

interface StudentAiChatClientContract
{
    /**
     * Send a student chat request to the AI API.
     *
     * @param array $payload  Student context + message history
     * @return array          ['content' => string]
     * @throws \App\Exceptions\AiClientException
     */
    public function send(array $payload): array;
}
```

### 13.2 `GuestAiChatClientContract`

```php
namespace App\Contracts;

interface GuestAiChatClientContract
{
    /**
     * Send a guest chat request to the AI API.
     *
     * @param array $payload  Guest message + history
     * @return array          ['content' => string]
     * @throws \App\Exceptions\AiClientException
     */
    public function send(array $payload): array;
}
```

### 13.3 `HttpStudentAiChatClient`

- Uses `Http::timeout(420)->withToken(config('chat.student_ai.token'))->post(config('chat.student_ai.url'), $payload)`
- Throws `AiClientException` on non-2xx or timeout

### 13.4 `HttpGuestAiChatClient`

- Uses `Http::timeout(420)->withToken(config('chat.guest_ai.token'))->post(config('chat.guest_ai.url'), $payload)`
- Throws `AiClientException` on non-2xx or timeout

### 13.5 `FakeStudentAiChatClient`

- Returns deterministic response: `['content' => 'Fake student AI response']`
- Tracks calls for assertions in tests
- Bound as singleton in test environment

### 13.6 `FakeGuestAiChatClient`

- Returns deterministic response: `['content' => 'Fake guest AI response']`
- Tracks calls for assertions in tests
- Bound as singleton in test environment

---

## 14. Exact Singleton Service-Container Bindings

In `AppServiceProvider::register()`:

```php
// Student AI client
$this->app->singleton(StudentAiChatClientContract::class, function ($app) {
    if ($app->environment('testing')) {
        return new FakeStudentAiChatClient();
    }
    return new HttpStudentAiChatClient();
});

// Guest AI client
$this->app->singleton(GuestAiChatClientContract::class, function ($app) {
    if ($app->environment('testing')) {
        return new FakeGuestAiChatClient();
    }
    return new HttpGuestAiChatClient();
});

// Guest chat store
$this->app->singleton(GuestChatStore::class, function ($app) {
    if ($app->environment('testing')) {
        return new InMemoryGuestChatStore();
    }
    return new RedisGuestChatStore();
});
```

---

## 15. Exact Student-Context Allowlist

The `StudentPayloadBuilder` extracts **only** these fields from the authenticated student:

```php
[
    'student_id'        => $student->student_id,
    'full_name'         => $student->full_name,
    'email'             => $student->email,
    'faculty_id'        => $student->faculty_id,
    'faculty_name'      => $student->faculty->name,
    'gpa'               => $student->gpa,
    'credits_completed' => $student->credits_completed,
    'credits_required'  => $student->credits_required,
]
```

**Never send:**
- Password hashes
- Sanctum tokens
- Internal secrets
- Admin data
- Vehicle-request data
- Database credentials

---

## 16. Exact Queue Jobs with Scalar Identifiers

### 16.1 `ProcessStudentAiChat`

```php
class ProcessStudentAiChat implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 450;
    public int $tries = 1;
    public bool $failOnTimeout = true;

    public function __construct(
        public readonly int $aiRequestId,       // scalar
        public readonly int $conversationId,    // scalar
        public readonly int $studentId,         // scalar
    ) {}

    public function handle(StudentAiChatClientContract $client): void
    {
        // 1. Load AI request, conversation, student, message history
        // 2. Build payload via StudentPayloadBuilder
        // 3. Update AI request status → processing, submitted_at
        // 4. Call $client->send($payload)
        // 5. Update assistant message content + status → completed
        // 6. Update AI request status → completed, completed_at
    }

    public function failed(Throwable $exception): void
    {
        // 1. Update AI request status → failed, failed_at, error_code, error_message
        // 2. Update assistant message status → failed
    }
}
```

**Dispatch (after DB commit):**
```php
DB::afterCommit(function () use ($aiRequest, $conversation, $student) {
    ProcessStudentAiChat::dispatch(
        $aiRequest->id,
        $conversation->id,
        $student->id,
    )->onQueue(config('chat.ai_queue'));
});
```

### 16.2 `ProcessGuestAiChat`

```php
class ProcessGuestAiChat implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 450;
    public int $tries = 1;
    public bool $failOnTimeout = true;

    public function __construct(
        public readonly string $tokenHash,    // scalar
        public readonly string $requestId,    // scalar (UUID)
    ) {}

    public function handle(
        GuestAiChatClientContract $client,
        GuestChatStore $store,
    ): void {
        // 1. Read message history from store
        // 2. Build payload via GuestPayloadBuilder
        // 3. Call $client->send($payload)
        // 4. Store AI response in guest_ai_request:{requestId}
        // 5. Append assistant message to guest_chat:{hash}:messages
        // 6. Clear pending lock: guest_chat:{hash}:pending
        // 7. Touch TTL on messages key
    }

    public function failed(Throwable $exception): void
    {
        // 1. Store error in guest_ai_request:{requestId}
        // 2. Clear pending lock: guest_chat:{hash}:pending
    }
}
```

**Dispatch (no DB transaction involved):**
```php
ProcessGuestAiChat::dispatch($tokenHash, $requestId)
    ->onQueue(config('chat.ai_queue'));
```

---

## 17. Exact After-Commit Dispatch Flow

For student chatbot only (guest has no DB transaction):

```php
// Inside controller, within DB::transaction closure:
// 1. Create conversation
// 2. Create user message
// 3. Create assistant placeholder
// 4. Create AI request
// 5. Update last_message_at

// After transaction commits:
DB::afterCommit(function () use ($aiRequestId, $conversationId, $studentId) {
    ProcessStudentAiChat::dispatch($aiRequestId, $conversationId, $studentId)
        ->onQueue(config('chat.ai_queue'));
});
```

This ensures the job never runs against uncommitted database records.

---

## 18. Exact Timeout Hierarchy

```
┌─────────────────────┬──────────┐
│ Layer               │ Seconds  │
├─────────────────────┼──────────┤
│ AI HTTP timeout     │ 420      │
│ Job timeout         │ 450      │
│ Worker --timeout    │ 460      │
│ Redis retry_after   │ 510      │
└─────────────────────┴──────────┘
```

**Rationale**: Each layer is larger than the one below to prevent premature termination. The AI HTTP call has 420s to respond. If it doesn't, the job has 30s of margin before its own 450s timeout kills it. The worker has 10s margin beyond the job. Redis `retry_after` is set to 510s to prevent duplicate job pickup while the worker is still processing.

---

## 19. Exact Dedicated `ai-chat` Queue Behavior

### 19.1 Configuration (`config/chat.php`)

```php
return [
    'ai_queue' => env('AI_CHAT_QUEUE', 'ai-chat'),
    // ...
];
```

### 19.2 Worker Command

```bash
php artisan queue:work redis \
  --queue=ai-chat \
  --tries=1 \
  --timeout=460 \
  --sleep=3
```

### 19.3 Redis Queue Connection (`config/queue.php`)

```php
'redis' => [
    'driver' => 'redis',
    'connection' => env('REDIS_QUEUE_CONNECTION', 'default'),
    'queue' => env('REDIS_QUEUE', 'default'),
    'retry_after' => (int) env('REDIS_QUEUE_RETRY_AFTER', 510),
    'block_for' => null,
    'after_commit' => false,
],
```

---

## 20. Exact Guest Token, Hashing, Atomic Lock, TTL, and Cleanup Behavior

### 20.1 Token Generation

```php
$token = Str::random(64);
```

Returned to the guest in the first response. Guest must include this token in all subsequent requests via `X-Guest-Token` header.

### 20.2 Token Hashing

```php
$tokenHash = hash('sha256', $token);
```

The plain token is **never** stored server-side. Only the hash is used in Redis keys.

### 20.3 Redis Key Structure

```
guest_chat:{tokenHash}:messages     → Redis List of JSON messages
guest_chat:{tokenHash}:pending      → String (requestId), atomic lock
guest_ai_request:{requestId}        → Hash with status, response, error
```

### 20.4 Atomic Pending Lock

```php
$locked = Redis::set(
    "guest_chat:{$tokenHash}:pending",
    $requestId,
    'NX',    // only if not exists
    'EX',    // expiry in seconds
    600      // 10 minutes
);

if (!$locked) {
    return response()->json([
        'success' => false,
        'message' => 'A response is already pending. Please wait.',
    ], 409);
}
```

### 20.5 TTL Management

- `guest_chat:{tokenHash}:messages` → TTL refreshed to 86,400s on every message
- `guest_chat:{tokenHash}:pending` → TTL 600s (auto-expires)
- `guest_ai_request:{requestId}` → TTL 86,400s

### 20.6 Cleanup on Failure

If guest setup or dispatch fails **after** acquiring the pending lock:

```php
try {
    // Store message, dispatch job
} catch (\Throwable $e) {
    Redis::del("guest_chat:{$tokenHash}:pending");
    throw $e;
}
```

---

## 21. Exact Redis Docker Rules, Password Requirements, and Worker Scalability

### 21.1 Redis Service in `docker-compose.yml`

```yaml
redis:
  image: redis:7-alpine
  restart: unless-stopped
  command: redis-server --requirepass ${REDIS_PASSWORD} --appendonly yes
  volumes:
    - galala_redis_data:/data
  networks:
    - galala_network
  healthcheck:
    test: ["CMD", "redis-cli", "-a", "${REDIS_PASSWORD}", "ping"]
    interval: 10s
    timeout: 5s
    retries: 5
```

**No `ports:` block.** Redis is internal-only.

**No fixed `container_name`.**

### 21.2 Worker Service in `docker-compose.yml`

```yaml
worker:
  build:
    context: .
    dockerfile: Dockerfile
  # NO container_name — allows scaling: docker compose up --scale worker=N
  restart: unless-stopped
  working_dir: /var/www/html
  volumes:
    - .:/var/www/html
    - /var/www/html/vendor
  environment:
    - QUEUE_CONNECTION=redis
    - REDIS_HOST=redis
    - REDIS_PASSWORD=${REDIS_PASSWORD}
    - REDIS_PORT=6379
  command: php artisan queue:work redis --queue=ai-chat --tries=1 --timeout=460 --sleep=3
  depends_on:
    redis:
      condition: service_healthy
    mysql:
      condition: service_healthy
  networks:
    - galala_network
```

**No fixed `container_name`** to allow `docker compose up --scale worker=N`.

### 21.3 Password Requirements

- `REDIS_PASSWORD` environment variable must be non-empty
- Required in all Docker environments including local development
- Never committed to version control
- Added to `.env.example` as placeholder:
  ```env
  REDIS_PASSWORD=change_this_to_strong_random_password
  ```

### 21.4 Updated `app` Service Environment

Add to existing `app` service `environment:`:
```yaml
- QUEUE_CONNECTION=redis
- REDIS_HOST=redis
- REDIS_PASSWORD=${REDIS_PASSWORD}
- REDIS_PORT=6379
```

### 21.5 Volume Addition

```yaml
volumes:
  galala_mysql_data:
    driver: local
  galala_redis_data:
    driver: local
```

---

## 22. Exact Filament Permissions, Restore Flow, Hard-Delete Conditions, Preview Modal, and No-Bulk-Delete Rule

### 22.1 Access Control

**`ChatConversationPolicy`:**

```php
class ChatConversationPolicy
{
    public function viewAny(Admin $user): bool
    {
        return $user->isSuperAdmin() || $user->isSupportAdmin();
    }

    public function view(Admin $user, ChatConversation $conversation): bool
    {
        return $user->isSuperAdmin() || $user->isSupportAdmin();
    }

    // No create — conversations are created by students via API
    public function create(Admin $user): bool
    {
        return false;
    }

    // Update allowed for restore (clearing deleted_by_student_at)
    public function update(Admin $user, ChatConversation $conversation): bool
    {
        return $user->isSuperAdmin() || $user->isSupportAdmin();
    }

    // Single delete — with active-request guard
    public function delete(Admin $user, ChatConversation $conversation): bool
    {
        return $user->isSuperAdmin() || $user->isSupportAdmin();
    }

    // No bulk delete
    public function deleteAny(Admin $user): bool
    {
        return false;
    }
}
```

### 22.2 Conversation Table Columns

- Student name (relationship)
- Title
- Status
- Message count
- Last message at
- Hidden status (`deleted_by_student_at` shown as badge)
- Created at

### 22.3 Restore Flow

A Filament table action "Restore" appears when `deleted_by_student_at` is not null:

```php
Tables\Actions\Action::make('restore')
    ->label('Restore')
    ->icon('heroicon-o-arrow-uturn-left')
    ->visible(fn (ChatConversation $record) => $record->deleted_by_student_at !== null)
    ->requiresConfirmation()
    ->action(fn (ChatConversation $record) => $record->update(['deleted_by_student_at' => null]));
```

### 22.4 Hard-Delete Conditions

Delete action with active-request guard:

```php
Tables\Actions\Action::make('permanentDelete')
    ->label('Delete Permanently')
    ->icon('heroicon-o-trash')
    ->color('danger')
    ->requiresConfirmation()
    ->modalDescription('This will permanently delete the conversation, all messages, and all AI request records. This action cannot be undone.')
    ->action(function (ChatConversation $record) {
        // Re-check immediately before deletion
        $hasActiveRequests = $record->aiRequests()
            ->whereIn('status', ['queued', 'processing'])
            ->exists();

        if ($hasActiveRequests) {
            Notification::make()
                ->title('Cannot delete')
                ->body('This conversation has AI requests that are currently queued or processing.')
                ->danger()
                ->send();
            return;
        }

        $record->forceDelete(); // cascades to messages and AI requests via FK
    });
```

### 22.5 Message Preview Modal

Each message shows a 100-character preview in the messages relation manager or view page:

```php
Tables\Columns\TextColumn::make('content')
    ->limit(100)
    ->tooltip(fn (ChatMessage $record) => null)  // no tooltip
    ->action(
        Tables\Actions\Action::make('viewFull')
            ->modalContent(fn (ChatMessage $record) => view('filament.modals.message-content', ['message' => $record]))
            ->modalHeading('Full Message')
            ->modalWidth('lg')
    );
```

### 22.6 No Bulk Delete

```php
->bulkActions([
    // No bulk actions — AGENTS.md rule: no bulk hard deletion
]);
```

---

## 23. Exact StudentResource Deletion Pre-Check

### 23.1 Single Delete (Edit Page)

In `app/Filament/Resources/StudentResource/Pages/EditStudent.php`:

```php
protected function beforeDelete(): void
{
    if ($this->record->chatConversations()->exists()) {
        Notification::make()
            ->title('Deletion blocked')
            ->body('Cannot delete this student because saved chatbot conversations exist.')
            ->danger()
            ->persistent()
            ->send();

        $this->halt();
    }
}
```

### 23.2 Bulk Delete (List Page)

In `app/Filament/Resources/StudentResource.php`, replace the existing `DeleteBulkAction`:

```php
Tables\Actions\DeleteBulkAction::make()
    ->before(function (Collection $records, Tables\Actions\DeleteBulkAction $action) {
        $blocked = $records->filter(fn (Student $s) => $s->chatConversations()->exists());

        if ($blocked->isNotEmpty()) {
            Notification::make()
                ->title('Deletion blocked')
                ->body('Cannot delete ' . $blocked->count() . ' student(s) because saved chatbot conversations exist: ' . $blocked->pluck('student_id')->join(', '))
                ->danger()
                ->persistent()
                ->send();

            $action->cancel();
        }
    }),
```

---

## 24. Exact Test Plan Grouped by Phase

### Phase 1: Database & Models

| # | Test | File |
|---|---|---|
| 1 | `chat_conversations` migration creates table with correct schema | `StudentChatTest` |
| 2 | `chat_messages` migration creates table with correct schema | `StudentChatTest` |
| 3 | `chat_ai_requests` migration creates table with correct schema | `StudentChatTest` |
| 4 | `ChatConversation` → `student()` relationship works | `StudentChatTest` |
| 5 | `ChatMessage` → `aiRequestsAsUser()` returns HasMany | `StudentChatTest` |
| 6 | `ChatMessage` → `aiRequestsAsAssistant()` returns HasMany | `StudentChatTest` |
| 7 | `restrictOnDelete` on `chat_conversations.student_id` prevents student deletion | `StudentChatTest` |
| 8 | `cascadeOnDelete` on messages and AI requests cascades correctly | `StudentChatTest` |

### Phase 2: Student Chat API

| # | Test | File |
|---|---|---|
| 9 | `POST /chats` creates conversation, messages, AI request and returns 202 | `StudentChatTest` |
| 10 | `POST /chats` requires `client_message_id` (uuid validation) | `StudentChatTest` |
| 11 | `client_message_id` global uniqueness enforced (duplicate returns 422) | `StudentChatTest` |
| 12 | Title generated from first message with Unicode Arabic text (50 chars, no ellipsis) | `StudentChatTest` |
| 13 | `GET /chats` returns only visible conversations (not hidden) | `StudentChatTest` |
| 14 | `DELETE /chats/{uuid}` sets `deleted_by_student_at` | `StudentChatTest` |
| 15 | Hidden chat blocked from show, rename, send, poll, retry | `StudentChatTest` |
| 16 | Cannot send message while assistant response is pending (409) | `StudentChatTest` |
| 17 | Retry failed AI response creates new AI request with incremented attempt | `StudentChatTest` |
| 18 | Job dispatched to `ai-chat` queue (not default) | `StudentChatTest` |
| 19 | Job dispatched only after DB commit | `StudentChatTest` |

### Phase 3: Guest Chat API

| # | Test | File |
|---|---|---|
| 20 | `POST /guest/chat/messages` generates opaque token on first request | `GuestChatTest` |
| 21 | Guest token hashed with SHA-256 before Redis key use | `GuestChatTest` |
| 22 | Atomic pending lock acquired correctly | `GuestChatTest` |
| 23 | Second message while pending returns 409 | `GuestChatTest` |
| 24 | Pending lock cleared on setup failure | `GuestChatTest` |
| 25 | Guest history TTL is 86,400 seconds | `GuestChatTest` |
| 26 | Guest named throttle `guest-chat-submit` enforced | `GuestChatTest` |
| 27 | `InMemoryGuestChatStore` singleton works in tests | `GuestChatStoreTest` |

### Phase 4: AI Clients

| # | Test | File |
|---|---|---|
| 28 | `FakeStudentAiChatClient` returns deterministic response | `StudentChatTest` |
| 29 | `FakeGuestAiChatClient` returns deterministic response | `GuestChatTest` |
| 30 | `StudentPayloadBuilder` includes only allowlisted fields | `StudentPayloadBuilderTest` |
| 31 | `StudentPayloadBuilder` never includes password, token, admin data | `StudentPayloadBuilderTest` |

### Phase 5: Filament Admin

| # | Test | File |
|---|---|---|
| 32 | `super_admin` can view conversations | `ChatAdminTest` |
| 33 | `support_admin` can view conversations | `ChatAdminTest` |
| 34 | `vehicle_admin` cannot view conversations | `ChatAdminTest` |
| 35 | Restore hidden chat clears `deleted_by_student_at` | `ChatAdminTest` |
| 36 | Hard deletion blocked while AI request is queued | `ChatAdminTest` |
| 37 | Hard deletion blocked while AI request is processing | `ChatAdminTest` |
| 38 | Hard deletion cascades conversation + messages + AI requests | `ChatAdminTest` |
| 39 | No bulk delete action available | `ChatAdminTest` |
| 40 | StudentResource single delete blocked when conversations exist | `ChatAdminTest` |
| 41 | StudentResource bulk delete blocked when any student has conversations | `ChatAdminTest` |

### Phase 6: Docker & Infrastructure

| # | Test | File |
|---|---|---|
| 42 | Redis service has no published ports | Manual / CI docker-compose config check |
| 43 | Redis password is required (non-empty) | Manual / CI check |
| 44 | Worker service has no fixed `container_name` | Manual / CI docker-compose config check |
| 45 | Redis persistence enabled (`--appendonly yes`) | Manual / CI check |

---

## 25. Deployment Risks and Rollback Plan

### 25.1 Risks

| Risk | Severity | Mitigation |
|---|---|---|
| Queue driver change (`database` → `redis`) breaks existing jobs | High | Deploy during maintenance window. Drain existing database queue before switching. No existing chatbot jobs exist, but other jobs may. |
| Redis service unavailable causes queue failures | High | Redis healthcheck in Docker Compose. Worker `depends_on` Redis with health condition. |
| `phpredis` extension missing in Dockerfile rebuild | Medium | Test Docker build in CI before deploy. |
| `restrictOnDelete` on `chat_conversations.student_id` blocks student deletion | Medium | Expected behavior. Communicate to admins via Filament notification. |
| AI team API unreachable causes all jobs to fail | Medium | `failed()` method stores error status. Frontend shows "AI unavailable" via polling. |
| Redis memory exhaustion from guest state | Low | TTL of 86,400s ensures automatic cleanup. Monitor Redis memory in production. |

### 25.2 Rollback Plan

1. **Database**: Run `php artisan migrate:rollback --step=3` to drop `chat_ai_requests`, `chat_messages`, `chat_conversations`.
2. **Queue driver**: Revert `QUEUE_CONNECTION=database` in `.env` and Docker environment.
3. **Docker**: Remove `redis` and `worker` services from `docker-compose.yml`, restart.
4. **Code**: Revert git to pre-chatbot commit. Chatbot files are additive (new models, controllers, routes) so removal is clean.
5. **Filament**: `ChatConversationResource` is auto-discovered; removing the file removes the navigation item.
6. **StudentResource**: Revert pre-check changes; student deletion returns to original behavior.

---

## 26. Remaining Genuine Uncertainties

> [!IMPORTANT]
> These are items that genuinely require your input before Phase 1 begins.

| # | Question | Impact |
|---|---|---|
| 1 | **Student AI API URL and token env var names.** What are the exact environment variable names and placeholder URLs the AI team expects? Proposed: `STUDENT_AI_CHAT_URL`, `STUDENT_AI_CHAT_TOKEN`. | Affects `config/chat.php` and `HttpStudentAiChatClient` |
| 2 | **Guest AI API URL and token env var names.** Same question for guest. Proposed: `GUEST_AI_CHAT_URL`, `GUEST_AI_CHAT_TOKEN`. | Affects `config/chat.php` and `HttpGuestAiChatClient` |
| 3 | **AI API response format.** What exact JSON structure does the AI team return? Assumed: `{"content": "..."}`. | Affects both HTTP client implementations |
| 4 | **AI API error response format.** What status codes and error body does the AI team return on failure? | Affects error_code/error_message storage |
| 5 | **Guest token transmission.** Use `X-Guest-Token` header or `Authorization: Bearer` header? Proposed: `X-Guest-Token` header to avoid Sanctum conflict. | Affects `GuestChatController` and `SendGuestMessageRequest` |
| 6 | **Should `QUEUE_CONNECTION` change globally to `redis` or should only chatbot jobs use Redis?** Currently `database` is used for queue. Changing globally affects the existing `composer dev` script which runs `queue:listen`. | Affects `docker-compose.yml`, `.env`, and existing job dispatch |
| 7 | **Phase execution order.** Should all phases be implemented in a single PR or broken into multiple PRs (one per phase)? | Affects branching strategy |

---

## Corrected Assumptions from Preliminary Plan

| # | Previous Assumption | Correction | Source |
|---|---|---|---|
| 1 | ChatMessage uses `HasOne` for AI request relationship | **Must use `HasMany`** — retries create multiple AI request attempts per message pair | Freeze addendum §Eloquent Relationships |
| 2 | `client_message_id` is optional in the database | **Nullable in DB but required by API** — `client_message_id` is nullable in the database column but the authenticated student API validation requires it as `['required', 'uuid']` | Freeze addendum §chat_messages |
| 3 | Guest store uses `REDIS_CLIENT=array` for testing | **Must use `InMemoryGuestChatStore`** — a dedicated in-memory implementation, not phpredis array driver | Freeze addendum §Guest Storage Abstraction |
| 4 | AI clients could share a common base class | **Separate contracts, separate implementations** — `StudentAiChatClientContract` and `GuestAiChatClientContract` are fully independent with separate URLs, tokens, payload builders, HTTP clients, and fake clients | AGENTS.md §Separate AI APIs |
| 5 | Worker service can have a fixed `container_name` | **No fixed `container_name`** — must allow `--scale worker=N` for horizontal scaling | AGENTS.md §Redis Docker Rules |
| 6 | Redis ports can be published for local dev debugging | **No published ports** — Redis must be internal-only even in local development | AGENTS.md §Redis Docker Rules |
| 7 | Cascade delete on `chat_conversations.student_id` | **Must use `restrictOnDelete()`** — student deletion is blocked when conversations exist | AGENTS.md §Student Deletion Protection |
| 8 | Admin dashboard available to all admin roles | **Only `super_admin` and `support_admin`** — `vehicle_admin` and `academic_admin` cannot access chatbot conversations | AGENTS.md §Admin Dashboard |
| 9 | Bulk hard deletion available for admin convenience | **Explicitly forbidden** — only single conversation permanent deletion allowed | AGENTS.md §Admin Dashboard |
| 10 | Deletion audit log tracked in a separate table | **Not in V1** — explicitly listed as postponed feature | AGENTS.md §Admin Dashboard, §Postponed Features |

---

## Remaining Genuine Uncertainties

See §26 above for the full list of 7 items requiring approval.

---

> **Phase 0 is frozen and ready for Phase 1.**
