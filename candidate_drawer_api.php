<?php
require_once __DIR__.'/lib/auth.php';requireAuth();
header('Content-Type: application/json; charset=utf-8');
$id=trim((string)($_GET['id']??''));
if($id===''){http_response_code(422);echo json_encode(['ok'=>false,'error'=>'Candidate is required.']);exit;}
$stmt=db()->prepare('SELECT id,stored_file,source_url FROM candidates WHERE id=? LIMIT 1');$stmt->execute([$id]);$candidate=$stmt->fetch();
if(!$candidate){http_response_code(404);echo json_encode(['ok'=>false,'error'=>'Candidate not found.']);exit;}
define('RESUMEIQ_FUNCTIONS_ONLY',true);$_SERVER['REQUEST_METHOD']='GET';
require_once __DIR__.'/index.php';require_once __DIR__.'/lib/resume_storage.php';
try{
    $path=resolveCandidateResumePath($candidate,(int)(currentUser()['id']??0));
    if(!$path)throw new RuntimeException('Resume text is not available.');
    $extraction=extractResumeText($path,strtolower(pathinfo($path,PATHINFO_EXTENSION)));
    $text=trim((string)($extraction['text']??''));
    if($text==='')throw new RuntimeException(($extraction['requires_ocr']??false)?'This resume requires OCR before text can be displayed.':'No usable text was extracted from this resume.');
    echo json_encode(['ok'=>true,'text'=>$text,'warning'=>$extraction['error']??null],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
}catch(Throwable $e){http_response_code(422);echo json_encode(['ok'=>false,'error'=>'Resume text could not be extracted.']);}
