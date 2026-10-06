<?php
function db(): PDO {
    static $pdo;
    if ($pdo instanceof PDO) return $pdo;
    $localFile=__DIR__.'/database.local.php';$local=is_file($localFile)?require $localFile:[];if(!is_array($local))$local=[];
    $host=$local['host']??(getenv('RESUMEIQ_DB_HOST')?:'127.0.0.1');$port=$local['port']??(getenv('RESUMEIQ_DB_PORT')?:'3306');$name=$local['name']??(getenv('RESUMEIQ_DB_NAME')?:'resumeiq');$user=$local['user']??(getenv('RESUMEIQ_DB_USER')?:'root');$pass=$local['pass']??(getenv('RESUMEIQ_DB_PASS')?:'');
    $pdo=new PDO("mysql:host=$host;port=$port;dbname=$name;charset=utf8mb4",$user,$pass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);return $pdo;
}
