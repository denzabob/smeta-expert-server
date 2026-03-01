@echo off
echo Local-only build: run this on your workstation, commit code, then deploy via git pull on VPS.
cd /d "C:\xampp\htdocs\smeta-expert-server\client"
call npm run build-only
pause
