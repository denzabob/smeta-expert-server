<?php
// app/Http/Controllers/Api/ProjectController.php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\UserSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ProjectController extends Controller
{
    public function index()
    {
        $projects = Project::where('user_id', Auth::id())
            ->whereNull('archived_at')
            ->withCount(['revisions', 'positions'])
            ->with(['latestRevision' => function ($query) {
                $query->select([
                    'project_revisions.id',
                    'project_revisions.project_id',
                    'project_revisions.number',
                    'project_revisions.status',
                    'project_revisions.created_at',
                ]);
            }])
            ->get();

        $projects->each(function ($project) {
            $project->latest_revision_number = $project->latestRevision?->number;
            $project->latest_revision_status = $project->latestRevision?->status;
            $project->latest_revision_at = $project->latestRevision?->created_at;
            $project->makeHidden('latestRevision');
        });

        return $projects;
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'number' => 'nullable|string|max:255',
            'expert_name' => 'nullable|string|max:255',
            'address' => 'nullable|string|max:255',
            'region_id' => 'nullable|exists:regions,id',
            'waste_coefficient' => 'nullable|numeric|min:0',
            'repair_coefficient' => 'nullable|numeric|min:0',
            'waste_plate_coefficient' => 'nullable|numeric|min:0',
            'waste_edge_coefficient' => 'nullable|numeric|min:0',
            'waste_operations_coefficient' => 'nullable|numeric|min:0',
            'apply_waste_to_plate' => 'nullable|boolean',
            'apply_waste_to_edge' => 'nullable|boolean',
            'apply_waste_to_operations' => 'nullable|boolean',
            'use_area_calc_mode' => 'nullable|boolean',
            'default_plate_material_id' => 'nullable|exists:materials,id',
            'default_edge_material_id' => 'nullable|exists:materials,id',
            'text_blocks' => 'nullable|array',
            'text_blocks.*.title' => 'nullable|string|max:255',
            'text_blocks.*.text' => 'nullable|string|max:10000',
            'text_blocks.*.enabled' => 'nullable|boolean',
            'waste_plate_description' => 'nullable|array',
            'waste_plate_description.title' => 'nullable|string|max:255',
            'waste_plate_description.text' => 'nullable|string|max:3000',
            'show_waste_plate_description' => 'nullable|boolean',
            'waste_edge_description' => 'nullable|array',
            'waste_edge_description.title' => 'nullable|string|max:255',
            'waste_edge_description.text' => 'nullable|string|max:3000',
            'show_waste_edge_description' => 'nullable|boolean',
            'waste_operations_description' => 'nullable|array',
            'waste_operations_description.title' => 'nullable|string|max:255',
            'waste_operations_description.text' => 'nullable|string|max:3000',
            'show_waste_operations_description' => 'nullable|boolean',
            'normohour_rate' => 'nullable|numeric|min:0',
            'normohour_region' => 'nullable|string|max:255',
            'normohour_date' => 'nullable|date',
            'normohour_method' => 'nullable|in:market_vacancies,commercial_proposals,contractor_estimate,other',
            'normohour_justification' => 'nullable|string|max:5000',
            'price_confirmation_freshness_days' => 'nullable|integer|min:1|max:365',
        ], $this->projectValidationMessages());

        // Получить пользовательские настройки (или создать если нет)
        $userSettings = Auth::user()->settings()->firstOrCreate(['user_id' => Auth::id()]);

        // Применить дефолты из user_settings если они не переданы явно
        $defaults = [
            'number' => $request->input('number') ?? $userSettings->default_number ?? 'Новый проект',
            'expert_name' => $request->input('expert_name') ?? $userSettings->default_expert_name ?? '',
            'address' => $request->input('address') ?? '',
            'region_id' => $userSettings->region_id,
            'waste_coefficient' => $request->input('waste_coefficient') ?? $userSettings->waste_coefficient ?? 1.0,
            'repair_coefficient' => $request->input('repair_coefficient') ?? $userSettings->repair_coefficient ?? 1.0,
            'waste_plate_coefficient' => $request->input('waste_plate_coefficient') ?? $userSettings->waste_plate_coefficient,
            'waste_edge_coefficient' => $request->input('waste_edge_coefficient') ?? $userSettings->waste_edge_coefficient,
            'waste_operations_coefficient' => $request->input('waste_operations_coefficient') ?? $userSettings->waste_operations_coefficient,
            'apply_waste_to_plate' => $request->input('apply_waste_to_plate') ?? $userSettings->apply_waste_to_plate ?? true,
            'apply_waste_to_edge' => $request->input('apply_waste_to_edge') ?? $userSettings->apply_waste_to_edge ?? true,
            'apply_waste_to_operations' => $request->input('apply_waste_to_operations') ?? $userSettings->apply_waste_to_operations ?? false,
            'use_area_calc_mode' => $request->input('use_area_calc_mode') ?? $userSettings->use_area_calc_mode ?? false,
            'default_plate_material_id' => $request->input('default_plate_material_id') ?? $userSettings->default_plate_material_id,
            'default_edge_material_id' => $request->input('default_edge_material_id') ?? $userSettings->default_edge_material_id,
            'text_blocks' => $request->input('text_blocks') ?? $userSettings->text_blocks,
            'waste_plate_description' => $request->input('waste_plate_description') ?? $userSettings->waste_plate_description,
            'waste_edge_description' => $request->input('waste_edge_description') ?? $userSettings->waste_edge_description,
            'waste_operations_description' => $request->input('waste_operations_description') ?? $userSettings->waste_operations_description,
            'show_waste_plate_description' => $request->input('show_waste_plate_description') ?? $userSettings->show_waste_plate_description ?? false,
            'show_waste_edge_description' => $request->input('show_waste_edge_description') ?? $userSettings->show_waste_edge_description ?? false,
            'show_waste_operations_description' => $request->input('show_waste_operations_description') ?? $userSettings->show_waste_operations_description ?? false,
        ];

        // Объединить валидированные и дефолтные данные
        $validated = array_merge($defaults, array_filter($validated, fn($value) => $value !== null));
        $validated['user_id'] = Auth::id();
        
        $project = Project::create($validated);
        return response()->json($project, 201);
    }

    public function show(Project $project)
    {
        $this->authorize('view', $project);
        abort_if($project->archived_at !== null, 404);
        $project->load('positions', 'fittings', 'expenses', 'profileRates');
        
        // Manually add profileRates to ensure they're included with correct key
        $data = $project->toArray();
        $data['profileRates'] = $project->profileRates->map(function($rate) {
            return $rate->toArray();
        })->values()->all();
        
        \Log::debug('API show() method', [
            'project_id' => $project->id,
            'profile_rates_count' => count($data['profileRates']),
            'first_rate' => $data['profileRates'][0] ?? null
        ]);
        
        return response()->json($data);
    }


    public function update(Request $request, Project $project)
    {
        $this->authorize('update', $project);
        abort_if($project->archived_at !== null, 404);

        $validated = $request->validate([
            'number' => 'sometimes|required|string|max:255',
            'expert_name' => 'sometimes|required|string|max:255',
            'address' => 'sometimes|required|string|max:255',
            'waste_coefficient' => 'sometimes|required|numeric|min:1',
            'repair_coefficient' => 'sometimes|required|numeric|min:1',
            'waste_plate_coefficient' => 'nullable|numeric|min:1',
            'waste_edge_coefficient' => 'nullable|numeric|min:1',
            'waste_operations_coefficient' => 'nullable|numeric|min:1',
            'apply_waste_to_plate' => 'sometimes|required|boolean',
            'apply_waste_to_edge' => 'sometimes|required|boolean',
            'apply_waste_to_operations' => 'sometimes|required|boolean',
            'use_area_calc_mode' => 'sometimes|required|boolean',
            'default_plate_material_id' => 'nullable|exists:materials,id',
            'default_edge_material_id' => 'nullable|exists:materials,id',
            'text_blocks' => 'nullable|array',
            'text_blocks.*.title' => 'nullable|string|max:255',
            'text_blocks.*.text' => 'nullable|string|max:10000',
            'text_blocks.*.enabled' => 'nullable|boolean',
            'waste_plate_description' => 'nullable|array',
            'waste_plate_description.title' => 'nullable|string|max:255',
            'waste_plate_description.text' => 'nullable|string|max:3000',
            'show_waste_plate_description' => 'nullable|boolean',
            'waste_edge_description' => 'nullable|array',
            'waste_edge_description.title' => 'nullable|string|max:255',
            'waste_edge_description.text' => 'nullable|string|max:3000',
            'show_waste_edge_description' => 'nullable|boolean',
            'waste_operations_description' => 'nullable|array',
            'waste_operations_description.title' => 'nullable|string|max:255',
            'waste_operations_description.text' => 'nullable|string|max:3000',
            'show_waste_operations_description' => 'nullable|boolean',
            'normohour_rate' => 'nullable|numeric|min:0',
            'normohour_region' => 'nullable|string|max:255',
            'normohour_date' => 'nullable|date',
            'normohour_method' => 'nullable|in:market_vacancies,commercial_proposals,contractor_estimate,other',
            'normohour_justification' => 'nullable|string|max:5000',
            'price_confirmation_freshness_days' => 'nullable|integer|min:1|max:365',
        ], $this->projectValidationMessages());

        $project->update($validated);
        return $project;
    }

    private function projectValidationMessages(): array
    {
        return [
            'number.required' => 'Укажите номер дела.',
            'number.max' => 'Номер дела не должен превышать 255 символов.',

            'expert_name.required' => 'Укажите ФИО эксперта.',
            'expert_name.max' => 'ФИО эксперта не должно превышать 255 символов.',

            'address.required' => 'Укажите адрес объекта.',
            'address.max' => 'Адрес объекта не должен превышать 255 символов.',

            'region_id.exists' => 'Выбранный регион не найден.',

            'waste_coefficient.required' => 'Укажите коэффициент обрезков.',
            'waste_coefficient.numeric' => 'Коэффициент обрезков должен быть числом.',
            'waste_coefficient.min' => 'Коэффициент обрезков не может быть меньше 1.',

            'repair_coefficient.required' => 'Укажите ремонтный коэффициент.',
            'repair_coefficient.numeric' => 'Ремонтный коэффициент должен быть числом.',
            'repair_coefficient.min' => 'Ремонтный коэффициент не может быть меньше 1.',

            'waste_plate_coefficient.numeric' => 'Коэффициент для плитных материалов должен быть числом.',
            'waste_plate_coefficient.min' => 'Коэффициент для плитных материалов не может быть меньше 1.',

            'waste_edge_coefficient.numeric' => 'Коэффициент для кромки должен быть числом.',
            'waste_edge_coefficient.min' => 'Коэффициент для кромки не может быть меньше 1.',

            'waste_operations_coefficient.numeric' => 'Коэффициент для операций должен быть числом.',
            'waste_operations_coefficient.min' => 'Коэффициент для операций не может быть меньше 1.',

            'default_plate_material_id.exists' => 'Выбранный плитный материал не найден.',
            'default_edge_material_id.exists' => 'Выбранный кромочный материал не найден.',

            'text_blocks.array' => 'Справочные блоки переданы в неверном формате.',
            'text_blocks.*.title.max' => 'Заголовок справочного блока не должен превышать 255 символов.',
            'text_blocks.*.text.max' => 'Текст справочного блока не должен превышать 10000 символов.',

            'waste_plate_description.array' => 'Описание для плитных материалов передано в неверном формате.',
            'waste_plate_description.title.max' => 'Заголовок описания для плитных материалов не должен превышать 255 символов.',
            'waste_plate_description.text.max' => 'Описание для плитных материалов не должно превышать 3000 символов.',

            'waste_edge_description.array' => 'Описание для кромки передано в неверном формате.',
            'waste_edge_description.title.max' => 'Заголовок описания для кромки не должен превышать 255 символов.',
            'waste_edge_description.text.max' => 'Описание для кромки не должно превышать 3000 символов.',

            'waste_operations_description.array' => 'Описание для операций передано в неверном формате.',
            'waste_operations_description.title.max' => 'Заголовок описания для операций не должен превышать 255 символов.',
            'waste_operations_description.text.max' => 'Описание для операций не должно превышать 3000 символов.',

            'normohour_rate.numeric' => 'Ставка нормо-часа должна быть числом.',
            'normohour_rate.min' => 'Ставка нормо-часа не может быть отрицательной.',
            'normohour_region.max' => 'Название региона для нормо-часа не должно превышать 255 символов.',
            'normohour_date.date' => 'Дата для нормо-часа указана в неверном формате.',
            'normohour_method.in' => 'Выбран неверный метод расчёта нормо-часа.',
            'normohour_justification.max' => 'Обоснование нормо-часа не должно превышать 5000 символов.',
        ];
    }

    public function destroy(Request $request, Project $project)
    {
        $this->authorize('delete', $project);
        abort_if($project->archived_at !== null, 404);

        $revisionsCount = $project->revisions()->count();
        if ($revisionsCount > 0 && $request->input('confirm_delete') !== 'УДАЛИТЬ') {
            return response()->json([
                'message' => 'Для удаления проекта с ревизиями требуется подтверждение.',
                'requires_confirmation' => true,
                'confirmation_phrase' => 'УДАЛИТЬ',
                'revisions_count' => $revisionsCount,
            ], 422);
        }

        $project->archived_at = now();
        $project->save();
        return response()->json(['message' => 'Проект архивирован'], 200);
    }
}
