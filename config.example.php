<?php
return [
    // Must match API_KEY in secrets.h.
    'api_key' => 'replace-with-a-long-random-key',
    'admin_username' => 'admin',
    // Generate with: php -r "echo password_hash('your-password', PASSWORD_DEFAULT);"
    'admin_password_hash' => 'replace-with-a-password-hash',
];
