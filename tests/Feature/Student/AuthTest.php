<?php

namespace Tests\Feature\Student;

use App\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_student_login_success(): void
    {
        $student = Student::factory()->create([
            'student_id' => '20230001',
            'password' => bcrypt('password123'),
        ]);

        $response = $this->postJson('/api/v1/student/login', [
            'student_id' => '20230001',
            'password' => 'password123',
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'student',
                    'token',
                ],
            ]);
    }

    public function test_student_login_failure(): void
    {
        $student = Student::factory()->create([
            'student_id' => '20230001',
            'password' => bcrypt('password123'),
        ]);

        $response = $this->postJson('/api/v1/student/login', [
            'student_id' => '20230001',
            'password' => 'wrongpassword',
        ]);

        $response->assertStatus(401)
            ->assertJson([
                'success' => false,
                'message' => 'Invalid student ID or password',
            ]);
    }

    public function test_protected_endpoint_without_token_returns_401(): void
    {
        $response = $this->getJson('/api/v1/student/profile');

        $response->assertStatus(401);
    }

    public function test_profile_returns_authenticated_student_data(): void
    {
        $student = Student::factory()->create([
            'student_id' => '20230001',
        ]);

        \Laravel\Sanctum\Sanctum::actingAs($student);
        $response = $this->getJson('/api/v1/student/profile');

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'student_id' => '20230001',
                ],
            ]);
    }
}
