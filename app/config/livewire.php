<?php

/*
 * Only what differs from Livewire's own settings (the rest comes from vendor/livewire/livewire/config/livewire.php).
 * Uploads: up to 20 MB (the web server takes 20 MB) and 15 minutes, so a file sent from a phone on slow mobile data
 * is not refused or cut off.
 */
return [
    'temporary_file_upload' => [
        'disk' => null,
        'rules' => ['required', 'file', 'max:20480'],
        'directory' => null,
        'middleware' => null,
        'preview_mimes' => [
            'png', 'gif', 'bmp', 'svg', 'wav', 'mp4',
            'mov', 'avi', 'wmv', 'mp3', 'm4a',
            'jpg', 'jpeg', 'mpga', 'webp', 'wma',
        ],
        'max_upload_time' => 15,
        'cleanup' => true,
    ],
];
