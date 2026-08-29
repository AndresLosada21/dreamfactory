# AGENTS.md — Pipeline Agnóstico de Desenvolvimento com Agents de IA

> **Propósito deste arquivo:** preservar o workflow estabelecido nesta sessão para que ele
> sobreviva à compactação. É a fonte da verdade agnóstica — não depende do projeto
> DreamFactory/api-query. Qualquer novo chat deve ler este arquivo primeiro e seguir
> o pipeline abaixo sem inventar.

---

## 1. Princípios

- **Fonte da verdade são os arquivos.** Toda afirmação precisa de `file:line`. Não inventar endpoints, contratos ou pastas.
- **Separar campo real de teoria.** "É pra funcionar" ≠ validado. Validado exige evidência executada (`docker run`, `vendor/bin/phpunit`, `curl`, `gh issue view`).
- **PO/GP = humano. DEV = agents de IA.** O humano decide `Priority/Iteration/Ready`; agents só puxam `Ready + Todo` na iteração atual.
- **TDD Ultra obrigatório:** 15 testes em RED antes de qualquer implementação de sprint. Nenhum agente implementa antes do commit RED.
- **Paralelismo com guardrail.** Só paraleliza se `Ready`, sem dependência aberta, sem tocar nos mesmos arquivos e sem violar `needs-decision`.
- **GH CLI pelos campos corretos.** Nunca editar milestone/label direto no board; board espelha issues. `gh project item-edit --field-id/--single-select-option-id/--project-id`.

---

## 2. Descoberta (antes de codar)

### 2.1 Varredura com subagents

Sempre disparar varreduras paralelas com `thoroughness: very thorough`:

| Agent | Foco |
|-------|------|
| `explore` (quick/medium/very thorough) | Estrutura de pastas, `config/**`, `src/**`, `extensions/**`, `docs/**`, `composer.json`, `Dockerfile*` |
| `general` | Visão de especialista no domínio (ex: DreamFactory commercial vs OSS, SSO, rate-limit, etc) |

Cada subagent deve reportar **file:line** para todo achado. Inventário cru fica em `docs/architecture/inventory-*.md` com decisões `Preserve / Migrate / Deprecate` por comportamento.

### 2.2 Saídas da descoberta

- `inventory-*` (contrato congelado) com tabela canônica (ex: 8 queries + extras)
- `driver-matrix-decision.md` / `.cleanroom/ledger.csv` se houver camada de licenciamento
- Matriz de gaps: o que foi bypassado (free), o que ainda é pago, o que é net-new — com `installer.sh:line` como prova

---

## 3. Gestão PO/GP via GH CLI (GitHub Projects V2)

### 3.1 Estrutura canônica

```
Milestones (fonte da verdade nos issues): M0 → M1 → M2 → M3 → M4
Epics: [EPIC] E0 (58) → E8 (66) — cada epic agrupa RQs via Sub-issues nativo
RQs: RQ-001 (67) → RQ-084 (114) — 5 tipos × 5 áreas × 3 prioridades
Labels: 44 (type:*, area:*, priority:*, contract-parity, etc)
Project V2: PVT_<id> (ex: 4) linkado ao repo via `gh project link`
```

### 3.2 Campos do Project (todos via `gh project field-create` / `field-list`)

| Campo | Tipo | Valores |
|-------|------|---------|
| `Status` | SingleSelect | `Todo` / `In Progress` / `Done` |
| `Priority` | SingleSelect | `P0` critical / `P1` / `P2` spike / `P3` |
| `Size` | SingleSelect | `XS` spike / `S` doc-or-test / `M` feature / `L` epic / `XL` |
| `Estimate` | Number | story points / horas (preencher ao puxar) |
| `Iteration` | SingleSelect | `Sprint 1` / `Sprint 2` / `Sprint 3` / `Sprint 4` / `Backlog` |
| `Start date` / `Target date` | Date | janela por RQ |
| `Agent` (fila IA) | SingleSelect | `spike` `core` `query-engine` `db-connector` `auth-compat` `admin-ui` `sgc` `ha-ops` `migration` |
| `Ready` (gate) | SingleSelect | `Ready` (sem deps) / `Blocked` (tem deps) / `Needs-Decision` / `Needs-Spike` |
| Nativos herdados | — | `Title, Assignees, Labels, Milestone, Repository, Parent issue, Sub-issues progress, Created/Updated/Closed, Linked pull requests` |

