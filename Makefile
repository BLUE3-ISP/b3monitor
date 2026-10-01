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

.PHONY: help test verify lab-up lab-down demo acceptance-lab probe-doctor smoke \
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
	  'test'            'Pest na central e go test nos agentes        [A6, A7, B1]' \
	  'verify'          'test + análise estática + contratos          [A8]' \
	  'pg-up|down|status' 'PostgreSQL de dev isolado, porta 54329      [A5]' \
	  'lab-up|down'     'laboratório numa máquina só                  [B8]' \
	  'demo'            'cenário sintético: sucesso, falha, replay    [B8]' \
	  'acceptance-lab'  'casos de aceite da F0-01 com evidência       [B10]' \
	  'probe-doctor'    'diagnóstico da sonda                         [F0-02, fora da rodada]' \
	  'smoke'           'verificação não destrutiva do ambiente       [B8]' \
	  'deploy-central'  'RECUSADO: exige autorização do dono          [nunca automático]' \
	  'docs-bundle'     'consolidado em dist/ para colar em chat      [real]'

test: ## Pest (central/) e go test (agents/)
	$(call nao-implementado,central/ no item A6; agents/ no item A7; motor no item B1)

verify: ## test + phpstan + go vet + validação dos contratos
	$(call nao-implementado,item A8 (contratos) e os itens A6 e A7)

pg-up: ## PostgreSQL 17 de desenvolvimento, em espaço de usuário, porta 54329
	$(call nao-implementado,item A5)

pg-down:
	$(call nao-implementado,item A5)

pg-status:
	$(call nao-implementado,item A5)

lab-up: ## Laboratório: PG, central, workers, coordenador, executor e alvo
	$(call nao-implementado,item B8)

lab-down:
	$(call nao-implementado,item B8)

demo: ## Cenário sintético, identificado como laboratório
	$(call nao-implementado,item B8)

acceptance-lab: ## Casos de aceite aplicáveis ao laboratório
	$(call nao-implementado,item B10)

probe-doctor: ## Diagnóstico da sonda
	$(call nao-implementado,F0-02, fora da rodada atual)

smoke: ## Verificação não destrutiva
	$(call nao-implementado,item B8)

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
