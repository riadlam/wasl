<?php

return [
    'path' => resource_path('ai-skills'),

    /*
    | Drop a name here, or delete its folder, to turn a skill off.
    */
    'surfaces' => [
        'dm' => ['grounded-facts', 'reply-language', 'data-gathering', 'dm-closer', 'customer-closer'],
        'comment' => ['grounded-facts', 'reply-language', 'data-gathering', 'comment-reply', 'customer-closer'],
        'owner' => ['grounded-facts', 'reply-language', 'data-gathering', 'owner-assistant', 'social-media-manager', 'post-draft', 'image-gen', 'profile-interview'],
        'campaign' => ['grounded-facts', 'reply-language', 'data-gathering', 'social-media-manager'],
        'profile' => ['channel-identity'],
        'interview' => ['profile-interview', 'reply-language'],
    ],
];
