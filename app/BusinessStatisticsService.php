<?php
declare(strict_types=1);

final class BusinessStatisticsService
{
    private readonly DateTimeZone $timezone;

    public function __construct(private readonly PDO $pdo, private readonly int $businessId)
    {
        if ($businessId < 1) {
            throw new InvalidArgumentException('A empresa ativa não é válida.');
        }
        $this->timezone = BusinessCalendar::timezone();
    }

    public function dashboard(?DateTimeImmutable $now = null): array
    {
        $now = ($now ?? new DateTimeImmutable('now', $this->timezone))->setTimezone($this->timezone);
        $today = $now->setTime(0, 0);
        $tomorrow = $today->modify('+1 day');
        $monthStart = $today->modify('first day of this month');
        $nextMonth = $monthStart->modify('+1 month');
        $previousMonth = $monthStart->modify('-1 month');
        $yearStart = $today->setDate((int) $today->format('Y'), 1, 1);
        $nextYear = $yearStart->modify('+1 year');
        $periods = [
            'today' => [$today, $tomorrow],
            'yesterday' => [$today->modify('-1 day'), $today],
            'week' => [$today->modify('monday this week'), $tomorrow],
            'month' => [$monthStart, $nextMonth],
            'previous_month' => [$previousMonth, $monthStart],
            'year' => [$yearStart, $nextYear],
        ];

        $sales = $this->salesStatistics($periods);
        $lines = $this->lineStatistics($periods);
        $trendStart = $today->modify('-29 days');
        $trends = $this->timeSeries($trendStart, $tomorrow);
        $products = new ProductRepository($this->pdo, $this->businessId);
        $stockItems = $products->dashboardStockItems(8);
        $approachingItems = $products->approachingStockItems(8);

        return [
            'summary' => $products->summary(),
            'sales' => $sales,
            'profit' => $lines,
            'revenue_trend' => $trends['daily'],
            'monthly_trend' => $this->timeSeries($monthStart->modify('-11 months'), $nextMonth)['monthly'],
            'best_sellers' => $this->bestSellers($this->bounds($monthStart, $nextMonth), 5),
            'customers' => $this->customerBreakdown($this->bounds($monthStart, $nextMonth), 5),
            'movements' => $this->movementTotals($this->bounds($monthStart, $nextMonth)),
            'recent_sales' => $this->recentSales(),
            'stock_items' => array_merge($stockItems, $approachingItems),
        ];
    }

    public function reportSummary(string $from, string $to): array
    {
        $range = BusinessCalendar::rangeUtc($from, $to);
        $statement = $this->pdo->prepare(
            "SELECT COUNT(*) AS sales_count, COALESCE(SUM(total_cents), 0) AS revenue_cents
             FROM sales
             WHERE business_id = :business_id AND status = 'completed' AND created_at >= :start AND created_at < :end"
        );
        $statement->execute(['business_id' => $this->businessId] + $range);
        $lines = $this->lineTotals($range);
        $trends = $this->timeSeries(
            DateTimeImmutable::createFromFormat('!Y-m-d', $from, $this->timezone),
            DateTimeImmutable::createFromFormat('!Y-m-d', $to, $this->timezone)->modify('+1 day'),
            false
        );
        $summary = $statement->fetch();
        $summary['sales_count'] = (int) $summary['sales_count'];
        $summary['revenue_cents'] = (int) $summary['revenue_cents'];
        $summary['cost_cents'] = $lines['cost_cents'];
        $summary['gross_profit_cents'] = $lines['profit_cents'];
        $summary['known_cost_lines'] = $lines['known_cost_lines'];
        $summary['total_sale_lines'] = $lines['total_sale_lines'];
        $summary['average_sale_cents'] = $summary['sales_count'] > 0
            ? intdiv($summary['revenue_cents'], $summary['sales_count'])
            : 0;

        return [
            'summary' => $summary,
            'daily' => array_values(array_filter($trends['daily'], static fn(array $day): bool => $day['sales_count'] > 0)),
            'monthly' => array_values(array_filter($trends['monthly'], static fn(array $month): bool => $month['sales_count'] > 0)),
            'best_sellers' => $this->bestSellers($range, 10),
            'inventory' => (new ProductRepository($this->pdo, $this->businessId))->summary(),
            'low_stock' => (new ProductRepository($this->pdo, $this->businessId))->dashboardStockItems(100),
        ];
    }

