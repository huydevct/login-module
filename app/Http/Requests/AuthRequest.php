<?php

namespace Modules\Login\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AuthRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'email' => 'required|email',
            'password' => 'required|string|min:4',
        ];
    }

    public function authorize(): bool
    {
        return true;
    }
}