### 3.3 Comandos corretos

```powershell
# Descobrir campos/ids
gh project field-list <num> --owner <owner> --format json
gh project view <num> --owner <owner> --format json
gh project item-list <num> --owner <owner> --limit 100 --format json  # map number→id

# Adicionar issues ao board
gh project item-add <num> --owner <owner> --url https://github.com/<owner>/<repo>/issues/<n> --format json

# Editar pelos campos corretos (um campo por invocação)
gh project item-edit --id <itemId> --project-id <PVT_...> --field-id <Priority|Size|Agent|Ready|Iteration|Estimate|Start date|Target date> --single-select-option-id <optId>
gh project item-edit --id <itemId> --project-id <PVT_...> --field-id <Estimate> --number 5
gh project item-edit --id <itemId> --project-id <PVT_...> --field-id <Start date> --date 2026-09-01

# Meta
gh project edit <num> --owner <owner> --title "..." --description "..." --readme "..."
gh project link <num> --owner <owner> --repo <owner>/<repo>
```

**Regra:** nunca duplicar `Milestone/Label` no board — board espelha issues. Issues são fonte da verdade (`body` com `Objetivo / Dependências RQ-XXX / Critérios de aceite / Integração obrigatória`).

### 3.4 Dependências e gating

- `Ready` = `## Dependencias` no body está vazia (`Nenhuma`) ou todos os `RQ-XXX` listados já estão `CLOSED`.
- `Blocked` = tem deps abertas. `Needs-Decision` = `RQ-XXX` spike/decision aberta.
- IA **nunca** puxa `Blocked`. Desbloqueia automaticamente quando deps fecham (próxima varredura seta `Ready`).
- Ordem canônica por deps (ex: `67→68→69...`), validada parseando `Dependencias` com regex `RQ-0*(\d+)`.

---

## 4. TDD Ultra — 15 testes em RED antes de implementar

### 4.1 Contrato

- Toda sprint tem um arquivo `tests/Feature/TddUltraSprint<N>Test.php` com **15 testes**.
- Todos devem falhar com `assertTrue(false, ''TDD RED...'')` no final — isso garante RED proposital.
- Só depois do commit+push RED as IAs implementam. Gate de promoção: virar para GREEN sem editar o teste.

### 4.2 Template de teste RED

```php
public function test_rq030_tokenizer_ignores_strings_and_comments(): void {
    $compiler = __DIR__ . ''/../../extensions/.../NamedSqlCompiler.php'';
    $content = file_exists($compiler) ? file_get_contents($compiler) : '''';
    $hasSkip = str_contains($content, ''SKIP'') && str_contains($content, "(*SKIP)(*F)");
    self::assertTrue($hasSkip, ''RQ-030...'');
    self::assertTrue(false, ''TDD RED RQ-030: falta ...''); // força RED
}
```

### 4.3 Execução (Docker — única fonte confiável)

```powershell
# Lint
docker run --rm -v "<host>/dreamfactory-fork:/app" -w /app php:8.3-cli php -l tests/Feature/TddUltraSprint3Test.php

# RED esperado: 15/15 FAIL
docker run --rm -v "<host>/dreamfactory-fork:/app" -w /app php:8.3-cli vendor/bin/phpunit -c phpunit.xml-dist --testsuite Feature --filter TddUltraSprint3

# Após implementar — GREEN esperado: 15/15
docker run --rm -v "<host>/dreamfactory-fork:/app" -w /app php:8.3-cli vendor/bin/phpunit -c phpunit.xml-dist --testsuite Feature --filter TddUltraSprint3
docker run --rm -v "<host>/dreamfactory-fork:/app" -w /app php:8.3-cli vendor/bin/phpunit -c phpunit.xml-dist --testsuite "Yamaha Extensions"  # 65/65
```

