<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Validator;

final class OrganizationNameRequest extends FormRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'name' => ['bail', 'required', 'string', 'max:120', 'not_regex:/\p{C}/u', 'regex:/[\p{L}\p{N}]/u'],
        ];
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if (array_diff(array_keys($this->all()), ['name']) !== []) {
                $validator->errors()->add(
                    'request',
                    'The Organization request contains unsupported fields.',
                );
            }
        }];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('name'))) {
            $this->merge(['name' => Str::squish($this->string('name')->toString())]);
        }
    }
}
