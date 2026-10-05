<?php

declare(strict_types=1);

namespace App\State;

/**
 * Por que uma observação não alimenta a saúde corrente (03 §3).
 *
 * Interno ao motor. Estes códigos vão ser persistidos junto da observação ("resultados não elegíveis
 * continuam armazenados com motivo"), então, ao chegar ao banco, precisam de um ADR que os fixe: o
 * 14 §4 só lista os `reason_code` que a sonda transporta, e estes nascem na central.
 */
enum Ineligibility: string
{
    /** Geração antiga serve apenas ao histórico daquele período. */
    case OldGeneration = 'old_generation';
    /** Duas observações do mesmo slot com conteúdo diferente: nenhuma vale, e não se escolhe por ordem de chegada. */
    case SlotConflict = 'slot_conflict';
    /** Horário agendado no futuro, além da tolerância de início. */
    case FutureTimestamp = 'future_timestamp';
    /** Relógio da sonda não sincronizado ou desconhecido: o resultado fica, o voto temporal não. */
    case ClockUntrusted = 'clock_untrusted';
    /** Slot que não rodou (limite, conflito de agenda, validade expirada): é AUSÊNCIA, não incapacidade. */
    case Skipped = 'skipped';
    /** Executor não mediu, configuração inválida, política negou ou capacidade ausente. */
    case NotMeasurable = 'not_measurable';
    /** Integridade diferente de `valid` no escopo avaliado. */
    case IntegrityNotValid = 'integrity_not_valid';
    /** A última evidência elegível é mais velha que o frescor, ou não existe nenhuma. */
    case Stale = 'stale';
}
