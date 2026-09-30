<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProjectDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // A controller must authorize upload access for the project.
    }

    public function rules(): array
    {
        return [
            'project_id' => ['required', Rule::exists('projects', 'id')],
            'project_phase_id' => ['nullable', Rule::exists('project_phases', 'id')->where('project_id', $this->input('project_id'))],
            'document_type' => ['required', Rule::in(['estimate', 'budget', 'request_evidence', 'payment_receipt', 'invoice', 'contract', 'drawing', 'other'])],
            'file' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png,webp,doc,docx,xls,xlsx', 'max:20480'],
            'documentable_type' => ['nullable', 'string', 'max:255'],
            'documentable_id' => ['nullable', 'integer', 'min:1'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}