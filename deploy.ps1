# Заливка на хостинг по FTP. Использование: .\deploy.ps1 -Ftp "ftp://HOST/pulse/" -User USER -Pass PASS [-Config path\config.php]
param([string]$Ftp, [string]$User, [string]$Pass, [string]$Config = '')
$root = $PSScriptRoot
$files = 'index.php','person.php','q.php','links.php','about.php','api.php','lib.php','config.sample.php','.htaccess','assets/style.css','assets/app.js','data/.htaccess'
foreach ($f in $files) {
  curl.exe -s --ftp-create-dirs --user "${User}:${Pass}" -T (Join-Path $root $f) "$Ftp$f"
  if ($LASTEXITCODE -ne 0) { Write-Error "Не удалось залить $f"; exit 1 }
  "ok $f"
}
if ($Config) { curl.exe -s --user "${User}:${Pass}" -T $Config "${Ftp}config.php"; 'ok config.php' }
