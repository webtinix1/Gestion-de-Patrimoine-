<?php

return [
    'modal' => [
        'form' => [
            'code' => [
                'actions' => [
                    'resend' => [
                        'notifications' => [
                            'throttled' => [
                                'title' => 'Trop de demandes de renvoi. Veuillez patienter avant de demander un nouveau code.',
                            ],
                        ],
                    ],
                ],
                'messages' => [
                    'rate_limited' => 'Trop de tentatives. Veuillez réessayer plus tard.',
                ],
            ],
        ],
    ],
];
