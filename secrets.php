<?php
function appSecretKey(): string {
    static $key = null;
    if ($key === null) {
        $key = getenv('RESUMEIQ_APP_SECRET') ?: 'nonceblox_ats_secret_key_2026';
    }
    return $key;
}
