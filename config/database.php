<?php
function db(): PDO {
    static $pdo;
    if ($pdo instanceof PDO) return $pdo;
    $host=getenv('RESUMEIQ_DB_HOST')?:'127.0.0.1';$port=getenv('RESUMEIQ_DB_PORT')?:'3306';$name=getenv('RESUMEIQ_DB_NAME')?:'resumeiq';$user=getenv('RESUMEIQ_DB_USER')?:'root';$pass=getenv('RESUMEIQ_DB_PASS')?:'';
    $pdo=new PDO("mysql:host=$host;port=$port;dbname=$name;charset=utf8mb4",$user,$pass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);return $pdo;
}