    private function salesStatistics(array $periods): array
    {
        $parts = [];
        $parameters = [];
        foreach ($periods as $name => [$start, $end]) {
            $startKey = $name . '_start';
            $endKey = $name . '_end';
            $parameters[$startKey] = $this->utc($start);
            $parameters[$endKey] = $this->utc($end);
            $parts[] = "COALESCE(SUM(CASE WHEN status = 'completed' AND created_at >= :{$startKey} AND created_at < :{$endKey} THEN total_cents ELSE 0 END), 0) AS {$name}_revenue_cents";
            $parts[] = "COALESCE(SUM(CASE WHEN status = 'completed' AND created_at >= :{$startKey} AND created_at < :{$endKey} THEN 1 ELSE 0 END), 0) AS {$name}_sales_count";
        }
        $parts[] = "COALESCE(SUM(CASE WHEN status = 'completed' THEN total_cents ELSE 0 END), 0) AS all_time_revenue_cents";
        $parts[] = "COALESCE(SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END), 0) AS all_time_sales_count";
        $statement = $this->pdo->prepare('SELECT ' . implode(",\n", $parts) . '         FROM sales WHERE business_id = :business_id');
        $parameters['business_id'] = $this->businessId;
        $statement->execute($parameters);
        $values = array_map('intval', $statement->fetch());
        $values['average_sale_cents'] = $values['all_time_sales_count'] > 0
            ? intdiv($values['all_time_revenue_cents'], $values['all_time_sales_count'])
            : 0;
        $values['today_vs_yesterday_cents'] = $values['today_revenue_cents'] - $values['yesterday_revenue_cents'];
        $values['month_vs_previous_month_cents'] = $values['month_revenue_cents'] - $values['previous_month_revenue_cents'];

        return $values;
    }

    private function lineStatistics(array $periods): array
    {
        $parts = [];
        $parameters = [];
        foreach ($periods as $name => [$start, $end]) {
            $startKey = $name . '_start';
            $endKey = $name . '_end';
            $parameters[$startKey] = $this->utc($start);
            $parameters[$endKey] = $this->utc($end);
            $condition = "s.status = 'completed' AND s.created_at >= :{$startKey} AND s.created_at < :{$endKey}";
            $parts[] = "COALESCE(SUM(CASE WHEN {$condition} AND si.unit_cost_cents IS NOT NULL THEN si.unit_cost_cents * si.quantity ELSE 0 END), 0) AS {$name}_cost_cents";
            $parts[] = "COALESCE(SUM(CASE WHEN {$condition} AND si.unit_cost_cents IS NOT NULL THEN (si.unit_price_cents - si.unit_cost_cents) * si.quantity ELSE 0 END), 0) AS {$name}_profit_cents";
            $parts[] = "COALESCE(SUM(CASE WHEN {$condition} AND si.unit_cost_cents IS NOT NULL THEN 1 ELSE 0 END), 0) AS {$name}_known_cost_lines";
            $parts[] = "COALESCE(SUM(CASE WHEN {$condition} THEN 1 ELSE 0 END), 0) AS {$name}_total_sale_lines";
        }
        $statement = $this->pdo->prepare(
            'SELECT ' . implode(",\n", $parts) . ' FROM sale_items si
             JOIN sales s ON s.id = si.sale_id AND s.business_id = si.business_id
             WHERE si.business_id = :business_id'
        );
        $parameters['business_id'] = $this->businessId;
        $statement->execute($parameters);

        return array_map('intval', $statement->fetch());
    }

