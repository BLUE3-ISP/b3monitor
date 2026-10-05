<?php

declare(strict_types=1);

namespace App\Work;

/** O que um passo do worker fez. */
enum Resultado: string
{
    /** Não havia item pronto. */
    case Ocioso = 'idle';

    /** Processado e concluído. */
    case Concluido = 'done';

    /** O handler falhou: o item volta à fila com espera, ou para como `dead` se acabaram as tentativas. */
    case Falhou = 'failed';

    /** A lease foi perdida no meio: nada deste passo valeu, e o item é de quem o pegou depois. */
    case LeasePerdida = 'lease_lost';
}
