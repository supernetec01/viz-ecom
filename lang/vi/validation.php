<?php

return [
    'required' => 'Vui lòng nhập :attribute.',
    'string' => ':attribute phải là văn bản.',
    'email' => ':attribute phải là địa chỉ email hợp lệ.',
    'url' => ':attribute phải là liên kết hợp lệ.',
    'unique' => ':attribute đã được sử dụng.',
    'exists' => ':attribute không hợp lệ.',
    'in' => ':attribute không hợp lệ.',
    'enum' => ':attribute không hợp lệ.',
    'integer' => ':attribute phải là số nguyên.',
    'gte' => ['numeric' => ':attribute không được nhỏ hơn :value.'],
    'numeric' => ':attribute phải là số.',
    'max' => ['string' => ':attribute không quá :max ký tự.', 'numeric' => ':attribute không lớn hơn :max.', 'array' => ':attribute không quá :max phần tử.'],
    'min' => ['string' => ':attribute tối thiểu :min ký tự.', 'numeric' => ':attribute không nhỏ hơn :min.', 'array' => ':attribute tối thiểu :min phần tử.'],
];
