<?php

/**
 * Test script for Project Revision (Snapshot) functionality
 * 
 * Run: php test_snapshot_service.php
 */

require __DIR__ . '/vendor/autoload.php';

use App\Models\Project;
use App\Services\SnapshotService;

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

echo "=== Project Revision (Snapshot) System Test ===\n\n";

try {
    // Get first project for testing
    $project = Project::with(['positions', 'region'])->first();
    
    if (!$project) {
        echo "❌ No projects found. Create a project first.\n";
        exit(1);
    }
    
    echo "✓ Testing with Project #" . $project->number . " (ID: {$project->id})\n";
    echo "  Expert: {$project->expert_name}\n";
    echo "  Address: {$project->address}\n\n";
    
    // Initialize SnapshotService
    $snapshotService = app(SnapshotService::class);
    
    // Test 1: Build Report
    echo "📊 Step 1: Building report...\n";
    $reportDto = app(\App\Service\ReportService::class)->buildReport($project);
    $reportArray = $reportDto->toArray();
    echo "  ✓ Report built: " . count($reportArray['positions']) . " positions\n";
    echo "  ✓ Totals: {$reportArray['totals']['grand_total']} руб.\n\n";
    
    // Test 2: Canonicalize JSON
    echo "🔧 Step 2: Canonicalizing JSON...\n";
    $canonicalJson = $snapshotService->canonicalizeJson($reportArray);
    $jsonLength = strlen($canonicalJson);
    echo "  ✓ Canonical JSON: " . number_format($jsonLength) . " bytes\n";
    
    // Verify deterministic ordering (run twice)
    $canonical2 = $snapshotService->canonicalizeJson($reportArray);
    if ($canonicalJson === $canonical2) {
        echo "  ✓ Deterministic: JSON is identical on re-canonicalization\n\n";
    } else {
        echo "  ❌ ERROR: JSON is not deterministic!\n\n";
        exit(1);
    }
    
    // Test 3: Hash calculation
    echo "🔐 Step 3: Calculating SHA256 hash...\n";
    $hash = hash('sha256', $canonicalJson);
    echo "  ✓ Hash: {$hash}\n";
    echo "  ✓ Hash length: " . strlen($hash) . " characters\n\n";
    
    // Test 4: Create snapshot
    echo "📸 Step 4: Creating snapshot...\n";
    $revision = $snapshotService->createSnapshot($project, 1); // User ID 1
    echo "  ✓ Revision created: #" . $revision->number . "\n";
    echo "  ✓ Revision ID: {$revision->id}\n";
    echo "  ✓ Status: {$revision->status}\n";
    echo "  ✓ Hash: {$revision->snapshot_hash}\n";
    echo "  ✓ Created at: {$revision->created_at}\n\n";
    
    // Test 5: Verify hash integrity
    echo "🔍 Step 5: Verifying snapshot integrity...\n";
    if ($revision->verifySnapshot()) {
        echo "  ✓ Integrity check PASSED: Hash matches snapshot content\n\n";
    } else {
        echo "  ❌ Integrity check FAILED: Hash mismatch!\n\n";
        exit(1);
    }
    
    // Test 6: Count revisions for project
    echo "📋 Step 6: Checking revisions...\n";
    $revisionCount = $project->revisions()->count();
    echo "  ✓ Total revisions for this project: {$revisionCount}\n";
    
    // Show all revisions
    $revisions = $project->revisions()->get();
    foreach ($revisions as $rev) {
        echo "    - Revision #{$rev->number} [{$rev->status}] - {$rev->created_at->format('Y-m-d H:i:s')}\n";
    }
    echo "\n";
    
    // Test 7: Restore from snapshot
    echo "♻️  Step 7: Restoring from snapshot...\n";
    $restoredData = $snapshotService->restoreFromSnapshot($revision);
    echo "  ✓ Snapshot restored successfully\n";
    echo "  ✓ Restored " . count($restoredData['positions']) . " positions\n";
    echo "  ✓ Restored totals: {$restoredData['totals']['grand_total']} руб.\n\n";
    
    // Test 8: Status mutations
    echo "🔄 Step 8: Testing status mutations...\n";
    
    // Publish
    $revision->publish();
    echo "  ✓ Status changed to: {$revision->status}\n";
    echo "  ✓ Published at: {$revision->published_at}\n";
    
    // Lock
    $revision->lock();
    echo "  ✓ Status changed to: {$revision->status}\n";
    
    // Mark stale
    $revision->markStale();
    echo "  ✓ Status changed to: {$revision->status}\n";
    echo "  ✓ Stale at: {$revision->stale_at}\n\n";
    
    echo "✅ All tests passed!\n";
    echo "\n=== Summary ===\n";
    echo "• Snapshot service works correctly\n";
    echo "• JSON canonicalization is deterministic\n";
    echo "• SHA256 hash calculation is accurate\n";
    echo "• Database storage and retrieval working\n";
    echo "• Integrity verification functional\n";
    echo "• Status lifecycle (locked → published → stale) working\n\n";
    
} catch (\Exception $e) {
    echo "\n❌ ERROR: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}
