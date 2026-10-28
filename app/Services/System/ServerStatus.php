<?php

namespace App\Services\System;

use App\Services\Settings;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * E41, onglet « État du serveur » : lecture seule. Réutilise les contrôles de l'installation
 * (EnvironmentCheck) et y ajoute l'espace disque, le planificateur (dernier calcul des alertes, toutes
 * les heures) et la dernière sauvegarde connue. Ne modifie rien, ne crée aucun dossier.
 */
class ServerStatus
{
    /** Le calcul des alertes est horaire (RG-90) : au-delà de ce délai, le planificateur est suspect. */
    public const int SCHEDULER_STALE_MINUTES = 150;

    public function __construct(private EnvironmentCheck $environment, private Settings $settings) {}

    /**
     * @return list<array{label: string, status: 'ok'|'warn'|'fail', detail: string}>
     */
    public function checks(): array
    {
        return [
            $this->environment->php(),
            ...$this->environment->extensions(),
            $this->environment->database(),
            ...$this->environment->limits(),
            $this->disk(),
            $this->scheduler(),
            $this->backup(),
        ];
    }

    /**
     * @return array{label: string, status: 'ok'|'warn', detail: string}
     */
    public function disk(): array
    {
        $path = storage_path();
        $free = @disk_free_space($path);
        $total = @disk_total_space($path);

        if ($free === false || $total === false || $total <= 0) {
            return ['label' => 'Espace disque', 'status' => 'warn', 'detail' => 'indisponible'];
        }

        $used = $total - $free;
        $low = $free < 1_073_741_824 || $free / $total < 0.1;

        return [
            'label' => 'Espace disque',
            'status' => $low ? 'warn' : 'ok',
            'detail' => self::size((int) $used).' utilisés sur '.self::size((int) $total).', '.self::size((int) $free).' libres'
                .($low ? ' : moins de 10 % ou moins de 1 Go libre, libérez de la place avant la prochaine sauvegarde' : ''),
        ];
    }

    /**
     * @return array{label: string, status: 'ok'|'warn', detail: string}
     */
    public function scheduler(): array
    {
        $last = $this->moment(Settings::ALERTS_LAST_RUN_AT);

        if (! $last instanceof CarbonImmutable) {
            return ['label' => 'Planificateur (calcul des alertes)', 'status' => 'warn', 'detail' => 'aucune exécution enregistrée : vérifiez la tâche planifiée qui lance `php artisan schedule:run` chaque minute (docs/07)'];
        }

        $late = $last->diffInMinutes(now()) > self::SCHEDULER_STALE_MINUTES;

        return [
            'label' => 'Planificateur (calcul des alertes)',
            'status' => $late ? 'warn' : 'ok',
            'detail' => 'dernier calcul le '.$last->timezone(config('app.timezone'))->format('d/m/Y à H:i')
                .($late ? ' : plus de 2 h 30, la tâche planifiée ne semble plus tourner' : ''),
        ];
    }

    /**
     * @return array{label: string, status: 'ok'|'warn', detail: string}
     */
    public function backup(): array
    {
        $last = $this->moment(Settings::LAST_BACKUP_AT);

        if (! $last instanceof CarbonImmutable) {
            return ['label' => 'Dernière sauvegarde', 'status' => 'warn', 'detail' => 'aucune sauvegarde enregistrée (php artisan patrimoine:sauvegarder, docs/07)'];
        }

        return [
            'label' => 'Dernière sauvegarde',
            'status' => 'ok',
            'detail' => 'le '.$last->timezone(config('app.timezone'))->format('d/m/Y à H:i'),
        ];
    }

    private static function size(int $bytes): string
    {
        $units = [[1_073_741_824, 'Go'], [1_048_576, 'Mo'], [1024, 'Ko']];

        foreach ($units as [$step, $unit]) {
            if ($bytes >= $step) {
                return rtrim(rtrim(number_format($bytes / $step, 1, ',', ' '), '0'), ',').' '.$unit;
            }
        }

        return $bytes.' o';
    }

    private function moment(string $key): ?CarbonImmutable
    {
        $value = $this->settings->get($key);

        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (Throwable) {
            return null;
        }
    }
}
