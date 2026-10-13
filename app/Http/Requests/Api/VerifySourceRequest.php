<?php

namespace App\Http\Requests\Api;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class VerifySourceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'type' => ['sometimes', 'string', 'max:50'],
            'fields' => ['required_without_all:text,file', 'prohibits:text,file', 'array', 'max:50'],
            'fields.*' => ['string', 'max:5000'],
            'fields.title' => ['sometimes', 'string', 'max:1000'],
            'text' => ['required_without_all:fields,file', 'prohibits:fields,file', 'string', 'max:100000'],
            'file' => ['required_without_all:fields,text', 'prohibits:fields,text', 'file', 'mimes:bib,txt', 'max:1024'],
        ];
    }

    public function bibContent(): ?string
    {
        if ($this->hasFile('file')) {
            return file_get_contents($this->file('file')->getRealPath());
        }

        return $this->input('text');
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(response()->json([
            'message' => 'The given data was invalid.',
            'errors' => $validator->errors(),
        ], 422));
    }
}
