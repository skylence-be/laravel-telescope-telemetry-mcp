<?php

namespace LaravelTelescope\Telemetry\Services;

class AggregationService
{
    protected array $config;
    
    public function __construct(array $config)
    {
        $this->config = $config;
    }
    
    /**
     * Aggregate data with multiple statistical measures.
     */
    public function aggregate(array $data, string $field = 'duration'): array
    {
        if (empty($data)) {
            return $this->emptyAggregation();
        }
        
        $values = $this->extractValues($data, $field);
        
        return [
            'count' => count($values),
            'sum' => array_sum($values),
            'avg' => $this->average($values),
            'min' => min($values),
            'max' => max($values),
            'range' => max($values) - min($values),
            'median' => $this->median($values),
            'mode' => $this->mode($values),
            'std_dev' => $this->standardDeviation($values),
            'variance' => $this->variance($values),
            'percentiles' => $this->calculatePercentiles($values),
            'distribution' => $this->calculateDistribution($values),
        ];
    }
    
    /**
     * Calculate percentiles based on configuration.
     */
    public function calculatePercentiles(array $values): array
    {
        $percentiles = $this->config['percentiles'] ?? [50, 95, 99];
        $result = [];
        
        foreach ($percentiles as $p) {
            $result["p{$p}"] = $this->percentile($values, $p);
        }
        
        return $result;
    }
    
    /**
     * Calculate time-window aggregations.
     */
    public function aggregateByTimeWindow(array $data, string $field = 'created_at'): array
    {
        $windows = $this->config['time_windows'] ?? [
            'recent' => 300,
            'short' => 3600,
            'medium' => 86400,
            'long' => 604800,
        ];
        
        $now = time();
        $result = [];
        
        foreach ($windows as $name => $seconds) {
            $cutoff = $now - $seconds;
            $windowData = array_filter($data, function ($item) use ($field, $cutoff) {
                $timestamp = strtotime($item[$field] ?? $item['content'][$field] ?? '');
                return $timestamp >= $cutoff;
            });
            
            $result[$name] = [
                'count' => count($windowData),
                'window_seconds' => $seconds,
                'window_label' => $this->formatTimeWindow($seconds),
                'data' => $this->aggregate($windowData),
            ];
        }
        
        return $result;
    }
    
    /**
     * Group data by a field and aggregate.
     */
    public function groupByAndAggregate(array $data, string $groupField, string $aggregateField = 'duration'): array
    {
        $groups = $this->groupBy($data, $groupField);
        $result = [];
        
        foreach ($groups as $key => $groupData) {
            $result[$key] = [
                'group' => $key,
                'count' => count($groupData),
                'aggregation' => $this->aggregate($groupData, $aggregateField),
            ];
        }
        
        // Sort by count descending
        uasort($result, fn($a, $b) => $b['count'] <=> $a['count']);
        
        return $result;
    }
    
    /**
     * Calculate trend over time periods.
     */
    public function calculateTrend(array $data, string $dateField = 'created_at', string $valueField = 'duration'): array
    {
        if (count($data) < 2) {
            return ['trend' => 'insufficient_data'];
        }
        
        // Sort by date
        usort($data, function ($a, $b) use ($dateField) {
            $timeA = strtotime($a[$dateField] ?? $a['content'][$dateField] ?? '');
            $timeB = strtotime($b[$dateField] ?? $b['content'][$dateField] ?? '');
            return $timeA <=> $timeB;
        });
        
        // Split into equal time periods
        $periods = $this->splitIntoPeriods($data, 5);
        $periodAverages = [];
        
        foreach ($periods as $period) {
            $values = $this->extractValues($period, $valueField);
            $periodAverages[] = $this->average($values);
        }
        
        // Calculate trend direction
        $trend = $this->determineTrend($periodAverages);
        
        return [
            'trend' => $trend,
            'period_count' => count($periods),
            'period_averages' => $periodAverages,
            'change_rate' => $this->calculateChangeRate($periodAverages),
            'correlation' => $this->calculateCorrelation($periodAverages),
        ];
    }
    
