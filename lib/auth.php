<?php
require_once __DIR__.'/../config/database.php';
function startAppSession(): void {if(session_status()===PHP_SESSION_ACTIVE)return;$p=__DIR__.'/../tmp/sessions';if(!is_dir($p))mkdir($p,0770,true);session_save_path($p);session_start();}
function currentUser(): ?array {startAppSession();return $_SESSION['user']??null;}
function requireAuth(): void {if(!currentUser()){header('Location: login.php');exit;}}
function csrfToken(): string {startAppSession();return $_SESSION['csrf']??=bin2hex(random_bytes(24));}
function verifyCsrf(string $token): bool {startAppSession();return hash_equals($_SESSION['csrf']??'',$token);}
