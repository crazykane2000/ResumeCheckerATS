<?php
function smtpRead($socket,array $expected): string {
    $response='';
    while(($line=fgets($socket,2048))!==false){$response.=$line;if(strlen($line)<4||$line[3]!=='-')break;}
    $code=(int)substr($response,0,3);
    if(!in_array($code,$expected,true))throw new RuntimeException('SMTP server rejected the request.');
    return $response;
}
function smtpWrite($socket,string $command,array $expected): string {fwrite($socket,$command."\r\n");return smtpRead($socket,$expected);}
function smtpSendHtml(array $config,string $to,string $subject,string $html): void {
    if(!filter_var($to,FILTER_VALIDATE_EMAIL))throw new RuntimeException('Recipient email is invalid.');
    $from=filter_var(trim((string)($config['from_email']??'')),FILTER_VALIDATE_EMAIL);$host=trim((string)($config['host']??''));$port=(int)($config['port']??587);$encryption=$config['encryption']??'tls';
    if(!$from||$host===''||$port<1||$port>65535)throw new RuntimeException('SMTP configuration is incomplete.');
    $target=($encryption==='ssl'?'ssl://':'').$host.':'.$port;$socket=@stream_socket_client($target,$errorNumber,$errorMessage,15,STREAM_CLIENT_CONNECT);
    if(!$socket)throw new RuntimeException('SMTP connection failed.');stream_set_timeout($socket,20);
    try{
        smtpRead($socket,[220]);smtpWrite($socket,'EHLO resumeiq.local',[250]);
        if($encryption==='tls'){smtpWrite($socket,'STARTTLS',[220]);if(!stream_socket_enable_crypto($socket,true,STREAM_CRYPTO_METHOD_TLS_CLIENT))throw new RuntimeException('SMTP encryption failed.');smtpWrite($socket,'EHLO resumeiq.local',[250]);}
        $username=trim((string)($config['username']??''));$password=integrationSecret($config,'password');
        if($username!==''){smtpWrite($socket,'AUTH LOGIN',[334]);smtpWrite($socket,base64_encode($username),[334]);smtpWrite($socket,base64_encode($password),[235]);}
        smtpWrite($socket,'MAIL FROM:<'.$from.'>',[250]);smtpWrite($socket,'RCPT TO:<'.$to.'>',[250,251]);smtpWrite($socket,'DATA',[354]);
        $safeSubject=str_replace(["\r","\n"],' ',trim($subject));$boundary='resumeiq-'.bin2hex(random_bytes(10));$headers=['From: '.$from,'To: '.$to,'Subject: '.$safeSubject,'MIME-Version: 1.0','Content-Type: text/html; charset=UTF-8','Content-Transfer-Encoding: base64'];$payload=implode("\r\n",$headers)."\r\n\r\n".chunk_split(base64_encode($html),76,"\r\n");$payload=str_replace("\r\n.\r\n","\r\n..\r\n",$payload);fwrite($socket,$payload."\r\n.\r\n");smtpRead($socket,[250]);smtpWrite($socket,'QUIT',[221]);
    }finally{fclose($socket);}
}
function interviewEmailHtml(string $company,string $domain,string $message): string {
    $company=htmlspecialchars($company,ENT_QUOTES|ENT_HTML5,'UTF-8');$domain=htmlspecialchars($domain,ENT_QUOTES|ENT_HTML5,'UTF-8');$copy=nl2br(htmlspecialchars($message,ENT_QUOTES|ENT_HTML5,'UTF-8'));
    return '<!doctype html><html><body style="margin:0;background:#f4f5f9;font-family:Arial,sans-serif;color:#202330"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="padding:28px 12px"><tr><td align="center"><table role="presentation" width="640" cellpadding="0" cellspacing="0" style="width:100%;max-width:640px;background:#fff;border:1px solid #e7e5f0;border-radius:12px;overflow:hidden"><tr><td style="padding:25px 30px;background:#f2efff"><strong style="font-size:24px;color:#6842ff">'.$company.'</strong><div style="margin-top:7px;color:#727786">'.$domain.'</div></td></tr><tr><td style="padding:34px 30px;font-size:15px;line-height:1.75">'.$copy.'</td></tr><tr><td style="padding:17px 30px;border-top:1px solid #eceaf3;color:#858997;font-size:12px">'.$company.' · '.$domain.'</td></tr></table></td></tr></table></body></html>';
}
