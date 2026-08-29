<?php

namespace App\Http\Controllers\Tenant\Catering;

use App\Http\Controllers\Controller;
use App\Models\Tenant\CateringInstruction;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * KASHIF-CATERING-INSTRUCTIONS-1 — the managed kitchen-instruction vocabulary.
 *
 * The Owner records the entries (Mirch Kam, Chawal Dana Dana, …); estimate
 * lines multi-select from them; the kitchen sheet prints the selections. The
 * vocabulary is DELIBERATELY not seeded — the authoritative list comes from the
 * client, and inventing it would put words in the kitchen's mouth.
 *
 * Deactivating hides an entry from new selection. Nothing here deletes: a line
 * that already carries an instruction keeps its meaning forever.
 */
class CateringInstructionController extends Controller
{
    public function index()
    {
        return view('tenant.catering.instructions.index', [
            'instructions' => CateringInstruction::ordered()->get(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'label' => ['required', 'string', 'max:120', Rule::unique('catering_instructions', 'label')],
            'label_ur' => ['nullable', 'string', 'max:120'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:65000'],
        ]);

        CateringInstruction::create([
            'label' => trim($data['label']),
            'label_ur' => $data['label_ur'] ?? null,
            'sort_order' => (int) ($data['sort_order'] ?? 0),
            'is_active' => true,
        ]);

        return redirect()->to('/catering/instructions')
            ->with('status', "'{$data['label']}' added to the kitchen vocabulary.");
    }

    public function update(Request $request, CateringInstruction $cateringInstruction)
    {
        $data = $request->validate([
            'label' => ['required', 'string', 'max:120',
                Rule::unique('catering_instructions', 'label')->ignore($cateringInstruction->id)],
            'label_ur' => ['nullable', 'string', 'max:120'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:65000'],
            'is_active' => ['required', 'boolean'],
        ]);

        $cateringInstruction->update([
            'label' => trim($data['label']),
            'label_ur' => $data['label_ur'] ?? null,
            'sort_order' => (int) ($data['sort_order'] ?? 0),
            'is_active' => (bool) $data['is_active'],
        ]);

        return redirect()->to('/catering/instructions')
            ->with('status', "'{$cateringInstruction->label}' updated.");
    }
}
