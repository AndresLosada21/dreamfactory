# SGA ↔ DF Admin Settings — matriz de gaps (E10 follow-up)

Fonte DF: página `#/admin-settings` servida (cards: Admins, Users, Schema,
Schema Contracts, Files, Logs; sidebar inclui Authentication, Rate Limiting,
Role-Based Access, API Keys, Connections, Usage, Email Templates, Scheduler,
System Info, CORS, Cache, Config Import/Export, Global Lookup Keys, Logs,
Reporting, Alerts). Fonte SGA: `SGA/src/main/java/br/com/yamaha/sga`
(facades `WsAcesso` 20 ops, `WsDominio` 20 ops, `WsWorkflow` 29 ops;
beans `MBean*`; controllers `Controller*`). SGC fora de escopo (suficiente).

## Legenda

- OK: coberto e validado em execução. PARTIAL: cobre parte, falta sync.
- GAP: sem fonte SOAP. OUT: DF-nativo, sem contraparte SGA esperada.
- Evidência `file:` = estática (código); `exec:` = executada (navegador/SOAP).

## Matriz

| # | DF Admin Settings | Fonte SGA | Status | Evidência |
|---|---|---|---|---|
| 1 | Admins / Users (cards) | `WsAcesso.java`: `getListaUsuario`, `getUsuarioByMatricula`, `getListaUsuarioBySistema`, `getUsuarioByNome` | PARTIAL | exec: login espelhado E10; file: sem CRUD/disable propagado |
| 2 | Authentication (login/AD) | `validarLogin`, `ControllerActiveDirectory.java` (interno, sem SOAP) | PARTIAL | exec: `SgaLdapBridge` E10; file: sem mgmt AD, sem `sessionSecond` |
| 3 | Role-Based Access | `getPerfilUsuario`, `getListaPerfil`, `getListaAcessoByPerfil`, `listUserBySystemAndObject` | PARTIAL | exec: `DF_ADMIN→admin` E10; file: menus/objetos (`eXtype`) não sincronizados |
| 4 | Logs / Logstash | `ServiceAcessLog.java`, `ControllerAcessLog.java`, `FacadeAcess.java` — SEM op SOAP | GAP | file: `MBeanLogAcesso`/`MBeanAuditoriaUsuario` só camada MVC |
| 5 | Email Templates | `WsWorkflow.java`: `sendEmail` | PARTIAL | file: só disparo; sem CRUD de templates |
| 6 | Scheduler | `ControllerJob.java`, `MBeanJob` — SEM op SOAP | GAP | file: jobs só internos |
| 7 | API Keys | sem conceito no SGA | GAP | file: nenhum `MBean*ApiKey`; precisa decisão (SGA não deveria emitir keys DF) |
| 8 | Rate Limiting, CORS, Cache, Config Import/Export, Global Lookup, System Info, Files, Schema | sem contraparte | OUT | — DF-nativo |
| 9 | Alerts / Reporting / Usage | `listEventByWorkflow`, `MBeanEvento` (workflow interno) | PARTIAL | file: eventos existem; sem feed para DF |
| 10 | Password policy / troca de senha | só `validarLogin`; sem op de política/troca no SOAP | UNKNOWN | file: validar se há tela/política em `webapp` (não varrido) |

## Observabilidade (sem segredos, padrão E10 `logSanitized`)

Cada integração futura emite: evento `sga_admin_sync.one`
`{section, total, created, updated, skipped}`, métrica
`sga_admin_sync_{created,updated,skipped}_total{section}`,
alerta em `skipped/needs_attention > 0`. Campos proibidos em log:
`dscSenha`, `refSenha`, `password`, `secret`, XML SOAP.

## Validação executada neste EPIC (discovery)

- exec/navegador: `#/admin-settings` renderiza; cards e sidebar listados.
- exec/SOAP anterior (E10): `validarLogin`, `getListaConexaoSistema` OK.
- A executar por onda: snippet SOAP por seção (mesmo padrão
  `Invoke-WebRequest` E10) + `npm test` via `determinus_run_test`.

## Ondas propostas (entradas do EPIC)

1. Users/profiles/accesses → modelo leitura DF + observabilidade. (ENTREGUE #125)
2. Audit/log SGA → feed DF Logs (expõe SOAP ou job + conector). (ENTREGUE #126, via direta DF-side)
3. Email templates + scheduler → sync DF. (ABERTA #127)

## Varredura residual (2026-09-03, pós Waves 1/2/4)

Cobertura atual: login, conta, RBAC, users, audit, databases SGC, tela Admins.

| # | Ponto | Status | Rota |
|---|---|---|---|
| R1 | Wave 3: sendEmail + jobs | ABERTA | #127 |
| R2 | Troca/reset de senha: sem superfície no SGA (nem SOAP nem controller) | GAP, requer decisão | Wave 5 candidata |
| R3 | Tela de auditoria no admin (resource audit só tem API) | GAP | Wave 5 candidata (UI) |
| R4 | Catálogo de perfis (`getListaPerfil`) + sistemas (`getSistemasWeb/Desktop/Mobile`) | Menor | Wave 6 candidata |
| R5 | SGC `getConnectionBySystemAndReference` + `getListaConexao` (resolução por ref, picker) | Menor | Wave 6 candidata |
| R6 | Disable em tempo real (sync é poll; SGA não tem push) | Limitação documentada | OUT salvo necessidade |
| R7 | Workflow/documentos/aprovações SGA | BPM interno, sem contraparte DF | OUT |
| R8 | Domínio RH (`WsDominio`, funcionários, turnos, papéis) | Sem domínio DF | OUT |
| R9 | API Keys emitidas pelo SGA | Por desenho, SGA não emite | OUT |
