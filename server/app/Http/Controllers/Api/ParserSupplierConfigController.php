<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ParserSupplierConfig;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;

class ParserSupplierConfigController extends Controller
{
    public function index()
    {
        $configsDir = base_path('parser/configs');
        $suppliers = [];

        if (File::exists($configsDir)) {
            $files = File::files($configsDir);
            foreach ($files as $file) {
                if ($file->getExtension() !== 'json') {
                    continue;
                }

                $supplier = $file->getFilenameWithoutExtension();
                $config = $this->readConfigFromFile($supplier);

                $suppliers[] = [
                    'supplier' => $supplier,
                    'display_name' => $config['display_name'] ?? $supplier,
                    'enabled' => (bool) ($config['enabled'] ?? true),
                    'has_db_config' => ParserSupplierConfig::where('supplier_name', $supplier)->exists(),
                ];
            }
        }

        return response()->json([
            'suppliers' => $suppliers,
        ]);
    }

    public function show(string $supplier)
    {
        $record = ParserSupplierConfig::where('supplier_name', $supplier)->first();

        if ($record) {
            return response()->json([
                'supplier' => $supplier,
                'config' => $record->config,
                'source' => 'db',
            ]);
        }

        $config = $this->readConfigFromFile($supplier);

        // Seed DB for future edits
        ParserSupplierConfig::updateOrCreate(
            ['supplier_name' => $supplier],
            ['config' => $config]
        );

        return response()->json([
            'supplier' => $supplier,
            'config' => $config,
            'source' => 'file',
        ]);
    }

    public function update(string $supplier, Request $request)
    {
        $validated = $request->validate([
            'config' => 'required|array',
        ]);

        $incoming = $validated['config'];
        $existing = null;

        try {
            $existing = $this->readConfigFromFile($supplier);
        } catch (\Throwable $e) {
            // Ignore file read errors; DB will be source of truth after update
        }

        $config = $existing ? array_replace_recursive($existing, $incoming) : $incoming;
        $config['name'] = $supplier;

        if (!isset($config['display_name'])) {
            $config['display_name'] = $existing['display_name'] ?? $supplier;
        }

        ParserSupplierConfig::updateOrCreate(
            ['supplier_name' => $supplier],
            ['config' => $config]
        );

        $this->writeConfigToFile($supplier, $config);

        return response()->json([
            'supplier' => $supplier,
            'config' => $config,
            'source' => 'db',
        ]);
    }

    private function readConfigFromFile(string $supplier): array
    {
        $path = base_path("parser/configs/{$supplier}.json");

        if (!File::exists($path)) {
            abort(404, 'Supplier config not found');
        }

        $raw = File::get($path);
        $config = json_decode($raw, true);

        if (!is_array($config)) {
            abort(500, 'Invalid supplier config JSON');
        }

        return $config;
    }

    private function writeConfigToFile(string $supplier, array $config): void
    {
        $path = base_path("parser/configs/{$supplier}.json");
        $dir = dirname($path);
        if (!File::exists($dir)) {
            File::makeDirectory($dir, 0755, true);
        }

        $payload = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        File::put($path, $payload . PHP_EOL);
    }
}
