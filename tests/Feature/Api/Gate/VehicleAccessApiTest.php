<?php

namespace Tests\Feature\Api\Gate;

use App\Models\Student;
use App\Models\VehicleRequest;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Regression tests for the Gate Vehicle Access API.
 *
 * Endpoint:
 *   POST /api/v1/gate/vehicle-access/check
 *
 * Auth: X-GATE-API-KEY header verified by EnsureGateApiKey middleware.
 *   config('services.gate.api_key') must match the header value (hash_equals).
 *
 * Plate normalisation (normalizePlate):
 *   1. Arabic/Persian digits → English digits.
 *   2. Strips spaces, dashes, underscores, slashes, backslashes, pipes, dots.
 *   3. Arabic letter variants: أإآ→ا  ى→ي  ة→ه.
 *   4. mb_strtolower.
 *
 * Gate access requires status='approved' AND
 *   semester_start_date <= today AND semester_end_date >= today.
 * Note: the student-facing state() endpoint only checks semester_end_date >= today
 * (ignores start_date) — the Gate is stricter than the student-side check.
 */
class VehicleAccessApiTest extends TestCase
{
    use RefreshDatabase;

    private const GATE_KEY = 'test-gate-api-key-for-phpunit';

    private const ENDPOINT = '/api/v1/gate/vehicle-access/check';

