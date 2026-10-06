# b3monitor — comandos entregáveis (02 §10 do pacote 2.0).
#
# REGRA DESTE ARQUIVO: um alvo que ainda não tem o que fazer FALHA, dizendo qual item da
# fila do loop o implementa. Nunca passa em silêncio — um `make test` verde sem teste
# nenhum é o falso verde que o 00 §3 proíbe. Quando o item fecha, o alvo deixa de chamar
# `nao-implementado` no mesmo commit.
#
# Sem Docker nem Compose (ADR-005). Só `make docs-bundle` é real hoje.

SHELL := /bin/bash
.DEFAULT_GOAL := help

.PHONY: help test test-central test-agents verify lab-up lab-down demo acceptance-lab probe-doctor smoke \
        deploy-central pg-up pg-down pg-status docs-bundle

# Arquivos do consolidado, na ordem de leitura 2.0 (PRECEDENCIA.md §1). O 02 vem do pacote
# 2.0, que substitui o do 1.0; o resto do 1.0 é a base.
V1 := .continue/b3monitor
V2 := .continue/b3monitor_2.0
BUNDLE      := dist/blue3_monitoramento_bundle.md
BUNDLE_SRCS := $(V2)/README.md $(V2)/PRECEDENCIA.md $(V2)/14_GLOSSARIO_E_CONVENCOES.md \
               $(V1)/00_PROMPT_PARA_O_AGENTE.md $(V1)/01_PRODUTO_E_ESCOPO.md \
               $(V2)/02_ARQUITETURA_E_STACK.md \
               $(V1)/03_ESTADOS_INCIDENTES_E_REPLAY.md $(V1)/04_SONDAS_E_EXECUCAO.md \
               $(V1)/05_UPSTREAMS_E_PROVA_DE_CAMINHO.md $(V1)/06_MODELO_DE_DADOS_E_CONTRATOS.md \
               $(V1)/07_SEGURANCA.md $(V1)/08_APP_PAINEL_E_ALERTAS.md \
               $(V1)/09_BACKLOG_E_PLANO_DE_ENTREGA.md $(V1)/10_TESTES_E_CRITERIOS_DE_ACEITE.md \
               $(V1)/11_IMPLANTACAO_OPERACAO_E_RUNBOOKS.md $(V1)/12_DECISOES_PREMISSAS_E_FONTES.md \
               $(V1)/13_CHECKLIST_DO_PILOTO_E_STATUS.md

# $(call nao-implementado,<itens da fila que o implementam>)
define nao-implementado
	@printf 'não implementado: %s\n' '$(1)' >&2; exit 1
endef

help: ## Lista os alvos e diz quais ainda não existem
	@printf 'b3monitor — alvos de 02 §10. "não implementado" falha de propósito.\n\n'
	@printf '  %-19s %s\n' \
	  'test'            'Pest na central e go test nos agentes          [real]' \
	  'build-probe'     'compila o binário da sonda em agents/probe/bin [real]' \
	  'verify'          'test + vet; FALHA até a validação de schema  [A8b]' \
	  'pg-up|down|status' 'PostgreSQL de dev isolado, porta 54329      [real]' \
	  'lab-up|down'     'laboratório numa máquina só                  [real]' \
	  'demo'            'cenário sintético: sucesso, falha, replay    [real]' \
	  'acceptance-lab'  'aceite da F0-01 no laboratório, com evidência [real]' \
	  'probe-doctor'    'diagnóstico da sonda                         [F0-02, fora da rodada]' \
	  'smoke'           'verificação não destrutiva do laboratório    [real]' \
	  'deploy-central'  'RECUSADO: exige autorização do dono          [nunca automático]' \
	  'docs-bundle'     'consolidado em dist/ para colar em chat      [real]'

test: test-central test-agents ## Pest (central/) e go test (agents/)

test-central: ## Suíte Pest da central, no banco b3mon_test (exige make pg-up)
	@test -x central/vendor/bin/pest || { printf 'central sem dependências: rode "composer install" em central/\n' >&2; exit 1; }
	@bash lab/pg.sh status >/dev/null 2>&1 || { printf 'PostgreSQL de dev parado: rode "make pg-up"\n' >&2; exit 1; }
	@cd central && ./vendor/bin/pest