    private function lineTotals(array $range): array
    {
        $statement = $this->pdo->prepare(
            "SELECT
                COALESCE(SUM(CASE WHEN si.unit_cost_cents IS NOT NULL THEN si.unit_cost_cents * si.quantity ELSE 0 END), 0) AS cost_cents,
                COALESCE(SUM(CASE WHEN si.unit_cost_cents IS NOT NULL THEN (si.unit_price_cents - si.unit_cost_cents) * si.quantity ELSE 0 END), 0) AS profit_cents,
                COALESCE(SUM(CASE WHEN si.unit_cost_cents IS NOT NULL THEN 1 ELSE 0 END), 0) AS known_cost_lines,
                COUNT(*) AS total_sale_lines
             FROM sale_items si JOIN sales s ON s.id = si.sale_id
             WHERE si.business_id = :business_id AND s.business_id = si.business_id
               AND s.status = 'completed' AND s.created_at >= :start AND s.created_at < :end"
        );
        $statement->execute(['business_id' => $this->businessId] + $range);

        return array_map('intval', $statement->fetch());
    }

    private function timeSeries(DateTimeImmutable $start, DateTimeImmutable $end, bool $includeEmpty = true): array
    {
        $range = $this->bounds($start, $end);
        $salesQuery = $this->pdo->prepare(
            "SELECT strftime('%Y-%m-%d %H:00:00', created_at) AS utc_hour,
                    SUM(total_cents) AS revenue_cents, COUNT(*) AS sales_count
             FROM sales
             WHERE business_id = :business_id AND status = 'completed' AND created_at >= :start AND created_at < :end
             GROUP BY utc_hour ORDER BY utc_hour"
        );
        $salesQuery->execute(['business_id' => $this->businessId] + $range);
        $lineQuery = $this->pdo->prepare(
            "SELECT strftime('%Y-%m-%d %H:00:00', s.created_at) AS utc_hour,
                    COALESCE(SUM(CASE WHEN si.unit_cost_cents IS NOT NULL THEN si.unit_cost_cents * si.quantity ELSE 0 END), 0) AS cost_cents,
                    COALESCE(SUM(CASE WHEN si.unit_cost_cents IS NOT NULL THEN (si.unit_price_cents - si.unit_cost_cents) * si.quantity ELSE 0 END), 0) AS profit_cents,
                    COALESCE(SUM(CASE WHEN si.unit_cost_cents IS NOT NULL THEN 1 ELSE 0 END), 0) AS known_cost_lines,
                    COUNT(*) AS total_sale_lines
             FROM sale_items si JOIN sales s ON s.id = si.sale_id
             WHERE si.business_id = :business_id AND s.business_id = si.business_id
               AND s.status = 'completed' AND s.created_at >= :start AND s.created_at < :end
             GROUP BY utc_hour ORDER BY utc_hour"
        );
        $lineQuery->execute(['business_id' => $this->businessId] + $range);

        $daily = [];
        $monthly = [];
        foreach ($salesQuery->fetchAll() as $row) {
            $localDate = $this->localHour($row['utc_hour'])->format('Y-m-d');
            $daily[$localDate] ??= ['sales_count' => 0, 'revenue_cents' => 0, 'cost_cents' => 0, 'profit_cents' => 0, 'known_cost_lines' => 0, 'total_sale_lines' => 0];
            $daily[$localDate]['sales_count'] += (int) $row['sales_count'];
            $daily[$localDate]['revenue_cents'] += (int) $row['revenue_cents'];
            $monthKey = substr($localDate, 0, 7);
            $monthly[$monthKey] ??= ['sales_count' => 0, 'revenue_cents' => 0, 'cost_cents' => 0, 'profit_cents' => 0, 'known_cost_lines' => 0, 'total_sale_lines' => 0];
            $monthly[$monthKey]['sales_count'] += (int) $row['sales_count'];
            $monthly[$monthKey]['revenue_cents'] += (int) $row['revenue_cents'];
        }
        foreach ($lineQuery->fetchAll() as $row) {
            $localDate = $this->localHour($row['utc_hour'])->format('Y-m-d');
            $daily[$localDate] ??= ['sales_count' => 0, 'revenue_cents' => 0, 'cost_cents' => 0, 'profit_cents' => 0, 'known_cost_lines' => 0, 'total_sale_lines' => 0];
            $daily[$localDate]['cost_cents'] += (int) $row['cost_cents'];
            $daily[$localDate]['profit_cents'] += (int) $row['profit_cents'];
            $daily[$localDate]['known_cost_lines'] += (int) $row['known_cost_lines'];
            $daily[$localDate]['total_sale_lines'] += (int) $row['total_sale_lines'];
            $monthKey = substr($localDate, 0, 7);
            $monthly[$monthKey] ??= ['sales_count' => 0, 'revenue_cents' => 0, 'cost_cents' => 0, 'profit_cents' => 0, 'known_cost_lines' => 0, 'total_sale_lines' => 0];
            $monthly[$monthKey]['cost_cents'] += (int) $row['cost_cents'];
            $monthly[$monthKey]['profit_cents'] += (int) $row['profit_cents'];
            $monthly[$monthKey]['known_cost_lines'] += (int) $row['known_cost_lines'];
            $monthly[$monthKey]['total_sale_lines'] += (int) $row['total_sale_lines'];
        }

        $dailyRows = [];
        if ($includeEmpty) {
            for ($date = $start; $date < $end; $date = $date->modify('+1 day')) {
                $key = $date->format('Y-m-d');
                $dailyRows[] = ['date' => $key, 'label' => $date->format('d/m')] + ($daily[$key] ?? [
                    'sales_count' => 0,
                    'revenue_cents' => 0,
                    'cost_cents' => 0,
                    'profit_cents' => 0,
                    'known_cost_lines' => 0,
                    'total_sale_lines' => 0,
                ]);
            }
        } else {
            ksort($daily);
            foreach ($daily as $key => $values) {
                $date = DateTimeImmutable::createFromFormat('!Y-m-d', $key, $this->timezone);
                $dailyRows[] = ['date' => $key, 'label' => $date->format('d/m')] + $values;
            }
        }
        $monthlyRows = [];
        if ($includeEmpty) {
            $month = $start->modify('first day of this month');
            $lastMonth = $end->modify('-1 day')->modify('first day of this month');
            for (; $month <= $lastMonth; $month = $month->modify('+1 month')) {
                $key = $month->format('Y-m');
                $monthlyRows[] = ['date' => $key, 'label' => $month->format('m/Y')] + ($monthly[$key] ?? [
                    'sales_count' => 0,
                    'revenue_cents' => 0,
                    'cost_cents' => 0,
                    'profit_cents' => 0,
                    'known_cost_lines' => 0,
                    'total_sale_lines' => 0,
                ]);
            }
        } else {
            ksort($monthly);
            foreach ($monthly as $key => $values) {
                $month = DateTimeImmutable::createFromFormat('!Y-m-d', $key . '-01', $this->timezone);
                $monthlyRows[] = ['date' => $key, 'label' => $month->format('m/Y')] + $values;
            }
        }

        return ['daily' => $dailyRows, 'monthly' => $monthlyRows];
    }

