<?php

declare(strict_types=1);

namespace App\Ingestion;

use RuntimeException;

/** O corpo passou de um limite de transporte: 4 MiB na requisição, ou 16 MiB depois de descomprimir (C-19). */
final class BodyTooLarge extends RuntimeException {}