`vendor` é montado do host — se não existir, `composer:2 install --ignore-platform-reqs` com `COMPOSER_PROCESS_TIMEOUT=900`. Nunca assumir `php` no Windows; sempre via `docker` ou `wsl`.

---

## 5. Execução de Sprint (IA-operável)

### 5.1 Protocolo

1. IA lista `item-list` e filtra `Ready + Todo` da `Iteration` atual, ordenado por `Priority P0→P1`.
2. Um agent por fila `Agent`; `spike/core/query-engine/db-connector` podem rodar em paralelo dentro do sprint; `auth-compat/admin-ui/sgc/ha-ops/migration` só após blocos anteriores.
3. Para cada RQ: `gh issue view <n> --json body,labels,milestone` → implementa → `php -l` → `vendor/bin/phpunit --filter` → commit.
4. Definition of Done: todos os checkboxes `Critérios de aceite` marcados + PR linkado em `Linked pull requests` + CI verde.
5. Ao fechar: `gh issue close <n> --comment "..."` + `project item-edit Status→Done` (desbloqueia dependentes na próxima varredura).

### 5.2 Guardrails de paralelismo

- ✅ Paralelo seguro: filas `Agent` distintas + `Ready` + arquivos disjuntos + sem `needs-decision` compartilhado.
- ❌ Serial obrigatório: dependência `RQ-XXX` aberta, mesmo arquivo, `needs-decision` ou `needs-spike`, budget compartilhado.
- Waves documentadas no commit: `Wave1 paralelo (Ready): RQ-040+041 | Wave2 serial: 042→043→044→045`.

### 5.3 Passo 1/2/3 (quando houver validação de campo)

- **Passo 1 — campo real** (`RQ-073` matriz CI): roda em paralelo como `docs/infra` sem tocar `E4`. Ex: `.github/workflows/ci-matrix.yml` com 4 jobs reais (postgres/oracle/sqlserver/informix) + `docs/ci-matrix.md` com `podman load -i` em `172.31.18.117`.
- **Passos 2/3:** `E4:62` (`89-94` RBAC/budgets/compat) antes de `E5/E6/E7/E8`.

---

## 6. Validação — campo real vs teoria

Sempre produzir tabela:

| Entregue E VALIDADO em execução real | Evidência executada |
|---|---|
| `phpunit --testsuite Feature --filter TddUltraSprint3` → 15/15 | `docker run ...` ok |
| `push 8d9fc44..65f31de origin/master` + `gh issue close` | log `To https://...` |
| `node --test api-query/tests/blackbox/src/offline-golden.test.js` → 7/7 | TAP ok |

| Entregue MAS só teoria (unit, sem banco real) | Falta |
|---|---|
| `sqlsrv sys.*` sem `pdo_sqlsrv` em `172.31.18.117` | `podman run -p 18082:80` + `named-query:import` |
| `oracle ALL_*` sem Instant Client OTN | runner autorizado |

| Não entregue — N OPEN | Próximo |
|---|---|
| `E4:62 89-94` etc | Sprint N |

Não alegar "pronto" sem evidência executada. `docker ps` vazio = nenhum serviço em campo.

---

## 7. Git