    /**
     * Detect anomalies using statistical methods.
     */
    public function detectAnomalies(array $data, string $field = 'duration', float $threshold = 2.0): array
    {
        $values = $this->extractValues($data, $field);
        
        if (count($values) < 3) {
            return [];
        }
        
        $mean = $this->average($values);
        $stdDev = $this->standardDeviation($values);
        $anomalies = [];
        
        foreach ($data as $index => $item) {
            $value = $this->extractValue($item, $field);
            $zScore = abs(($value - $mean) / $stdDev);
            
            if ($zScore > $threshold) {
                $anomalies[] = [
                    'index' => $index,
                    'value' => $value,
                    'z_score' => round($zScore, 2),
                    'deviation_from_mean' => round($value - $mean, 2),
                    'item' => $item,
                ];
            }
        }
        
        return $anomalies;
    }
    
    /**
     * Calculate correlation between two fields.
     */
    public function correlate(array $data, string $field1, string $field2): float
    {
        $values1 = $this->extractValues($data, $field1);
        $values2 = $this->extractValues($data, $field2);
        
        if (count($values1) !== count($values2) || count($values1) < 2) {
            return 0;
        }
        
        return $this->pearsonCorrelation($values1, $values2);
    }
    
    /**
     * Create histogram buckets for distribution analysis.
     */
    public function createHistogram(array $data, string $field = 'duration', int $buckets = 10): array
    {
        $values = $this->extractValues($data, $field);
        
        if (empty($values)) {
            return [];
        }
        
        $min = min($values);
        $max = max($values);
        $range = $max - $min;
        $bucketSize = $range / $buckets;
        
        $histogram = [];
        
        for ($i = 0; $i < $buckets; $i++) {
            $bucketMin = $min + ($i * $bucketSize);
            $bucketMax = $min + (($i + 1) * $bucketSize);
            
            $histogram[] = [
                'bucket' => $i,
                'min' => round($bucketMin, 2),
                'max' => round($bucketMax, 2),
                'count' => 0,
                'percentage' => 0,
            ];
        }
        
        foreach ($values as $value) {
            $bucketIndex = min((int)(($value - $min) / $bucketSize), $buckets - 1);
            $histogram[$bucketIndex]['count']++;
        }
        
        $total = count($values);
        foreach ($histogram as &$bucket) {
            $bucket['percentage'] = round(($bucket['count'] / $total) * 100, 2);
        }
        
        return $histogram;
    }
    
    /**
     * Helper Methods
     */
    
    protected function extractValues(array $data, string $field): array
    {
        return array_map(function ($item) use ($field) {
            return $this->extractValue($item, $field);
        }, $data);
    }
    
    protected function extractValue($item, string $field)
    {
        if (isset($item[$field])) {
            return $item[$field];
        }
        
        if (isset($item['content'][$field])) {
            return $item['content'][$field];
        }
        
        $keys = explode('.', $field);
        $value = $item;
        
        foreach ($keys as $key) {
            if (!isset($value[$key])) {
                return 0;
            }
            $value = $value[$key];
        }
        
        return $value;
    }
    
    protected function average(array $values): float
    {
        if (empty($values)) return 0;
        return array_sum($values) / count($values);
    }
    
    protected function median(array $values): float
    {
        if (empty($values)) return 0;
        
        sort($values);
        $count = count($values);
        
        if ($count % 2 === 0) {
            return ($values[$count / 2 - 1] + $values[$count / 2]) / 2;
        }
        
        return $values[floor($count / 2)];
    }
    
    protected function mode(array $values)
    {
        if (empty($values)) return null;
        
        $frequencies = array_count_values($values);
        $maxFrequency = max($frequencies);
        
        $modes = array_keys($frequencies, $maxFrequency);
        
        return count($modes) === 1 ? $modes[0] : $modes;
    }
    
    protected function variance(array $values): float
    {
        if (empty($values)) return 0;
        
        $mean = $this->average($values);
        $sumSquared = array_sum(array_map(fn($x) => pow($x - $mean, 2), $values));
        
        return $sumSquared / count($values);
    }
    
