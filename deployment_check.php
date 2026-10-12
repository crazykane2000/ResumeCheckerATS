<?php
declare(strict_types=1);

const DEPLOYMENT_CHECK_KEY = '2d79f651d78a40c5b8251f9228d6b50f';

if(!isset($_GET['key'])||!hash_equals(DEPLOYMENT_CHECK_KEY,(string)$_GET['key'])){
    http_response_code(404);
    exit('Not found.');
}

ini_set('display_errors','0');
header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store, max-age=0');
header('X-Robots-Tag: noindex, nofollow');

$checks=[];
$add=static function(string $name,bool $ok,string $detail='')use(&$checks):void{
    $checks[]=[$name,$ok,$detail];
};

$add('PHP version',version_compare(PHP_VERSION,'8.1.0','>='),PHP_VERSION);
foreach(['pdo_mysql','mbstring','fileinfo','zip','curl','openssl'] as $extension){
    $add('Extension '.$extension,extension_loaded($extension));
}

foreach([
    'login.php'=>'login.php',
    'auth bootstrap'=>'lib/auth.php',
    'database bootstrap'=>'config/database.php',
    'database credentials'=>'config/database.local.php',
    'auth view'=>'views/auth.php',
] as $label=>$relative){
    $add('File '.$label,is_file(__DIR__.'/'.$relative));
}

foreach(['tmp','tmp/sessions','data','uploads'] as $relative){
    $path=__DIR__.'/'.$relative;
    $add('Directory '.$relative,is_dir($path),is_dir($path)?(is_writable($path)?'writable':'not writable'):'missing');
}

try{
    $localFile=__DIR__.'/config/database.local.php';
    if(!is_file($localFile))throw new RuntimeException('Database credentials file is missing.');
    $local=require $localFile;
    if(!is_array($local))throw new RuntimeException('Database credentials file must return an array.');
    foreach(['host','name','user','pass'] as $key){
        if(!array_key_exists($key,$local))throw new RuntimeException('Database credential key is missing: '.$key);
    }
    $add('Database configuration syntax',true,'required keys found');
}catch(ParseError $error){
    $add('Database configuration syntax',false,'PHP syntax error in config/database.local.php');
}catch(Throwable $error){
    $add('Database configuration syntax',false,$error->getMessage());
}

try{
    require_once __DIR__.'/config/database.php';
    $pdo=db();
    $pdo->query('SELECT 1')->fetchColumn();
    $tableCount=(int)$pdo->query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE()')->fetchColumn();
    $userCount=(int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
    $add('Database connection',true,'tables='.$tableCount.', users='.$userCount);
}catch(Throwable $error){
    $message=strtolower($error->getMessage());
    $detail='Database connection failed.';
    if(str_contains($message,'access denied'))$detail='Access denied: verify database username, password, and assigned permissions.';
    elseif(str_contains($message,'unknown database'))$detail='Database name was not found.';
    elseif(str_contains($message,'could not find driver'))$detail='PDO MySQL extension is unavailable.';
    elseif(str_contains($message,'connection refused')||str_contains($message,'getaddrinfo'))$detail='Database host or port is unreachable.';
    $add('Database connection',false,$detail);
}

try{
    $sessionDir=__DIR__.'/tmp/sessions';
    if(!is_dir($sessionDir)||!is_writable($sessionDir))throw new RuntimeException('tmp/sessions is missing or not writable.');
    session_save_path($sessionDir);
    if(!session_start())throw new RuntimeException('PHP could not start a session.');
    $_SESSION['deployment_check_at']=time();
    session_write_close();
    $add('Session storage',true,'session created');
}catch(Throwable $error){
    $add('Session storage',false,$error->getMessage());
}

echo "ResumeIQ deployment check\n";
echo "=========================\n";
foreach($checks as [$name,$ok,$detail]){
    echo ($ok?'PASS':'FAIL').' | '.$name.($detail!==''?' | '.$detail:'')."\n";
}
echo "=========================\n";
echo "Delete deployment_check.php after troubleshooting.\n";
