<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\Validator;

final class UpdateProfilePasswordRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'current_password' => ['required', 'string', 'current_password:web'],
            'password' => ['required', 'string', Password::default(), 'confirmed'],
        ];
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $unexpected = array_diff(
                array_keys($this->all()),
                ['current_password', 'password', 'password_confirmation'],
            );

            if ($unexpected !== []) {
                $validator->errors()->add(
                    'request',
                    'The password request contains unsupported fields.',
                );
            }
        }];
    }
}
