# df-named-query — SGA/SGC (E10)

Integração nativa DreamFactory com SGA (login) e SGC (conexões).

## Configuração (distribuível via ambiente)

| Variável | Default | Uso |
|---|---|---|
| `SGA_ENDPOINT` | `http://172.31.16.89:80/SGA/WsAcesso` | SOAP `validarLogin`/`getPerfilUsuario` |
| `SGA_ALLOWLIST` | `172.31.16.89` | hosts permitidos (csv) |
| `SGC_ENDPOINT` | `http://172.31.16.89:80/SGC/WsConexao` | SOAP `getConexaoById`/`getListaConexaoSistema` |
| `SGC_ALLOWLIST` | `172.31.16.89` | hosts permitidos (csv) |

Código-fonte: `src/Services/SgaClient.php:19` (`WSDL_SGA`),
`src/Services/SgcConnectionClient.php:19` (`WSDL_SGC`).
Compose lê com override: `docker-compose.yml:19-22`
(`SGA_ENDPOINT=${SGA_ENDPOINT:-...}`). Exemplo: `.env-dist` (seção SGA/SGC).

Nenhum segredo é logado; logs carregam só `host`/`status`/`ids`.

## Operação

- Login: `POST /api/v2/sga_login/sync` espelha conta/papel (público).
- Sync databases (admin): `POST /api/v2/sga_login/connections`
  `{"nomSistema":"DF"}` lê `getListaConexaoSistema` e faz upsert
  idempotente por nome `sgc-{idConexao}-...` (`src/Services/SgaDatabaseSyncService.php`).
  Re-sync atualiza só `host/port/database/username/password`; resto preservado.
- Tela: página Database (`/api-connections/api-types/database`) tem botão
  `Sync SGA` (`dreamfabric-admin/.../df-manage-services/`); requer admin
  (`src/Resources/SgaSyncConnectionsResource.php`).

## Validar

```powershell
docker run --rm -v "<host>/dreamfactory-fork:/app" -w /app php:8.3-cli php -l extensions/df-named-query/src/Services/SgcConnectionClient.php
docker compose config | Select-String -Pattern 'SGC_|SGA_'
npm test  # via determinus_run_test (outer tests/outer-validate.js)
```
