#!/bin/bash
# prova il configserver con credenziali vere: bash prova.sh <codice> <utente> [password]
# 1. il login passa dal configserver su web02 ( in locale sul server, finche' manca il certificato )
# 2. il token ricevuto si usa da QUI su <url>api/cli: se risponde, il token non e' legato all'IP del configserver
codice=$1; utente=$2; pasw=$3
[ -z "$utente" ] && { echo "uso: bash prova.sh <codice> <utente> [password]"; exit 1; }
[ -z "$pasw" ] && { read -rsp "password: " pasw; echo; }

json=$(CODICE="$codice" UTENTE="$utente" PASW="$pasw" python3 -c 'import json,os; print(json.dumps({"codice":os.environ["CODICE"],"user":os.environ["UTENTE"],"pasw":os.environ["PASW"]}))')

echo "== 1. configserver ( web02 ) =="
risposta=$(echo "$json" | ssh root@web02.istricesrl.it 'cd /var/www/configserver.istricesrl.com/dev
su -s /bin/sh www-data -c "php -S 127.0.0.1:8099 >/dev/null 2>&1 &"; sleep 1
curl -s -w "\n%{http_code} %{time_total}s" -X POST -H "Content-Type: application/json" --data-binary @- http://127.0.0.1:8099/
pkill -f "php -S 127.0.0.1:8099" >/dev/null 2>&1; true')
corpo=$(echo "$risposta" | head -n -1)
echo "HTTP $(echo "$risposta" | tail -1)"
echo "$corpo" | python3 -c 'import json,sys; d=json.load(sys.stdin); t=d.get("token"); d["token"]=(t[:6]+"...") if t else None; print(json.dumps(d, ensure_ascii=False)[:400])'

url=$(echo "$corpo" | python3 -c 'import json,sys; print(json.load(sys.stdin).get("url") or "")')
token=$(echo "$corpo" | python3 -c 'import json,sys; print(json.load(sys.stdin).get("token") or "")')
[ -z "$token" ] && { echo "KO: niente token, la prova si ferma qui"; exit 1; }

echo "== 2. token usato da questa macchina su ${url}api/cli =="
curl -s -X POST -H "Content-Type: application/json" -H "gliswebAuthKey: $token" -d '{}' "${url}api/cli" | python3 -c 'import json,sys
d=json.load(sys.stdin)
if d.get("status")=="KO": print("KO:", d.get("err"), "-> il token potrebbe essere legato all IP del configserver")
else: print("OK: utente", (d.get("account") or {}).get("username"), "- glisweb", d.get("version"), "- il token vale anche da un altro IP")'
