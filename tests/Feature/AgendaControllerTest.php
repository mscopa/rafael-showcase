<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\TopLevelGoal;
use App\Models\MidLevelGoal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * SHOWCASE: Quality Assurance & TDD
 *
 * @challenge Ensuring critical business paths (like goal-to-task linking and agenda loading) do not break during future updates, especially when using Inertia.js for the frontend.
 * @solution Wrote comprehensive Feature tests verifying HTTP status codes, correct Inertia view rendering with required props, and accurate database mapping.
 * @highlight Proves a commitment to delivering bug-free, maintainable software and a professional approach to Quality Assurance. Demonstrates secure ID handling (using public ULIDs in requests mapped to internal IDs).
 */
class AgendaControllerTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Verify that the Agenda page loads correctly and passes the required Inertia props to the Vue frontend.
     */
    public function test_agenda_page_is_accessible(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/agenda');

        $response->assertStatus(200);
        $response->assertInertia(
            fn($page) => $page
                ->component('Agenda/Index')
                ->has('columns')
                ->has('backlog')
                ->has('midLevelGoals')
        );
    }

    /**
     * Ensure the Agenda can handle specific date queries seamlessly.
     */
    public function test_agenda_page_with_start_date_is_accessible(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/agenda?start_date=2024-01-01');

        $response->assertStatus(200);
    }

    /**
     * Test the creation of a standalone task (not linked to any Mid-Level Goal).
     */
    public function test_can_create_loose_task(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/tasks', [
            'name' => 'Tarea Suelta de Prueba',
            'mid_level_goal_id' => null,
            'due_date' => '2024-03-03',
            'difficulty' => 3
        ]);

        $response->assertStatus(302); // Redirect back on success
        $this->assertDatabaseHas('tasks', [
            'name' => 'Tarea Suelta de Prueba',
            'mid_level_goal_id' => null,
            'user_id' => $user->id
        ]);
    }

    /**
     * Test the creation of a task linked to a specific goal, 
     * validating the translation from a public ULID/UUID to an internal database ID.
     */
    public function test_can_create_linked_task(): void
    {
        $user = User::factory()->create();

        // Setup related models
        $topGoal = TopLevelGoal::create([
            'user_id' => $user->id,
            'name' => 'Vision de Prueba',
            'public_id' => '01HQJ7Z123456789012345678A'
        ]);

        $goal = MidLevelGoal::create([
            'top_level_goal_id' => $topGoal->id,
            'name' => 'Meta de Prueba',
            'public_id' => '01HQJ7Z123456789012345678B'
        ]);

        // Attempt to create a task using the public_id
        $response = $this->actingAs($user)->post('/tasks', [
            'name' => 'Tarea Vinculada de Prueba',
            'mid_level_goal_id' => '01HQJ7Z123456789012345678B',
            'due_date' => '2024-03-03',
            'difficulty' => 3
        ]);

        $response->assertStatus(302);

        // Verify the system correctly resolved the internal ID
        $this->assertDatabaseHas('tasks', [
            'name' => 'Tarea Vinculada de Prueba',
            'mid_level_goal_id' => $goal->id,
            'user_id' => $user->id
        ]);
    }
}