    protected function setUp(): void
    {
        parent::setUp();
        // Override the gate key so tests are self-contained and never read .env secrets
        config(['services.gate.api_key' => self::GATE_KEY]);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function gatePost(string $plate, string $key = self::GATE_KEY): TestResponse
    {
        return $this->withHeader('X-GATE-API-KEY', $key)
            ->postJson(self::ENDPOINT, ['OCR' => $plate]);
    }

    private function activeApprovedRequest(array $overrides = []): VehicleRequest
    {
        return VehicleRequest::factory()->approved()->create(array_merge([
            'semester_start_date' => Carbon::today()->subDays(5),
            'semester_end_date' => Carbon::today()->addMonths(3),
        ], $overrides));
    }

    // =========================================================================
    // 1. Authentication
    // =========================================================================

    public function test_missing_gate_key_is_rejected(): void
    {
        $this->postJson(self::ENDPOINT, ['OCR' => 'ABC-1234'])
            ->assertUnauthorized()
            ->assertJson(['success' => false]);
    }

    public function test_wrong_gate_key_is_rejected(): void
    {
        $this->gatePost('ABC-1234', 'wrong-key-entirely')
            ->assertUnauthorized()
            ->assertJson(['success' => false]);
    }

    public function test_correct_gate_key_is_accepted(): void
    {
        // No matching plate → denied, but 200 (not 401)
        $this->gatePost('UNKNOWN-0000')
            ->assertOk()
            ->assertJson(['success' => true, 'access' => 'denied']);
    }

    // =========================================================================
    // 2. OCR field validation
    // =========================================================================

    public function test_ocr_field_is_required(): void
    {
        $this->withHeader('X-GATE-API-KEY', self::GATE_KEY)
            ->postJson(self::ENDPOINT, [])
            ->assertUnprocessable();
    }

    // =========================================================================
    // 3. Active approved permit → allowed
    // =========================================================================

    public function test_active_approved_permit_allows_access(): void
    {
        $this->activeApprovedRequest(['plate_number' => 'ABC-1234']);

        $this->gatePost('ABC-1234')
            ->assertOk()
            ->assertJson(['success' => true, 'access' => 'allowed']);
    }

    public function test_allowed_response_includes_student_and_permit_data(): void
    {
        $student = Student::factory()->create([
            'student_id' => 'GU-20240001',
            'full_name' => 'Ahmed Hassan',
        ]);

        $this->activeApprovedRequest([
            'student_id' => $student->id,
            'plate_number' => 'ABC-1234',
        ]);

        $response = $this->gatePost('ABC-1234')->assertOk();

        $this->assertSame('GU-20240001', $response->json('data.student.student_id'));
        $this->assertSame('Ahmed Hassan', $response->json('data.student.full_name'));
        $this->assertNotNull($response->json('data.permit.valid_from'));
        $this->assertNotNull($response->json('data.permit.valid_until'));
    }

    // =========================================================================
    // 4. Pending plate → denied
    // =========================================================================

    public function test_pending_request_plate_is_denied(): void
    {
        VehicleRequest::factory()->pending()->create(['plate_number' => 'ABC-1234']);

        $this->gatePost('ABC-1234')
            ->assertOk()
            ->assertJson(['access' => 'denied']);
    }

    // =========================================================================
    // 5. Rejected plate → denied
    // =========================================================================

    public function test_rejected_request_plate_is_denied(): void
    {
        VehicleRequest::factory()->rejected()->create(['plate_number' => 'ABC-1234']);

        $this->gatePost('ABC-1234')
            ->assertOk()
            ->assertJson(['access' => 'denied']);
    }

    // =========================================================================
    // 6. Expired approved permit → denied
    //    (semester_end_date < today fails the WHERE clause)
    // =========================================================================

    public function test_expired_approved_permit_is_denied(): void
    {
        VehicleRequest::factory()->approved()->create([
            'plate_number' => 'ABC-1234',
            'semester_start_date' => Carbon::today()->subMonths(5),
            'semester_end_date' => Carbon::today()->subDay(), // expired yesterday
        ]);

        $this->gatePost('ABC-1234')
            ->assertOk()
            ->assertJson(['access' => 'denied']);
    }

    // =========================================================================
    // 7. Future-start approved permit → denied
    //    The Gate checks semester_start_date <= today (the student state() does not).
    // =========================================================================

    public function test_future_start_approved_permit_is_denied(): void
    {
        VehicleRequest::factory()->approved()->create([
            'plate_number' => 'ABC-1234',
            'semester_start_date' => Carbon::today()->addDays(10), // not yet started
            'semester_end_date' => Carbon::today()->addMonths(4),
        ]);

        $this->gatePost('ABC-1234')
            ->assertOk()
            ->assertJson(['access' => 'denied']);
    }

    // =========================================================================
    // 8. Unknown plate → denied
    // =========================================================================

    public function test_unknown_plate_is_denied(): void
    {
        $this->gatePost('UNKNOWN-9999')
            ->assertOk()
            ->assertJson(['access' => 'denied']);
    }

    // =========================================================================
    // 9. Plate normalisation
    //    All normalisation is symmetric: stored AND OCR are both normalised
    //    before comparison, so these tests verify that the current rules allow
    //    mixed-format inputs to match.
    // =========================================================================

    public function test_normalisation_is_case_insensitive(): void
    {
        $this->activeApprovedRequest(['plate_number' => 'ABC-1234']);

        // Lowercase OCR matches uppercase stored plate
        $this->gatePost('abc-1234')->assertOk()->assertJson(['access' => 'allowed']);

        // Mixed case OCR also matches
        $this->gatePost('Abc-1234')->assertOk()->assertJson(['access' => 'allowed']);
    }

    public function test_normalisation_strips_dashes(): void
    {
        // Stored: "ABC 1234" (space) — OCR: "ABC-1234" (dash)
        // After normalisation both become "abc1234"
        $this->activeApprovedRequest(['plate_number' => 'ABC 1234']);

        $this->gatePost('ABC-1234')->assertOk()->assertJson(['access' => 'allowed']);
    }

    public function test_normalisation_strips_spaces(): void
    {
        $this->activeApprovedRequest(['plate_number' => 'ABC-1234']);

        // OCR without separator, stored with dash → both normalize to 'abc1234'
        $this->gatePost('ABC1234')->assertOk()->assertJson(['access' => 'allowed']);
    }

    public function test_normalisation_converts_arabic_digits(): void
    {
        // Stored plate has English digits; OCR sends Arabic-Indic digits
        // ١٢٣٤ = 1234 after normalisation
        $this->activeApprovedRequest(['plate_number' => 'ABC-1234']);

        $this->gatePost('ABC-١٢٣٤')->assertOk()->assertJson(['access' => 'allowed']);
    }

    public function test_normalisation_converts_persian_digits(): void
    {
        // Persian digits ۱۲۳۴ = 1234
        $this->activeApprovedRequest(['plate_number' => 'ABC-1234']);

        $this->gatePost('ABC-۱۲۳۴')->assertOk()->assertJson(['access' => 'allowed']);
    }

    public function test_normalisation_collapses_arabic_alef_variants(): void
    {
        // أ, إ, آ all normalise to ا
        // Stored plate uses base ا; OCR sends variant أ
        $this->activeApprovedRequest(['plate_number' => 'ابج-1234']);

        $this->gatePost('أبج-1234')->assertOk()->assertJson(['access' => 'allowed']);
    }

    public function test_normalisation_collapses_alef_maqsura(): void
    {
        // ى normalises to ي
        $this->activeApprovedRequest(['plate_number' => 'طيب-1234']);

        $this->gatePost('طىب-1234')->assertOk()->assertJson(['access' => 'allowed']);
    }

    public function test_normalisation_collapses_teh_marbuta(): void
    {
        // ة normalises to ه
        $this->activeApprovedRequest(['plate_number' => 'جامعه-1']);

        $this->gatePost('جامعة-1')->assertOk()->assertJson(['access' => 'allowed']);
    }
}
