<?php

namespace Tests\Feature;

use App\Models\Professor;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsPlatform;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase, BuildsPlatform;

    // -----------------------------------------------------------------
    // Registration
    // -----------------------------------------------------------------

    public function test_a_student_registers_and_receives_a_token(): void
    {
        $response = $this->postJson('/api/register', [
            'name'                  => 'Sara',
            'email'                 => 'sara@test.local',
            'password'              => 'password123',
            'password_confirmation' => 'password123',
            'role'                  => 'student',
        ]);

        $response->assertCreated()
            ->assertJsonPath('user.role', 'Student')
            ->assertJsonStructure(['message', 'user' => ['id', 'name', 'email', 'role'], 'token']);

        $this->assertDatabaseHas('users', ['email' => 'sara@test.local', 'role' => 'Student']);

        // The students row is what progress and submissions hang off.
        $this->assertNotNull(Student::where('user_id', $response->json('user.id'))->first());
    }

    public function test_a_professor_application_stores_the_cv_and_issues_no_token(): void
    {
        Storage::fake('local');

        $response = $this->postJson('/api/register', [
            'name'                  => 'Dr Khoury',
            'email'                 => 'khoury@test.local',
            'password'              => 'password123',
            'password_confirmation' => 'password123',
            'role'                  => 'professor',
            'qualification'         => 'PhD in Computer Science',
            'cv'                    => UploadedFile::fake()->create('cv.pdf', 200, 'application/pdf'),
        ]);

        $response->assertCreated()
            ->assertJsonPath('user.role', 'PendingProfessor')
            ->assertJsonPath('pending', true);

        // A pending professor cannot use the app, so they must not get a token.
        $this->assertArrayNotHasKey('token', $response->json());

        $professor = Professor::where('user_id', $response->json('user.id'))->first();

        $this->assertNotNull($professor);
        $this->assertFalse((bool) $professor->is_verified);

        // The cv_path was silently dropped once because it was missing from
        // $fillable, so assert the file is actually there.
        $this->assertNotNull($professor->cv_path);
        Storage::assertExists($professor->cv_path);
    }

    public function test_a_professor_application_without_a_cv_is_rejected(): void
    {
        $this->postJson('/api/register', [
            'name'                  => 'No CV',
            'email'                 => 'nocv@test.local',
            'password'              => 'password123',
            'password_confirmation' => 'password123',
            'role'                  => 'professor',
            'qualification'         => 'PhD',
        ])->assertStatus(422)->assertJsonValidationErrors('cv');

        $this->assertDatabaseMissing('users', ['email' => 'nocv@test.local']);
    }

    public function test_registration_rejects_a_duplicate_email_and_a_mismatched_password(): void
    {
        User::factory()->student()->create(['email' => 'taken@test.local']);

        $this->postJson('/api/register', [
            'name'                  => 'Copy',
            'email'                 => 'taken@test.local',
            'password'              => 'password123',
            'password_confirmation' => 'password123',
            'role'                  => 'student',
        ])->assertStatus(422)->assertJsonValidationErrors('email');

        $this->postJson('/api/register', [
            'name'                  => 'Typo',
            'email'                 => 'typo@test.local',
            'password'              => 'password123',
            'password_confirmation' => 'different123',
            'role'                  => 'student',
        ])->assertStatus(422)->assertJsonValidationErrors('password');
    }

    public function test_registration_cannot_be_used_to_self_assign_a_privileged_role(): void
    {
        // Only student and professor are accepted; anything else is refused
        // rather than quietly creating an admin.
        foreach (['Admin', 'admin', 'MaterialManager', 'Professor'] as $attempt) {
            $this->postJson('/api/register', [
                'name'                  => 'Sneaky',
                'email'                 => "sneaky-{$attempt}@test.local",
                'password'              => 'password123',
                'password_confirmation' => 'password123',
                'role'                  => $attempt,
            ])->assertStatus(422)->assertJsonValidationErrors('role');
        }

        $this->assertDatabaseMissing('users', ['role' => 'Admin']);
    }

    // -----------------------------------------------------------------
    // Login
    // -----------------------------------------------------------------

    public function test_a_student_logs_in(): void
    {
        $this->makeStudent(['email' => 'sara@test.local', 'password' => bcrypt('password123')]);

        $this->postJson('/api/login', [
            'email'    => 'sara@test.local',
            'password' => 'password123',
        ])->assertOk()
          ->assertJsonPath('user.role', 'Student')
          ->assertJsonStructure(['token']);
    }

    public function test_a_wrong_password_is_refused_without_revealing_which_field_was_wrong(): void
    {
        $this->makeStudent(['email' => 'sara@test.local', 'password' => bcrypt('password123')]);

        $this->postJson('/api/login', [
            'email'    => 'sara@test.local',
            'password' => 'wrong-password',
        ])->assertStatus(401)->assertJsonPath('message', 'Invalid credentials.');

        $this->postJson('/api/login', [
            'email'    => 'nobody@test.local',
            'password' => 'password123',
        ])->assertStatus(401)->assertJsonPath('message', 'Invalid credentials.');
    }

    public function test_a_pending_professor_cannot_log_in(): void
    {
        User::factory()->create([
            'email'    => 'pending@test.local',
            'password' => bcrypt('password123'),
            'role'     => 'PendingProfessor',
        ]);

        $this->postJson('/api/login', [
            'email'    => 'pending@test.local',
            'password' => 'password123',
        ])->assertStatus(403)
          ->assertJsonPath('pending', true)
          ->assertJsonMissingPath('token');
    }

    // -----------------------------------------------------------------
    // Session
    // -----------------------------------------------------------------

    public function test_me_returns_the_authenticated_user(): void
    {
        $student = $this->makeStudent(['email' => 'sara@test.local']);

        $this->actingAs($student, 'sanctum')
            ->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('user.email', 'sara@test.local')
            ->assertJsonPath('user.role', 'Student');
    }

    public function test_logout_revokes_only_the_token_that_was_used(): void
    {
        $student = $this->makeStudent(['password' => bcrypt('password123')]);

        $first  = $student->createToken('one')->plainTextToken;
        $second = $student->createToken('two')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$first}")
            ->postJson('/api/logout')
            ->assertOk();

        // The token row is gone, but the guard caches the user it already
        // resolved and the whole test shares one application instance. Without
        // this the next request is answered from that cache and the assertion
        // below passes for the wrong reason.
        $this->app['auth']->forgetGuards();

        $this->assertDatabaseMissing('personal_access_tokens', ['name' => 'one']);

        $this->withHeader('Authorization', "Bearer {$first}")
            ->getJson('/api/me')
            ->assertUnauthorized();

        $this->app['auth']->forgetGuards();

        // Logging out on one device must not sign the user out everywhere.
        $this->withHeader('Authorization', "Bearer {$second}")
            ->getJson('/api/me')
            ->assertOk();
    }

    // -----------------------------------------------------------------
    // Route protection
    // -----------------------------------------------------------------

    public function test_protected_routes_reject_an_anonymous_request(): void
    {
        foreach (['/api/me', '/api/roadmaps', '/api/todos', '/api/profile'] as $route) {
            $this->getJson($route)->assertUnauthorized();
        }
    }

    public function test_each_role_group_admits_only_its_own_role(): void
    {
        $cases = [
            // route                       => [role that may, roles that may not]
            '/api/admin/dashboard-stats'   => ['makeAdmin',   ['makeStudent', 'makeProfessor', 'makeManager']],
            '/api/ai-workflows'            => ['makeManager', ['makeStudent', 'makeProfessor', 'makeAdmin']],
            '/api/professor/stats'         => ['makeProfessor', ['makeStudent', 'makeManager', 'makeAdmin']],
        ];

        foreach ($cases as $route => [$allowed, $refused]) {
            $this->actingAs($this->{$allowed}(), 'sanctum')
                ->getJson($route)
                ->assertOk();

            foreach ($refused as $maker) {
                $this->actingAs($this->{$maker}(), 'sanctum')
                    ->getJson($route)
                    ->assertForbidden();
            }
        }
    }
}