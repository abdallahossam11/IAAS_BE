<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\VehicleRequestResource\Pages\ListVehicleRequests;
use App\Models\Admin;
use App\Models\Student;
use App\Models\VehicleRequest;
use Carbon\Carbon;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Tests for the VehicleRequestResource Filament page.
 *
 * Phase J: confirms the date order guard in the approve action.
 */
class VehicleRequestResourceTest extends TestCase
{
    use RefreshDatabase;

    private Admin $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->admin = Admin::factory()->create(['role' => 'vehicle_admin']);
        $this->actingAs($this->admin, 'web');
    }

    // =========================================================================
    // Approve action — date order validation
    // =========================================================================

    public function test_approve_action_succeeds_with_valid_date_range(): void
    {
        $student = Student::factory()->create();
        $request = VehicleRequest::factory()->pending()->create([
            'student_id' => $student->id,
        ]);

        Livewire::test(ListVehicleRequests::class)
            ->callTableAction('approve', $request, data: [
                'semester_start_date' => '2026-09-01',
                'semester_end_date' => '2027-01-31',
            ])
            ->assertHasNoTableActionErrors();

        $this->assertDatabaseHas('vehicle_requests', [
            'id' => $request->id,
            'status' => 'approved',
        ]);
    }

    public function test_approve_action_blocked_when_end_date_before_start_date(): void
    {
        $student = Student::factory()->create();
        $request = VehicleRequest::factory()->pending()->create([
            'student_id' => $student->id,
        ]);

        // semester_end_date < semester_start_date — must be rejected
        Livewire::test(ListVehicleRequests::class)
            ->callTableAction('approve', $request, data: [
                'semester_start_date' => '2027-01-31',
                'semester_end_date' => '2026-09-01',
            ]);

        // Record must remain pending (not approved)
        $this->assertDatabaseHas('vehicle_requests', [
            'id' => $request->id,
            'status' => 'pending',
        ]);
    }

    public function test_approve_action_blocked_when_end_date_equals_start_date(): void
    {
        $student = Student::factory()->create();
        $request = VehicleRequest::factory()->pending()->create([
            'student_id' => $student->id,
        ]);

        Livewire::test(ListVehicleRequests::class)
            ->callTableAction('approve', $request, data: [
                'semester_start_date' => '2026-09-01',
                'semester_end_date' => '2026-09-01',
            ]);

        $this->assertDatabaseHas('vehicle_requests', [
            'id' => $request->id,
            'status' => 'pending',
        ]);
    }

    // =========================================================================
    // Reject action
    // =========================================================================

    public function test_reject_action_sets_rejected_status(): void
    {
        $student = Student::factory()->create();
        $request = VehicleRequest::factory()->pending()->create([
            'student_id' => $student->id,
        ]);

        Livewire::test(ListVehicleRequests::class)
            ->callTableAction('reject', $request, data: [
                'rejection_reason' => 'Insufficient documentation',
            ])
            ->assertHasNoTableActionErrors();

        $this->assertDatabaseHas('vehicle_requests', [
            'id' => $request->id,
            'status' => 'rejected',
            'rejection_reason' => 'Insufficient documentation',
        ]);
    }

    public function test_approve_action_is_hidden_for_already_approved_request(): void
    {
        $student = Student::factory()->create();
        $request = VehicleRequest::factory()->approved()->create([
            'student_id' => $student->id,
            'semester_end_date' => Carbon::today()->subDay(),
        ]);

        // The approve action uses ->visible(fn => $record->status === 'pending'),
        // so it must be hidden for an already-approved record.
        Livewire::test(ListVehicleRequests::class)
            ->assertTableActionHidden('approve', $request);
    }
}
