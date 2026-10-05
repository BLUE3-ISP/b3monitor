<?php

declare(strict_types=1);

namespace App\State;

use DateTimeImmutable;
use DateTimeZone;

/**
 * O motor de estado: uma FUNÇÃO PURA (03; 02 §3 do 2.0).
 *
 * Recebe a política versionada, as perspectivas previstas, as observações já aceitas e o instante de
 * avaliação, e devolve o estado e as transições. Não lê o relógio, não toca banco nem rede, e nunca
 * envia notificação: o instante vem de fora, e por isso a mesma entrada dá sempre a mesma saída.
 *
 * ⚠️ A EXPIRAÇÃO É POR TEMPO. Uma perspectiva sem evidência recente vira `unknown` mesmo quando nenhuma
 * amostra nova chega, porque `evaluate()` é chamado de novo com um instante mais tarde: nada aqui depende
 * de outra ingestão para retirar um verde antigo (03 §3; T-008).
 *
 * Escopo desta versão (F0-01): confirmação por perspectiva (03 §4), elegibilidade, frescor, e a agregação
 * por domínio com quórum (03 §5, tabela 5.1). Integridade persistente e quarentena (§7.2), anti-flap
 * (§7.1), redundância (§8) e replay (§9) são do F0-04 e não estão aqui.
 */
final class Engine
{
    /**
     * @param  list<PlannedPerspective>  $planned
     * @param  list<Observation>  $observations  em QUALQUER ordem: a ordem de chegada não importa
     */
    public function evaluate(Policy $policy, array $planned, array $observations, DateTimeImmutable $at): Evaluation
    {
        $at = $at->setTimezone(new DateTimeZone('UTC'));

        $porPerspectiva = [];
        foreach ($observations as $observation) {
            $porPerspectiva[$observation->perspectiveId][] = $observation;
        }

        $estados = [];
        foreach ($planned as $perspectiva) {
            $estados[] = $this->perspective($policy, $perspectiva, $porPerspectiva[$perspectiva->id] ?? [], $at);
        }

        return $this->aggregate($policy, $at, $estados);
    }

    /**
     * A unidade de estado: uma perspectiva numa geração do assignment (03 §4).
     *
     * @param  list<Observation>  $observations
     */
    private function perspective(Policy $policy, PlannedPerspective $perspectiva, array $observations, DateTimeImmutable $at): PerspectiveState
    {
        // Geração antiga (ou futura) serve só ao histórico: não entra na sequência atual.
        $atuais = array_filter($observations, fn (Observation $o) => $o->generation === $perspectiva->currentGeneration);

        // Agrupar por SLOT, e não por ordem de chegada. Dois conteúdos diferentes no mesmo slot são um
        // conflito: nenhum vale, porque escolher um por ordem de chegada tornaria o resultado dependente
        // dela (03 §9.1: "mesma chave com conteúdo incompatível gera conflito").
        $porSlot = [];
        foreach ($atuais as $o) {
            $porSlot[$o->slot->getTimestamp()][] = $o;
        }
        ksort($porSlot);

        $atTs = $at->getTimestamp();
        $confirmado = Health::Unknown;
        $ultimoConfirmado = null;
        $falhas = 0;
        $sucessos = 0;
        $slotAnterior = null;
        $ultimoElegivel = null;
        $ultimaAmostra = null;   // a amostra MAIS RECENTE (por slot): ['slot' => int, 'motivo' => ?Ineligibility]
        $transicoes = [];

        foreach ($porSlot as $slotTs => $amostras) {
            // Horário agendado no futuro (além da tolerância de início) não participa de nada.
            if ($slotTs > $atTs + $policy->startToleranceS) {
                continue;
            }

            // Continuidade: slots são `epoch + n × intervalo`. Qualquer lacuna interrompe a sequência.
            // Três falhas espaçadas NÃO são três consecutivas (03 §4; T-012).
            if ($slotAnterior !== null && $slotTs - $slotAnterior !== $policy->intervalS) {
                $falhas = 0;
                $sucessos = 0;
            }
            $slotAnterior = $slotTs;

            $motivo = $this->ineligibility($amostras);
            if ($motivo !== null) {
                // Não soma sucesso, falha, recuperação nem flap, e interrompe a sequência.
                $falhas = 0;
                $sucessos = 0;
                // Um slot pulado é ausência: não é uma "amostra mais recente" que force o presente a
                // `unknown`. Qualquer outro motivo é uma amostra que existiu e não serve.
                if ($motivo !== Ineligibility::Skipped) {
                    $ultimaAmostra = ['slot' => $slotTs, 'motivo' => $motivo];
                }

                continue;
            }

            $ultimoElegivel = $slotTs;
            $ultimaAmostra = ['slot' => $slotTs, 'motivo' => null];

            if ($amostras[0]->outcome === Outcome::Fail) {
                $falhas++;
                $sucessos = 0;
                if ($falhas >= $policy->failureCount && $confirmado !== Health::Down) {
                    $transicoes[] = new Transition($perspectiva->id, $confirmado, Health::Down, $this->at($slotTs));
                    $confirmado = Health::Down;
                    $ultimoConfirmado = Health::Down;
                }
            } else {
                $sucessos++;
                $falhas = 0;
                if ($sucessos >= $policy->recoveryCount && $confirmado !== Health::Up) {
                    $transicoes[] = new Transition($perspectiva->id, $confirmado, Health::Up, $this->at($slotTs));
                    $confirmado = Health::Up;
                    $ultimoConfirmado = Health::Up;
                }
            }
        }

        // O PRESENTE. O último estado confirmado só vale como presente se a evidência ainda é recente e a
        // amostra mais nova não foi um erro de medição.
        $razao = null;
        if ($ultimoElegivel === null) {
            $saude = Health::Unknown;
            $razao = $ultimaAmostra['motivo'] ?? Ineligibility::Stale;
        } elseif ($atTs - $ultimoElegivel > $policy->freshnessS()) {
            $saude = Health::Unknown;
            $razao = Ineligibility::Stale;
        } elseif ($ultimaAmostra !== null && $ultimaAmostra['motivo'] !== null) {
            // A amostra MAIS NOVA existiu e não serve: erro de integridade, relógio, incapacidade de medir
            // ou conflito. Isso torna a perspectiva corrente `unknown`; o último estado confirmado
            // permanece histórico (03 §4; T-024). Como a iteração é por slot crescente, uma amostra mais
            // nova que a última elegível é sempre posterior a ela.
            $saude = Health::Unknown;
            $razao = $ultimaAmostra['motivo'];
        } else {
            $saude = $confirmado;
        }

        // Confirmação em curso: nunca um verde sozinho (03 §4).
        $pendente = Pending::None;
        if ($saude !== Health::Unknown || $razao === null) {
            if ($falhas > 0 && $confirmado !== Health::Down) {
                $pendente = Pending::PendingFailure;
            } elseif ($sucessos > 0 && $confirmado !== Health::Up) {
                $pendente = Pending::PendingRecovery;
            }
        }

        return new PerspectiveState(
            $perspectiva->id,
            $perspectiva->domain,
            $saude,
            $pendente,
            $ultimoElegivel === null ? null : $this->at($ultimoElegivel),
            $ultimoConfirmado,
            $razao,
            $transicoes,
        );
    }

