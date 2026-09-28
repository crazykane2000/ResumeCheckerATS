<?php
function appEncryptionKey(): string {
    $env=trim((string)getenv('RESUMEIQ_APP_KEY'));if($env!=='')return hash('sha256',$env,true);
    $dir=__DIR__.'/../data';$file=$dir.'/.app-key';if(!is_dir($dir))mkdir($dir,0770,true);
    if(!is_file($file)){file_put_contents($file,bin2hex(random_bytes(32)),LOCK_EX);@chmod($file,0600);}
    return hash('sha256',trim((string)file_get_contents($file)),true);
}
function encryptSecret(string $plain): string {
    if($plain==='')return '';$iv=random_bytes(12);$tag='';$cipher=openssl_encrypt($plain,'aes-256-gcm',appEncryptionKey(),OPENSSL_RAW_DATA,$iv,$tag);if($cipher===false)throw new RuntimeException('Secret encryption failed.');return base64_encode($iv.$tag.$cipher);
}
function decryptSecret(?string $payload): string {
    if(!$payload)return '';$raw=base64_decode($payload,true);if($raw===false||strlen($raw)<29)return '';$iv=substr($raw,0,12);$tag=substr($raw,12,16);$plain=openssl_decrypt(substr($raw,28),'aes-256-gcm',appEncryptionKey(),OPENSSL_RAW_DATA,$iv,$tag);return $plain===false?'':$plain;
}
