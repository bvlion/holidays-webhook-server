<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

class CommandRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize()
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        return [
            'target_name' => ['required', 'string', 'max:256'],
            'target_type' => ['required', 'regex:/^user$|^group$/'],
            'url' => ['required', 'url', 'max:1024'],
            'method' => ['required', 'string', 'max:32'],
            'body_type' => ['required', 'regex:/^json$|^form_params$|^query$/'],
            'headers_values' => ['json', 'max:4096'],
            'parameters' => ['json', 'max:4096'],
        ];
    }

    protected function failedValidation(Validator $validator)
    {
        $res = response()->json([
            'status' => 400,
            'errors' => $validator->errors(),
        ], 400);
        throw new HttpResponseException($res);
    }
}
