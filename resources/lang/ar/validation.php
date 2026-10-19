<?php

/*
 * WEBSITE-I18N-GEO-1 — Arabic validation messages for the public website's forms (the trial signup).
 * Only the rules those forms use; any other rule falls back to Laravel's English line.
 */
return [
    'accepted' => 'يجب قبول :attribute.',
    'confirmed' => 'تأكيد :attribute غير مطابق.',
    'email' => 'يجب أن يكون :attribute بريداً إلكترونياً صحيحاً.',
    'exists' => 'قيمة :attribute المختارة غير صالحة.',
    'in' => 'قيمة :attribute المختارة غير صالحة.',
    'integer' => 'يجب أن يكون :attribute رقماً صحيحاً.',
    'max' => [
        'string' => 'يجب ألا يزيد :attribute عن :max حرفاً.',
        'numeric' => 'يجب ألا يزيد :attribute عن :max.',
    ],
    'min' => [
        'string' => 'يجب ألا يقل :attribute عن :min أحرف.',
        'numeric' => 'يجب ألا يقل :attribute عن :min.',
    ],
    'not_in' => 'قيمة :attribute المختارة غير صالحة.',
    'required' => 'حقل :attribute مطلوب.',
    'size' => [
        'string' => 'يجب أن يكون :attribute :size حرفاً.',
    ],
    'string' => 'يجب أن يكون :attribute نصاً.',
    'unique' => ':attribute مستخدم من قبل.',

    'attributes' => [
        'business_name' => 'اسم النشاط التجاري',
        'tenant_code' => 'النطاق الفرعي',
        'owner_name' => 'اسم المالك',
        'owner_email' => 'البريد الإلكتروني للمالك',
        'owner_phone' => 'هاتف المالك',
        'password' => 'كلمة المرور',
        'plan_id' => 'الباقة',
        'billing_period' => 'طريقة الدفع',
        'currency_code' => 'العملة',
    ],
];
