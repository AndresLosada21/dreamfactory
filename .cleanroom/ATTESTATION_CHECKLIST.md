# Attestation Checklist — Connector Clean-Room (RQ-004)

Copie este checklist para a descrição do PR que toca `extensions/df-oracle`, `extensions/df-sqlsrv` ou `extensions/df-informix`. O PR não pode ser mergeado com item desmarcado. Cada linha do ledger (`.cleanroom/ledger.csv`) deve corresponder a um item verificado aqui.

## Checklist por autor — assinar a cada PR

```markdown
- [ ] Li `docs/architecture/connector-clean-room.md` e a denylist (§4 — df-oracledb, df-sqlsrv, df-informix).
- [ ] Não acessei, clonei, li ou executei `dreamfactory/df-oracledb`, `dreamfactory/df-sqlsrv`, `dreamfactory/df-informix` (nem espelhos/forks) para este trabalho.
- [ ] Não copiei, reescrevi de memória ou traduzi código/comentário/teste/mensagem de erro proprietário.
- [ ] Toda lógica vem de allowlist (§5): df-core/df-sqldb Apache-2.0, Laravel, php.net, docs Oracle/Microsoft/IBM, yajra/laravel-oci8 (MIT) ou PECL pdo_informix.
- [ ] Registrei cada fonte no `.cleanroom/ledger.csv` com `source_url` canônica, `source_type`, `license` e `prior_exposure` honestos.
- [ ] Não commitei binário vendor (OCI Instant Client, MS ODBC .so/.dll, IBM CSDK / libifcli.so) — apenas receita de build / source PECL.
- [ ] Informei `prior_exposure` corretamente; se `yes`, solicitei revisor sem exposição.
- [ ] Entendo que este commit passa por revisão de proveniência (§9) e gate jurídico (§11) antes de publicação de imagem.

Assinado: __________________  Data: __________  Commit: __________  PR: __________
```

## O que o revisor verifica

- [ ] Cada `source_url` do ledger existe e corresponde a allowlist (§5).
- [ ] `grep -R "df-oracledb\|df-sqlsrv" --include="*.php" extensions/` retorna zero em `src/` (exceto este doc e ledger).
- [ ] `license` compatível com matriz §10; se runtime vendor incluso, `legal_signoff` exigido.
- [ ] `prior_exposure` tratado; se `yes`, revisor alternativo sem exposição.
- [ ] Comentário no PR: `Provenance reviewed: <reviewer> on <date> — ledger lines <commits> verified against allowlist §5`.

Referência: `docs/architecture/connector-clean-room.md` §8-9.