    private function bestSellers(array $range, int $limit): array
    {
        $statement = $this->pdo->prepare(
            "SELECT si.product_id, si.product_name_snapshot AS name, si.sku_snapshot AS sku,
                    SUM(si.quantity) AS quantity, SUM(si.line_total_cents) AS revenue_cents,
                    COALESCE(SUM(CASE WHEN si.unit_cost_cents IS NOT NULL THEN si.unit_cost_cents * si.quantity ELSE 0 END), 0) AS cost_cents,
                    COALESCE(SUM(CASE WHEN si.unit_cost_cents IS NOT NULL THEN (si.unit_price_cents - si.unit_cost_cents) * si.quantity ELSE 0 END), 0) AS profit_cents,
                    SUM(CASE WHEN si.unit_cost_cents IS NOT NULL THEN 1 ELSE 0 END) AS known_cost_lines,
                    COUNT(*) AS total_sale_lines
             FROM sale_items si JOIN sales s ON s.id = si.sale_id
             WHERE si.business_id = :business_id AND s.business_id = si.business_id
               AND s.status = 'completed' AND s.created_at >= :start AND s.created_at < :end
             GROUP BY si.product_id, si.product_name_snapshot, si.sku_snapshot
             ORDER BY quantity DESC, revenue_cents DESC, name COLLATE NOCASE
             LIMIT :limit"
        );
        $statement->bindValue('business_id', $this->businessId, PDO::PARAM_INT);
        $statement->bindValue('start', $range['start']);
        $statement->bindValue('end', $range['end']);
        $statement->bindValue('limit', $limit, PDO::PARAM_INT);
        $statement->execute();

        return $statement->fetchAll();
    }