- Commits: `type(SprintN escopo): mensagem` + `Refs: #<issues>` + `Closes: #<issues>` quando fechar epic.
- Exemplos: `test(TDD-ULTRA Sprint3): add 15 RED...`, `feat(Sprint2 M1-M2 E2+E3): RQ-020..035 TDD GREEN`, `feat(E0+E1 RQ-003,010-014): close baseline...`
- Só `add .` no fork deixa `.phpunit.result.cache` staged — remover antes: `Remove-Item -Force .phpunit.result.cache`.
- Push: `git -C dreamfactory-fork push origin master` (ou `git push` na raiz monorepo para `api-query` Azure).
- `api-query` é repo aninhado com `.git` próprio (`origin` Azure). Não usar `git add api-query/tests/...` na raiz — fazer `git -C api-query add tests/...`.

---

## 8. Tooling — armadilhas Windows/PowerShell

- Não existe `head`, `ls`, `where php` como no Unix. Usar:
  - `Select-Object -First 20` ao invés de `head`
  - `Get-ChildItem -Force | Format-Table -AutoSize | Out-String -Width 800`
  - `where.exe php` (retorna erro se não existir — normal)
- `php` raramente está no Windows — usar `docker run --rm -v "<host>:/app" -w /app php:8.3-cli ...`.
- `wsl --list --verbose` e `docker version` são probes rápidos.
- `gh` precisa de `gh auth status` com scopes `gist, project, read:org, repo, workflow`. Para `gh api graphql` usar `--input` com arquivo, não `--field query=...`.
- `vendor` pode estar dessincronizado (`extensions/` vs `vendor/yamaha/...`): copiar com `Copy-Item -Recurse -Force extensions/... vendor/yamaha/...`.
- `gh issue view --jq` falha com aspas do PowerShell — usar `gh issue view <n> --json body --jq .body` sem `".[] |"` complexo, ou parse via `ConvertFrom-Json`.

---

## 9. Citação e revisão

- Toda linha não-trivial cita `file:line` (ex: `extensions/df-named-query/src/Query/NamedSqlCompiler.php:143`).
- Revisão de EPIC: rodar `gh issue list --state open --json number,title` + checar `sub_issues` (`gh api repos/<owner>/<repo>/issues/<n>/sub_issues --jq ".[].number"`).
- Fechar RQs em ordem e, quando todos os filhos fecharem, fechar o Epic (`gh issue close 58 --comment "Epic fechado - 5/5..."`) e mover `item-edit Status→Done`.

---

## 10. Retomar após compactação

1. Ler este `AGENTS.md`.
2. `Get-Location` + `Get-ChildItem -Force` para confirmar root (`Query-builder` monorepo com `dreamfactory-fork/`, `api-query/`).
3. `gh auth status` + `gh project view <num> --owner <owner>` + `gh issue list --state open --limit 100 --json number,title,state,labels`.
4. `git -C dreamfactory-fork log --oneline -8` + `git -C dreamfactory-fork status --short` para posição.
5. `docker run --rm -v "...:/app" -w /app php:8.3-cli vendor/bin/phpunit -c phpunit.xml-dist --testsuite Feature --filter TddUltraSprint<N>` para validar estado TDD.
6. Identificar `Epic E0/E1 abertos` → revisar critérios faltantes → re-finalizar antes de nova sprint.
7. Seguir §5 para próxima sprint — nunca pular §4 (TDD RED).

---

> **Modo @ai-driven-engineering:** este pipeline AGENTS.md é a implementação concreta dos 3 planos da skill — `PRODUCT = AGENTS.md:3 issues/Sub-issues + .ai/product-contract.md`, `DELIVERY = AGENTS.md:3 Project 4 + .ai/delivery-contract.md + waves`, `ENGINEERING = AGENTS.md:4-5 TDD Ultra + .ai/engineering-contract.md + qb-net field`. Ver `.ai/product-contract.md`, `.ai/delivery-contract.md`, `.ai/engineering-contract.md`, `.ai/decision-log.md`.

*Última atualização: 2026-08-29 — 19 teoria mortos via docker local qb-net (qb-pg/qb-mssql) + @ai-driven-engineering .ai contracts. Fork 6e05f16 → próximo: fechar E4:62 (EW-01) e Sprint 4 TDD RED 15 (EW-02).*
