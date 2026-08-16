<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Validator;

final class UpdateProfileRequest extends FormRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'first_name' => ['bail', 'required', 'string', 'max:255', 'not_regex:/\p{C}/u', 'regex:/[\p{L}\p{N}]/u'],
            'last_name' => ['bail', 'required', 'string', 'max:255', 'not_regex:/\p{C}/u', 'regex:/[\p{L}\p{N}]/u'],
        ];
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $unexpected = array_diff(array_keys($this->all()), ['first_name', 'last_name']);

            if ($unexpected !== []) {
                $validator->errors()->add(
                    'request',
                    'The profile request contains unsupported fields.',
                );
            }
        }];
    }

    protected function prepareForValidation(): void
    {
        $normalized = [];

        foreach (['first_name', 'last_name'] as $field) {
            if (is_string($this->input($field))) {
                $normalized[$field] = Str::squish($this->string($field)->toString());
            }
        }

        $this->merge($normalized);
    }
}