    /**
     * Elegibilidade de UM slot (03 §3). Devolve o motivo da inelegibilidade, ou nulo se a amostra serve.
     *
     * A autenticação, o schema e os limites já foram feitos pela ingestão; o frescor e a continuidade,
     * por `perspective()`. Aqui ficam conflito, medição, relógio e integridade.
     *
     * @param  list<Observation>  $amostras  todas as observações do MESMO slot e da mesma geração
     */
    private function ineligibility(array $amostras): ?Ineligibility
    {
        $primeira = $amostras[0];
        foreach ($amostras as $outra) {
            if (! $primeira->sameContentAs($outra)) {
                return Ineligibility::SlotConflict;
            }
        }

        // `skipped` é um slot que não rodou: AUSÊNCIA, não incapacidade. Interrompe a sequência, mas só o
        // frescor decide o presente, como em qualquer outra lacuna (ver `perspective()`).
        if ($primeira->outcome === Outcome::Skipped) {
            return Ineligibility::Skipped;
        }
        if (! $primeira->outcome->measuresTarget()) {
            return Ineligibility::NotMeasurable;
        }
        // Resultado é preservado, o voto temporal é negado: relógio desconhecido nunca vira "sincronizado".
        if ($primeira->clock !== ClockStatus::Synchronized) {
            return Ineligibility::ClockUntrusted;
        }
        if ($primeira->integrity !== Integrity::Valid) {
            return Ineligibility::IntegrityNotValid;
        }

        return null;
    }

    /**
     * Quórum por DOMÍNIO e cobertura (03 §5 e a tabela 5.1).
     *
     * @param  list<PerspectiveState>  $estados
     */
    private function aggregate(Policy $policy, DateTimeImmutable $at, array $estados): Evaluation
    {
        $porDominio = [];
        foreach ($estados as $estado) {
            $porDominio[$estado->domain][] = $estado;
        }

        $subiram = 0;
        $cairam = 0;
        $conflitos = [];
        foreach ($porDominio as $dominio => $membros) {
            $votos = [];
            foreach ($membros as $m) {
                if ($m->isEligible()) {
                    $votos[$m->health->value] = true;
                }
            }
            if (count($votos) > 1) {
                // Duas sondas do mesmo domínio discordam: o domínio não dá voto unânime e não completa quórum.
                $conflitos[] = (string) $dominio;
            } elseif (isset($votos[Health::Up->value])) {
                $subiram++;
            } elseif (isset($votos[Health::Down->value])) {
                $cairam++;
            }
        }
        sort($conflitos);

        $dominiosValidos = $subiram + $cairam;
        $todasElegiveis = count($estados) > 0 && count(array_filter($estados, fn (PerspectiveState $e) => ! $e->isEligible())) === 0;

        if ($dominiosValidos < $policy->minDomains) {
            $cobertura = Coverage::Insufficient;
        } elseif ($todasElegiveis && $conflitos === []) {
            $cobertura = Coverage::Full;
        } else {
            $cobertura = Coverage::Reduced;
        }

        // Cobertura insuficiente não conclui nada: `unknown / unknown`, e as evidências individuais seguem
        // visíveis em `perspectives` (03 §5.1, linhas 8 e 9). Não se reduz o mínimo por conta própria.
        if ($cobertura === Coverage::Insufficient) {
            $saude = Health::Unknown;
            $abrangencia = Scope::Unknown;
        } elseif ($cairam === 0) {
            $saude = Health::Up;
            $abrangencia = Scope::None;
        } elseif ($subiram === 0) {
            $saude = Health::Down;
            $abrangencia = Scope::AllObserved;
        } else {
            $saude = Health::Degraded;
            $abrangencia = Scope::Partial;
        }

        return new Evaluation(
            $policy->revision,
            $at,
            $saude,
            $abrangencia,
            $cobertura,
            $dominiosValidos,
            count($porDominio),
            $conflitos,
            $estados,
        );
    }

    private function at(int $timestamp): DateTimeImmutable
    {
        return (new DateTimeImmutable('@'.$timestamp))->setTimezone(new DateTimeZone('UTC'));
    }
}
