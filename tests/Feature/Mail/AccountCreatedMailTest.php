<?php

namespace Tests\Feature\Mail;

use App\Filament\Resources\AdminResource\Pages\CreateAdmin;
use App\Filament\Resources\StudentResource\Pages\CreateStudent;
use App\Mail\AdminAccountCreatedMail;
use App\Mail\StudentAccountCreatedMail;
use App\Models\Admin;
use App\Models\Faculty;
use App\Models\Student;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

class AccountCreatedMailTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function superAdmin(): Admin
    {
        return Admin::factory()->superAdmin()->create();
    }

    // =========================================================================
    // Student account creation mails
    // =========================================================================

    public function test_creating_student_sends_account_created_email(): void
    {
        Mail::fake();

        $this->actingAs($this->superAdmin(), 'web');
        $faculty = Faculty::factory()->create();

        Livewire::test(CreateStudent::class)
            ->fillForm([
                'student_id' => 'GU-20241001',
                'full_name' => 'Test Student',
                'email' => 'student@example.com',
                'password' => 'SecureStudent1!',
                'faculty_id' => $faculty->id,
                'gpa' => 3.5,
                'credits_completed' => 60,
                'credits_required' => 140,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        Mail::assertSent(StudentAccountCreatedMail::class, function ($mail) {
            return $mail->hasTo('student@example.com');
        });
    }

    public function test_student_email_contains_student_id(): void
    {
        Mail::fake();

        $this->actingAs($this->superAdmin(), 'web');
        $faculty = Faculty::factory()->create();

        Livewire::test(CreateStudent::class)
            ->fillForm([
                'student_id' => 'GU-20241002',
                'full_name' => 'Another Student',
                'email' => 'another@example.com',
                'password' => 'SecureStudent1!',
                'faculty_id' => $faculty->id,
                'gpa' => 3.0,
                'credits_completed' => 0,
                'credits_required' => 140,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        Mail::assertSent(StudentAccountCreatedMail::class, function (StudentAccountCreatedMail $mail) {
            return $mail->student->student_id === 'GU-20241002'
                && $mail->student->email === 'another@example.com';
        });
    }

    public function test_student_email_does_not_expose_password_hash(): void
    {
        Mail::fake();

        $this->actingAs($this->superAdmin(), 'web');
        $faculty = Faculty::factory()->create();

        Livewire::test(CreateStudent::class)
            ->fillForm([
                'student_id' => 'GU-20241003',
                'full_name' => 'Hash Test Student',
                'email' => 'hashtest@example.com',
                'password' => 'SecureStudent1!',
                'faculty_id' => $faculty->id,
                'gpa' => 3.0,
                'credits_completed' => 0,
                'credits_required' => 140,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        Mail::assertSent(StudentAccountCreatedMail::class, function (StudentAccountCreatedMail $mail) {
            // plainPassword must be the plain text, not a bcrypt hash
            return ! str_starts_with($mail->plainPassword, '$2y$')
                && ! str_starts_with($mail->plainPassword, '$argon');
        });
    }

    // =========================================================================
    // Admin account creation mails
    // =========================================================================

    public function test_creating_admin_sends_account_created_email(): void
    {
        Mail::fake();

        $this->actingAs($this->superAdmin(), 'web');

        Livewire::test(CreateAdmin::class)
            ->fillForm([
                'name' => 'New Admin',
                'email' => 'newadmin@example.com',
                'password' => 'SecureAdmin1!',
                'role' => 'vehicle_admin',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        Mail::assertSent(AdminAccountCreatedMail::class, function ($mail) {
            return $mail->hasTo('newadmin@example.com');
        });
    }

    public function test_admin_email_contains_admin_email_and_role(): void
    {
        Mail::fake();

        $this->actingAs($this->superAdmin(), 'web');

        Livewire::test(CreateAdmin::class)
            ->fillForm([
                'name' => 'Another Admin',
                'email' => 'another@example.com',
                'password' => 'SecureAdmin1!',
                'role' => 'support_admin',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        Mail::assertSent(AdminAccountCreatedMail::class, function (AdminAccountCreatedMail $mail) {
            return $mail->admin->email === 'another@example.com'
                && $mail->admin->role === 'support_admin';
        });
    }

    public function test_admin_email_does_not_expose_password_hash(): void
    {
        Mail::fake();

        $this->actingAs($this->superAdmin(), 'web');

        Livewire::test(CreateAdmin::class)
            ->fillForm([
                'name' => 'Hash Test Admin',
                'email' => 'hashcheck@example.com',
                'password' => 'SecureAdmin1!',
                'role' => 'academic_admin',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        Mail::assertSent(AdminAccountCreatedMail::class, function (AdminAccountCreatedMail $mail) {
            return ! str_starts_with($mail->plainPassword, '$2y$')
                && ! str_starts_with($mail->plainPassword, '$argon');
        });
    }

    // =========================================================================
    // FIX 2 — Login URL uses frontend/admin domain, not APP_URL
    // =========================================================================

    public function test_student_email_login_url_uses_frontend_url(): void
    {
        $student = Student::factory()->create();
        $mail = new StudentAccountCreatedMail($student, 'PlainText-Pa55!');

        // Inspect the content() data directly — avoids triggering the envelope
        // From-address validation (which fails on .local test domains).
        $with = $mail->content()->with;
        $expectedBase = rtrim((string) config('app.frontend_url', config('app.url')), '/');

        $this->assertStringContainsString($expectedBase.'/login', $with['loginUrl']);
    }

    public function test_admin_email_login_url_uses_admin_url(): void
    {
        $admin = Admin::factory()->create();
        $mail = new AdminAccountCreatedMail($admin, 'PlainText-Pa55!');

        $with = $mail->content()->with;
        $expectedBase = rtrim((string) config('app.admin_url', config('app.url')), '/');

        $this->assertStringContainsString($expectedBase.'/admin/login', $with['loginUrl']);
    }

    // =========================================================================
    // FIX 5 — "Temporary Password" replaced with "Initial Password"
    // =========================================================================

    public function test_student_email_uses_initial_password_wording(): void
    {
        $student = Student::factory()->create();
        // Render the blade view directly, bypassing the Mailable envelope so
        // the .local test From address does not trigger RFC 2822 validation.
        $rendered = view('emails.student-account-created', [
            'student' => $student,
            'plainPassword' => 'PlainText-Pa55!',
            'loginUrl' => 'https://example.com/login',
        ])->render();

        $this->assertStringContainsString('Initial Password', $rendered);
        $this->assertStringNotContainsString('Temporary Password', $rendered);
    }

    public function test_admin_email_uses_initial_password_wording(): void
    {
        $admin = Admin::factory()->create();
        $rendered = view('emails.admin-account-created', [
            'admin' => $admin,
            'plainPassword' => 'PlainText-Pa55!',
            'loginUrl' => 'https://example.com/admin/login',
        ])->render();

        $this->assertStringContainsString('Initial Password', $rendered);
        $this->assertStringNotContainsString('Temporary Password', $rendered);
    }

    // =========================================================================
    // FIX 6 — Record still created even if email delivery fails
    // =========================================================================

    public function test_student_record_created_even_when_mail_fails(): void
    {
        Mail::shouldReceive('to->send')->andThrow(new \RuntimeException('SMTP error'));

        $this->actingAs($this->superAdmin(), 'web');
        $faculty = Faculty::factory()->create();

        Livewire::test(CreateStudent::class)
            ->fillForm([
                'student_id' => 'GU-20249001',
                'full_name' => 'Mail Fail Student',
                'email' => 'mailfail@example.com',
                'password' => 'SecureStudent1!',
                'faculty_id' => $faculty->id,
                'gpa' => 3.0,
                'credits_completed' => 0,
                'credits_required' => 140,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('students', ['email' => 'mailfail@example.com']);
    }

    public function test_admin_record_created_even_when_mail_fails(): void
    {
        Mail::shouldReceive('to->send')->andThrow(new \RuntimeException('SMTP error'));

        $this->actingAs($this->superAdmin(), 'web');

        Livewire::test(CreateAdmin::class)
            ->fillForm([
                'name' => 'Mail Fail Admin',
                'email' => 'mailfailadmin@example.com',
                'password' => 'SecureAdmin1!',
                'role' => 'support_admin',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('admins', ['email' => 'mailfailadmin@example.com']);
    }
}
