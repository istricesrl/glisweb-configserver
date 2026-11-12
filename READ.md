# configserver.istricesrl.com

Da codice deploy + utente + password al profilo di accesso, per glisweb-cli scaricato da GitHub e per glisweb-app.

- POST JSON {codice, user, pasw}: OK {status, url, token, account}, altrimenti KO unitario ( 401 ) a tempo fisso di 2s
- registro dei codici: etc/deploy.ini ( codice = url ), root:www-data 640
- limiti: 10 falliti per IP, 30 per codice, in 15 minuti; contatori in var/tentativi/
- log senza password: var/log/accessi.log

## codici

I codici del registro ( etc/deploy.ini ) sono 8 cifre casuali, che non dicono di che deploy si tratta: un nome parlante si indovina. Un deploy nuovo si aggiunge con

    php bin/nuovo-codice.php https://deploy/

che stampa il codice ( se l URL c e gia , quello che ha ). Il configserver accetta solo codici di 8 cifre.
