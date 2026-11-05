/^\[/ { sec = $0 }
/^action =/ && sec ~ /^\[broken\.pl/ { $0 = "action = skip" }
/^url =/ { $0 = "url =" }
/^url =/ && sec ~ /^\[example\.pl/ { $0 = "url = http://127.0.0.1:8081/" }
/^url =/ && sec ~ /^\[broken\.pl/ { $0 = "url = http://127.0.0.1:8082/" }
{ print }
