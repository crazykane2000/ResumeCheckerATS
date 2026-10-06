<?php
require_once __DIR__.'/lib/auth.php';requireAuth();require_once __DIR__.'/lib/integrations.php';
$config=integrationConfig('google_drive');$client=$config['client_id']??getenv('GOOGLE_CLIENT_ID');if(!$client||empty($config['client_secret'])&&!getenv('GOOGLE_CLIENT_SECRET')){header('Location: integrations.php');exit;}
$state=bin2hex(random_bytes(20));$_SESSION['drive_oauth_state']=$state;$redirect='http://127.0.0.1:8001/drive_callback.php';$params=['client_id'=>$client,'redirect_uri'=>$redirect,'response_type'=>'code','scope'=>'https://www.googleapis.com/auth/drive.readonly','access_type'=>'offline','prompt'=>'consent','state'=>$state];header('Location: https://accounts.google.com/o/oauth2/v2/auth?'.http_build_query($params));
