<?php
require_once __DIR__.'/../config/database.php';require_once __DIR__.'/secrets.php';
function integrationConfig(string $provider): array {$s=db()->prepare('SELECT status,config_json FROM integrations WHERE provider=?');$s->execute([$provider]);$row=$s->fetch();if(!$row)return ['status'=>'disconnected'];$config=json_decode($row['config_json']??'{}',true)?:[];return array_merge($config,['status'=>$row['status']]);}
function saveIntegration(string $provider,string $status,array $config): void {db()->prepare('INSERT INTO integrations(provider,status,config_json) VALUES(?,?,?) ON DUPLICATE KEY UPDATE status=VALUES(status),config_json=VALUES(config_json)')->execute([$provider,$status,json_encode($config,JSON_UNESCAPED_SLASHES)]);}
function integrationSecret(array $config,string $key): string {return decryptSecret($config[$key]??'');}
