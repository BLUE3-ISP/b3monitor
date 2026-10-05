<?php

declare(strict_types=1);

namespace App\Ingestion;

use RuntimeException;

/** O corpo não é interpretável: codificação desconhecida, gzip corrompido ou truncado, ou JSON malformado. */
final class BadBody extends RuntimeException {}
