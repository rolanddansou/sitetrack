<?php

declare(strict_types=1);

namespace App\Infrastructure\Reporting;

use Doctrine\DBAL\Connection;

/**
 * Date-ranged variant of DashboardController::fetchIncidents() — same
 * alert_events/alert_rules join, but bounded by [start, end] instead of a
 * fixed "20 most recent" limit, for report-generation use.
 */
class IncidentQueryService
{
    public function __construct(private Connection $connection) {}

    /**
     * @return array<int, array<string, mixed>>
     */
    public function findIncidentsInRange(int $monitorId, \DateTimeImmutable $start, \DateTimeImmutable $end): array
    {
        return $this->connection->createQueryBuilder()
            ->select('e.*, r.condition_type, r.channel')
            ->from('alert_events', 'e')
            ->join('e', 'alert_rules', 'r', 'e.rule_id = r.id')
            ->where('r.monitor_id = :monitorId')
            ->andWhere('e.triggered_at BETWEEN :start AND :end')
            ->setParameter('monitorId', $monitorId)
            ->setParameter('start', $start->format('Y-m-d H:i:s'))
            ->setParameter('end', $end->format('Y-m-d H:i:s'))
            ->orderBy('e.triggered_at', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();
    }
}
