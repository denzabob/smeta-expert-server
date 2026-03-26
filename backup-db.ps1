$BackupDir = "C:\xampp\htdocs\smeta-expert-server\backups"
New-Item -ItemType Directory -Force -Path $BackupDir | Out-Null

$ts = Get-Date -Format "yyyy-MM-dd_HH-mm-ss"
$file = Join-Path $BackupDir "smeta_dev_$ts.sql"

docker exec smeta_db sh -lc 'mysqldump -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" "$MYSQL_DATABASE"' | Out-File -Encoding utf8 $file

Get-Item $file | Select-Object FullName,Length,LastWriteTime