# ⚠️ Sem nenhum `go.mod` em agents/, a suíte dos agentes é PULADA, em voz alta, e
# isso não é aprovação: 09 §6, "um teste ignorado não conta como aprovado". Com `go.mod`, roda.
test-agents: ## go test em cada módulo de agents/ (pulado, avisando, se não houver módulo)
	@mods="$$(ls agents/*/go.mod 2>/dev/null)"; \
	if [ -z "$$mods" ]; then \
	  printf 'PULADO, NÃO APROVADO: agents/ não tem nenhum módulo Go\n' >&2; \
	else \
	  for m in $$mods; do d="$$(dirname $$m)"; \
	    if [ -z "$$(cd "$$d" && go list ./... 2>/dev/null)" ]; then \
	      printf 'PULADO, NÃO APROVADO: %s tem go.mod e nenhum pacote\n' "$$d" >&2; \
	    else \
	      echo "== go test $$d"; (cd "$$d" && go test ./...) || exit 1; \
	    fi; \
	  done; \
	fi
	@echo "== go test lab/target"; cd lab/target && go test ./...

# ⚠️ `verify` FALHA de propósito até o item A8b: ele roda o que existe (testes, go vet, composer
# validate) e termina dizendo, com erro, que a validação de payload contra os schemas não foi feita.
# Sem isso um `make verify` verde afirmaria uma verificação que ninguém fez.
build-probe: ## Compila o binário da sonda em agents/probe/bin (não versionado)
	@mkdir -p agents/probe/bin
	@cd agents/probe && go build -trimpath -ldflags "-X main.versao=$$(head -1 ../../version.md | tr -d '[:space:]') -X main.commit=$$(git rev-parse --short HEAD 2>/dev/null || echo unknown)" -o bin/b3mon-probe ./cmd/b3mon-probe
	@./agents/probe/bin/b3mon-probe version

verify: test ## test + go vet + composer validate; a validação de schema é o item A8b
	@for m in $$(ls agents/*/go.mod 2>/dev/null); do d="$$(dirname $$m)"; \
	  if [ -z "$$(cd "$$d" && go list ./... 2>/dev/null)" ]; then \
	    printf 'PULADO: %s tem go.mod e nenhum pacote para o go vet\n' "$$d" >&2; \
	  else echo "== go vet $$d"; (cd "$$d" && go vet ./...) || exit 1; fi; \
	done
	@(cd central && composer validate --no-interaction >/dev/null) && echo "composer validate: ok"
	@printf 'NÃO VERIFICADO: validação de payload contra os schemas (item A8b, ADR-014 proposta). Os testes conferem só a estrutura dos contratos.\n' >&2; exit 1

pg-up: ## PostgreSQL 17 de desenvolvimento, em espaço de usuário, porta 54329
	@bash lab/pg.sh up

pg-down: ## Para o PostgreSQL de desenvolvimento (os dados ficam)
	@bash lab/pg.sh down

pg-status: ## Diz se está no ar, a versão e os bancos
	@bash lab/pg.sh status

lab-up: ## Laboratório: PG, central, workers, agendador, alvo e DUAS sondas (primeira vez: composer install e go build)
	@bash lab/lab.sh up

lab-down: ## Para o laboratório (sondas com SIGTERM) e o PostgreSQL; o estado fica
	@bash lab/lab.sh down

demo: ## Cenário sintético: sucesso, falha → incidente aberto e fechado, ausência, replay (~3 min; exige make lab-up)
	@bash lab/demo.sh

acceptance-lab: ## Aceite da F0-01 no laboratório: sucesso, falha, recuperação, ausência, replay, duplicata, resposta perdida (~4 min; exige make lab-up)
	@bash lab/acceptance.sh

probe-doctor: ## Diagnóstico da sonda
	$(call nao-implementado,F0-02, fora da rodada atual)

smoke: ## Verificação não destrutiva do laboratório (exige make lab-up)
	@bash lab/lab.sh smoke

deploy-central: ## Deploy da central (11 §7)
	@printf 'recusado: deploy exige autorização específica do dono e backup prévio (11 §7).\nNenhum deploy foi implementado nesta rodada.\n' >&2; exit 1

docs-bundle: ## Consolidado do pacote 1.0 + 2.0 em dist/, para colar em chat (não versionado)
	@mkdir -p dist
	@for f in $(BUNDLE_SRCS); do test -f "$$f" || { printf 'faltou: %s\n' "$$f" >&2; exit 1; }; done
	@{ for f in $(BUNDLE_SRCS); do \
	     printf '\n---\n\n**Arquivo de origem:** `%s`\n\n' "$$f"; \
	     sed 's/^#/##/' "$$f"; \
	   done; } > $(BUNDLE)
	@printf 'gerado: %s (%s linhas)\n' '$(BUNDLE)' "$$(wc -l < $(BUNDLE))"