    protected function standardDeviation(array $values): float
    {
        return sqrt($this->variance($values));
    }
    
    protected function percentile(array $values, int $percentile): float
    {
        if (empty($values)) return 0;
        
        sort($values);
        $index = ceil(($percentile / 100) * count($values)) - 1;
        
        return $values[$index] ?? 0;
    }
    
    protected function calculateDistribution(array $values): array
    {
        if (empty($values)) {
            return [];
        }
        
        return [
            'q1' => $this->percentile($values, 25),
            'q2' => $this->percentile($values, 50),
            'q3' => $this->percentile($values, 75),
            'iqr' => $this->percentile($values, 75) - $this->percentile($values, 25),
            'outlier_threshold_low' => $this->percentile($values, 25) - (1.5 * ($this->percentile($values, 75) - $this->percentile($values, 25))),
            'outlier_threshold_high' => $this->percentile($values, 75) + (1.5 * ($this->percentile($values, 75) - $this->percentile($values, 25))),
        ];
    }
    
    protected function groupBy(array $data, string $field): array
    {
        $groups = [];
        
        foreach ($data as $item) {
            $key = $this->extractValue($item, $field);
            
            if (!isset($groups[$key])) {
                $groups[$key] = [];
            }
            
            $groups[$key][] = $item;
        }
        
        return $groups;
    }
    
    protected function splitIntoPeriods(array $data, int $periods): array
    {
        $total = count($data);
        $perPeriod = ceil($total / $periods);
        
        return array_chunk($data, $perPeriod);
    }
    
    protected function determineTrend(array $values): string
    {
        if (count($values) < 2) {
            return 'insufficient_data';
        }
        
        $first = array_slice($values, 0, floor(count($values) / 2));
        $second = array_slice($values, floor(count($values) / 2));
        
        $firstAvg = $this->average($first);
        $secondAvg = $this->average($second);
        
        $change = (($secondAvg - $firstAvg) / $firstAvg) * 100;
        
        if ($change > 10) return 'increasing';
        if ($change < -10) return 'decreasing';
        return 'stable';
    }
    
    protected function calculateChangeRate(array $values): float
    {
        if (count($values) < 2) return 0;
        
        $first = $values[0];
        $last = $values[count($values) - 1];
        
        if ($first == 0) return 0;
        
        return round((($last - $first) / $first) * 100, 2);
    }
    
    protected function calculateCorrelation(array $values): float
    {
        if (count($values) < 2) return 0;
        
        $x = range(0, count($values) - 1);
        
        return $this->pearsonCorrelation($x, $values);
    }
    
    protected function pearsonCorrelation(array $x, array $y): float
    {
        $n = count($x);
        
        if ($n !== count($y) || $n < 2) {
            return 0;
        }
        
        $sumX = array_sum($x);
        $sumY = array_sum($y);
        $sumXY = array_sum(array_map(fn($i) => $x[$i] * $y[$i], array_keys($x)));
        $sumX2 = array_sum(array_map(fn($val) => $val * $val, $x));
        $sumY2 = array_sum(array_map(fn($val) => $val * $val, $y));
        
        $numerator = ($n * $sumXY) - ($sumX * $sumY);
        $denominator = sqrt((($n * $sumX2) - ($sumX * $sumX)) * (($n * $sumY2) - ($sumY * $sumY)));
        
        if ($denominator == 0) {
            return 0;
        }
        
        return round($numerator / $denominator, 4);
    }
    
    protected function formatTimeWindow(int $seconds): string
    {
        if ($seconds < 3600) {
            return round($seconds / 60) . ' minutes';
        } elseif ($seconds < 86400) {
            return round($seconds / 3600) . ' hours';
        } else {
            return round($seconds / 86400) . ' days';
        }
    }
    
    protected function emptyAggregation(): array
    {
        return [
            'count' => 0,
            'sum' => 0,
            'avg' => 0,
            'min' => 0,
            'max' => 0,
            'range' => 0,
            'median' => 0,
            'mode' => null,
            'std_dev' => 0,
            'variance' => 0,
            'percentiles' => [],
            'distribution' => [],
        ];
    }
}
