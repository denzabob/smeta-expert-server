#!/bin/bash
echo "Local-only build: run this on your workstation, commit code, then deploy via git pull on VPS."
cd /c/xampp/htdocs/smeta-expert-server/client
npm run build-only
