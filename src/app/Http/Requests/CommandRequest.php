<?php

namespace App\Http\Requests;

class CommandRequest extends BaseFormRequest
{
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
}
