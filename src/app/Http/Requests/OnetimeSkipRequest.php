<?php

namespace App\Http\Requests;

class OnetimeSkipRequest extends BaseFormRequest
{
    public function rules()
    {
        return [
            'target_id' => ['required', 'integer'],
            'target_type' => ['required', 'regex:/^ssid$|^time$/'],
        ];
    }
}
