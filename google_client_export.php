<?php // .env の GOOGLE_CLIENT_* を書き換える

$hidden_file = 'google_client_secret';

if (!file_exists($hidden_file)) {
  return;
}

$content = file_get_contents($hidden_file);
$rows = explode("\n", $content);

$env_file = 'src/.env';
$str = file_get_contents($env_file);
 
$str = preg_replace('/GOOGLE_CLIENT_ID.*/', $rows[0], $str);
$str = preg_replace('/GOOGLE_CLIENT_SECRET.*/', $rows[1], $str);
 
file_put_contents($env_file, $str);