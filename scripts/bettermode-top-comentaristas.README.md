# Top comentaristas en Bettermode (diez.5t4d10.com)

Ranking de miembros por número de **comentarios** (en Bettermode un comentario = *reply*)
en un rango de fechas, vía la API GraphQL Analytics. Salida: CSV en `./output/` + resumen
en consola.

## Requisitos
- PHP 8 con cURL (sin dependencias externas).
- Variables de entorno (ver `bettermode-top-comentaristas.env.example`). En este repo ya
  existen en el `.env` raíz (`BETTERMODE_API_URL`, `BETTERMODE_NETWORK_ID`,
  `BETTERMODE_NETWORK_DOMAIN`, `BETTERMODE_ADMIN_EMAIL`, `BETTERMODE_ADMIN_PASSWORD`), así
  que corre tal cual. Si tienes un `BETTERMODE_APP_TOKEN`, el script lo usa directo y se
  salta el login.

## Uso
```bash
# Últimos 30 días (default), top 100:
php scripts/bettermode-top-comentaristas.php

# Rango y límite específicos:
php scripts/bettermode-top-comentaristas.php --from=2026-05-01 --to=2026-05-31 --limit=50
```
- `--from` / `--to`: `YYYY-MM-DD` (se interpretan en hora **Ciudad de México**).
- `--limit`: default 100. Si el resultado iguala el límite, el script avisa que puede
  haber más (sube `--limit`).

## Salida
- CSV: `scripts/output/top-comentaristas_<from>_a_<to>_<timestamp>.csv`
  columnas: `posicion, member_id, nombre, username, email, comentarios` (BOM UTF-8).
- Consola: top 10 + total de miembros con comentarios + rango consultado.

## Notas técnicas (validadas contra la API real, 2026-06)
- Endpoint US `https://api.bettermode.com` (solo POST, Bearer token).
- DSL de `analytics`: `count(reply) as comentarios` funciona aislado (no hace falta el
  score ponderado).
- En la respuesta, `records[].entities` es un **objeto** (`entities.person`), no un arreglo;
  el conteo viene en `records[].payload` como pares `{key,value}`. El script cruza ambos.
- Filtro de espacios: `space_type in ('GROUP','BROADCAST')`. Si se agregan tipos de espacio
  nuevos en 5T4D10, revisar que sigan cubiertos.
- No pagina: usa `limit`. Para el universo completo, sube `--limit`.
- Plan B (no usado): `leaderboardWithScores` existe pero mezcla posts+replies+reacciones,
  así que NO aísla comentarios.
