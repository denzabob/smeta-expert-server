<?php

namespace App\Console\Commands;

use App\Models\Project;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class TestExpensesApi extends Command
{
    protected $signature = 'test:expenses-api {projectId=4}';
    protected $description = 'Test expenses API endpoints';

    public function handle()
    {
        $projectId = $this->argument('projectId');
        $project = Project::find($projectId);

        if (!$project) {
            $this->error("Project $projectId not found");
            return 1;
        }

        $user = User::find($project->user_id);
        if (!$user) {
            $this->error("User {$project->user_id} not found");
            return 1;
        }

        $this->info("Testing expenses API for project: {$project->code}");

        // Get or create token
        $token = $user->tokens()->first();
        if (!$token) {
            $token = $user->createToken('test-token');
        }

        $baseUrl = 'http://localhost:8000';
        $headers = [
            'Authorization' => 'Bearer ' . $token->plainTextToken,
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ];

        try {
            // Test 1: Create expense
            $this->line('');
            $this->info('Test 1: API Create expense');
            
            $payload = [
                'name' => 'API Test Доставка',
                'amount' => 3500.50,
                'description' => 'Test description from API'
            ];

            $response = Http::withHeaders($headers)
                ->post("$baseUrl/api/projects/$projectId/expenses", $payload);

            if ($response->failed()) {
                $this->error("✗ Create failed: " . $response->status());
                $this->error($response->body());
                return 1;
            }

            $expense = $response->json();
            $expenseId = $expense['id'];

            $this->line("✓ Expense created via API: ID $expenseId");
            $this->line("  - Name: {$expense['name']}");
            $this->line("  - Amount: {$expense['amount']}");

            // Test 2: Update expense
            $this->line('');
            $this->info('Test 2: API Update expense');

            $updatePayload = [
                'name' => 'API Test Доставка + консультация',
                'amount' => 4500.75,
                'description' => 'Updated via API'
            ];

            $response = Http::withHeaders($headers)
                ->put("$baseUrl/api/projects/$projectId/expenses/$expenseId", $updatePayload);

            if ($response->failed()) {
                $this->error("✗ Update failed: " . $response->status());
                $this->error($response->body());
                return 1;
            }

            $updated = $response->json();
            $this->line("✓ Expense updated via API");
            $this->line("  - Name: {$updated['name']}");
            $this->line("  - Amount: {$updated['amount']}");
            $this->line("  - Description: {$updated['description']}");

            // Test 3: Delete expense
            $this->line('');
            $this->info('Test 3: API Delete expense');

            $response = Http::withHeaders($headers)
                ->delete("$baseUrl/api/projects/$projectId/expenses/$expenseId");

            if ($response->failed() && $response->status() !== 204) {
                $this->error("✗ Delete failed: " . $response->status());
                return 1;
            }

            $this->line("✓ Expense deleted via API: ID $expenseId");

        } catch (\Exception $e) {
            $this->error("✗ Error: " . $e->getMessage());
            return 1;
        }

        $this->info('All API tests passed!');
        return 0;
    }
}
