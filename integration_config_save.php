<?php
require_once __DIR__.'/lib/auth.php';
requireAuth();
require_once __DIR__.'/lib/integrations.php';
if($_SERVER['REQUEST_METHOD']!=='POST'||!verifyCsrf($_POST['csrf']??'')){http_response_code(403);exit('Invalid request.');}
$type=$_POST['type']??'';
if(!in_array($type,['smtp','email_api','nonceblox_mysql','gemini_oauth'],true)){http_response_code(422);exit('Invalid integration type.');}
$old=integrationConfig($type);
if($type==='email_api'){$url=trim((string)($_POST['url']??''));if(!filter_var($url,FILTER_VALIDATE_URL)||strtolower((string)parse_url($url,PHP_URL_SCHEME))!=='https'||strtolower((string)parse_url($url,PHP_URL_HOST))!=='hrms-api.nonceblox.com'){http_response_code(422);exit('Use an approved HTTPS NonceBlox Email API URL.');}}
$config=[];
foreach($_POST as $key=>$value){if(in_array($key,['csrf','type'],true))continue;$value=trim((string)$value);if(in_array($key,['password','client_secret','api_key'],true))$config[$key]=$value!==''?encryptSecret($value):($old[$key]??'');else $config[$key]=$value;}
saveIntegration($type,'configured',$config);
header('Location: integrations.php?saved='.urlencode($type));