    private function customerBreakdown(array $range, int $limit): array
    {
        $statement = $this->pdo->prepare(
            "SELECT client_name_snapshot AS name, COUNT(*) AS sales_count, SUM(total_cents) AS revenue_cents
             FROM sales
             WHERE business_id = :business_id AND status = 'completed' AND created_at >= :start AND created_at < :end
             GROUP BY client_id, client_name_snapshot
             ORDER BY revenue_cents DESC, sales_count DESC, name COLLATE NOCASE
             LIMIT :limit"
        );
        $statement->bindValue('business_id', $this->businessId, PDO::PARAM_INT);
        $statement->bindValue('start', $range['start']);
        $statement->bindValue('end', $range['end']);
        $statement->bindValue('limit', $limit, PDO::PARAM_INT);
        $statement->execute();

        return $statement->fetchAll();
    }

    private function movementTotals(array $range): array
    {
        $statement = $this->pdo->prepare(
            "SELECT
                COUNT(*) AS movement_count,
                COALESCE(SUM(CASE WHEN movement_type IN ('in', 'sale_return') THEN quantity
                                  WHEN movement_type = 'correction' AND stock_after > stock_before THEN quantity
                                  ELSE 0 END), 0) AS entries_units,
                COALESCE(SUM(CASE WHEN movement_type IN ('out', 'sale') THEN quantity
                                  WHEN movement_type = 'correction' AND stock_after < stock_before THEN quantity
                                  ELSE 0 END), 0) AS exits_units,
                COALESCE(SUM(CASE WHEN movement_type = 'correction' THEN 1 ELSE 0 END), 0) AS corrections_count,
                COALESCE(SUM(CASE WHEN movement_type = 'correction' THEN quantity ELSE 0 END), 0) AS corrections_units
             FROM stock_movements
             WHERE business_id = :business_id AND created_at >= :start AND created_at < :end"
        );
        $statement->execute(['business_id' => $this->businessId] + $range);

        return array_map('intval', $statement->fetch());
    }

    private function recentSales(): array
    {
        $statement = $this->pdo->prepare('SELECT * FROM sales WHERE business_id = :business_id ORDER BY created_at DESC, id DESC LIMIT 5');
        $statement->execute(['business_id' => $this->businessId]);

        return $statement->fetchAll();
    }

    private function bounds(DateTimeImmutable $start, DateTimeImmutable $end): array
    {
        return ['start' => $this->utc($start), 'end' => $this->utc($end)];
    }

    private function utc(DateTimeImmutable $date): string
    {
        return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    private function localHour(string $hour): DateTimeImmutable
    {
        return new DateTimeImmutable($hour, new DateTimeZone('UTC'))->setTimezone($this->timezone);
    }
}
