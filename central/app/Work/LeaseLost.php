<?php

declare(strict_types=1);

namespace App\Work;

use RuntimeException;

/** O item já não pertence a este worker: a lease venceu e o reconciliador o devolveu à fila (ou outro o concluiu). */
final class LeaseLost extends RuntimeException {}
