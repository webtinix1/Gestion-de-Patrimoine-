<?php

namespace App\Services\System\Backup;

use RuntimeException;

/**
 * Échec d'une sauvegarde ou d'une restauration : le message est destiné à l'administrateur, en français.
 */
final class BackupException extends RuntimeException {}
