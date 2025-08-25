<?php

return [
    'subjects' => [
        'verify_email' => 'تأكيد البريد الإلكتروني',
        'reset_password' => 'إعادة تعيين كلمة المرور',
        'new_order' => 'طلب جديد',
    ],
    'verify_email' => [
        'greeting' => 'عزيزي, :name',
        'intro' => ': هذا هو رمز التحقق الخاص بك',
    ],
    'forgot_password' => [
        'greeting' => 'عزيزي المستخدم،',
        'body_1' => 'تم إنشاء طلب إعادة تعيين كلمة المرور.',
        'body_2' => 'يرجى إعادة تعيين كلمة المرور من خلال الضغط على الزر التالي:',
        'button' => 'إعادة تعيين كلمة المرور',
    ],
    'order_request' => [
        'hello_admin' => ': مرحباً مشرف النظام',
        'received_new_order' => ': لقد تلقيت طلباً جديداً',
        'user_details' => ': بيانات المستخدم',
        'order_details' => ': تفاصيل الطلب',
        'fields' => [
            'full_name' => 'الاسم الكامل:',
            'email' => ': البريد الإلكتروني',
            'phone' => 'رقم الهاتف:',
            'order_id' => 'رقم الطلب:',
            'product_name' => ': اسم المنتج',
            'quantity' => 'الكمية:',
            'price' => 'السعر:',
        ],
    ],
    'contact_request' => [
        'hello_admin' => 'مرحباً مشرف النظام:',
        'received_new_email' => 'لقد تلقيت رسالة جديدة:',
        'fields' => [
            'full_name' => 'الاسم الكامل:',
            'email' => ': البريد الإلكتروني',
            'phone' => 'رقم الهاتف:',
            'subject' => 'الموضوع:',
            'message' => 'الرسالة:',
        ],
    ],
];
