<?php
class FXRateService
{
    private $db;
    private const DEFAULT_BASE = 'USD';
    private const CACHE_DURATION_MINUTES = 60;

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function getRate(string $from, string $to): float
    {
        $from = strtoupper($from);
        $to = strtoupper($to);

        if ($from === $to) {
            return 1.0;
        }

        $stmt = $this->db->prepare("
            SELECT rate FROM exchange_rates
            WHERE base_currency = ? AND target_currency = ?
            AND is_active = 1
            AND (valid_until IS NULL OR valid_until > CURRENT_TIMESTAMP)
            ORDER BY valid_from DESC
            LIMIT 1
        ");
        $stmt->execute([$from, $to]);
        $rate = $stmt->fetchColumn();

        if ($rate !== false) {
            return (float)$rate;
        }

        $stmt = $this->db->prepare("
            SELECT rate FROM exchange_rates
            WHERE base_currency = ? AND target_currency = ?
            AND is_active = 1
            AND (valid_until IS NULL OR valid_until > CURRENT_TIMESTAMP)
            ORDER BY valid_from DESC
            LIMIT 1
        ");
        $stmt->execute([$to, $from]);
        $reverseRate = $stmt->fetchColumn();

        if ($reverseRate !== false && (float)$reverseRate > 0) {
            return 1.0 / (float)$reverseRate;
        }

        if ($from !== self::DEFAULT_BASE) {
            $stmt = $this->db->prepare("
                SELECT rate FROM exchange_rates
                WHERE base_currency = ? AND target_currency = ?
                AND is_active = 1
                ORDER BY valid_from DESC LIMIT 1
            ");
            $stmt->execute([self::DEFAULT_BASE, $from]);
            $fromToUsd = $stmt->fetchColumn();

            if ($fromToUsd === false) {
                return $this->getFallbackRate($from, $to);
            }

            $stmt = $this->db->prepare("
                SELECT rate FROM exchange_rates
                WHERE base_currency = ? AND target_currency = ?
                AND is_active = 1
                ORDER BY valid_from DESC LIMIT 1
            ");
            $stmt->execute([self::DEFAULT_BASE, $to]);
            $toUsd = $stmt->fetchColumn();

            if ($toUsd !== false) {
                return (float)$toUsd / (float)$fromToUsd;
            }
        }

        return $this->getFallbackRate($from, $to);
    }

    public function convertAmount(int $amountCents, string $fromCurrency, string $toCurrency): int
    {
        $fromCurrency = strtoupper($fromCurrency);
        $toCurrency = strtoupper($toCurrency);

        if ($fromCurrency === $toCurrency) {
            return $amountCents;
        }

        $rate = $this->getRate($fromCurrency, $toCurrency);

        $zeroDecimal = ['JPY', 'KRW', 'BIF', 'CLP', 'DJF', 'GNF', 'KMF', 'MGA', 'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF'];
        $fromDivisor = in_array($fromCurrency, $zeroDecimal) ? 1 : 100;
        $toDivisor = in_array($toCurrency, $zeroDecimal) ? 1 : 100;

        $amountInBase = $amountCents / $fromDivisor;
        $convertedAmount = $amountInBase * $rate;
        return (int)round($convertedAmount * $toDivisor);
    }

    public function updateRate(string $from, string $to, float $rate, string $source = 'manual'): bool
    {
        $id = 'fx_' . bin2hex(random_bytes(8));
        $stmt = $this->db->prepare("
            INSERT INTO exchange_rates (id, base_currency, target_currency, rate, source, valid_from, is_active)
            VALUES (?, ?, ?, ?, ?, CURRENT_TIMESTAMP, 1)
        ");
        return $stmt->execute([$id, strtoupper($from), strtoupper($to), $rate, $source]);
    }

    public function getAllRates(string $base = null): array
    {
        $base = $base ? strtoupper($base) : self::DEFAULT_BASE;
        $stmt = $this->db->prepare("
            SELECT target_currency, rate, source, valid_from
            FROM exchange_rates
            WHERE base_currency = ? AND is_active = 1
            AND (valid_until IS NULL OR valid_until > CURRENT_TIMESTAMP)
            ORDER BY target_currency ASC
        ");
        $stmt->execute([$base]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getPriceForCohort(string $cohortId, string $currency, int $defaultPriceCents = null): array
    {
        $currency = strtoupper($currency);

        $stmt = $this->db->prepare("
            SELECT amount, is_active FROM cohort_prices
            WHERE cohort_id = ? AND currency = ? AND is_active = 1
            LIMIT 1
        ");
        $stmt->execute([$cohortId, $currency]);
        $price = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($price) {
            return [
                'amount' => (int)$price['amount'],
                'currency' => $currency,
                'source' => 'explicit'
            ];
        }

        $basePrice = null;
        if ($defaultPriceCents !== null) {
            $basePrice = $defaultPriceCents;
        } else {
            $stmt = $this->db->prepare("SELECT price FROM cohorts WHERE id = ?");
            $stmt->execute([$cohortId]);
            $cohort = $stmt->fetch(PDO::FETCH_ASSOC);
            $basePrice = $cohort ? (int)$cohort['price'] : null;
        }

        if ($basePrice === null) {
            return ['amount' => 0, 'currency' => $currency, 'source' => 'none'];
        }

        if ($currency === 'USD') {
            return ['amount' => $basePrice, 'currency' => 'USD', 'source' => 'converted'];
        }

        $convertedAmount = $this->convertAmount($basePrice, 'USD', $currency);
        return [
            'amount' => $convertedAmount,
            'currency' => $currency,
            'source' => 'converted',
            'base_amount' => $basePrice,
            'base_currency' => 'USD',
            'rate' => $this->getRate('USD', $currency)
        ];
    }

    public function getAllCohortPrices(string $cohortId): array
    {
        $stmt = $this->db->prepare("
            SELECT currency, amount FROM cohort_prices
            WHERE cohort_id = ? AND is_active = 1
            ORDER BY currency ASC
        ");
        $stmt->execute([$cohortId]);
        $prices = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $stmt = $this->db->prepare("SELECT price FROM cohorts WHERE id = ?");
        $stmt->execute([$cohortId]);
        $cohort = $stmt->fetch(PDO::FETCH_ASSOC);

        $result = [];
        if ($cohort) {
            $result['USD'] = ['amount' => (int)$cohort['price'], 'source' => 'base'];
        }

        foreach ($prices as $p) {
            $result[$p['currency']] = ['amount' => (int)$p['amount'], 'source' => 'explicit'];
        }

        return $result;
    }

    public function updateCohortPrice(string $cohortId, string $currency, int $amountCents): bool
    {
        $id = 'cp_' . bin2hex(random_bytes(8));
        $stmt = $this->db->prepare("
            INSERT INTO cohort_prices (id, cohort_id, currency, amount, is_active, created_at, updated_at)
            VALUES (?, ?, ?, ?, 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
            ON CONFLICT(cohort_id, currency) DO UPDATE SET amount = ?, updated_at = CURRENT_TIMESTAMP
        ");
        return $stmt->execute([$id, $cohortId, strtoupper($currency), $amountCents, $amountCents]);
    }

    public function fetchLiveRates(string $apiKey = null): array
    {
        $results = ['success' => 0, 'failed' => 0, 'errors' => []];

        $frankfurterRates = $this->fetchFromFrankfurter();
        if (!empty($frankfurterRates)) {
            $this->saveRates($frankfurterRates, 'frankfurter');
            $results['success'] = count($frankfurterRates);
            $results['source'] = 'frankfurter';
            return $results;
        }

        if ($apiKey) {
            $apiRates = $this->fetchFromExchangeRateApi($apiKey);
            if (!empty($apiRates)) {
                $this->saveRates($apiRates, 'exchange_rate_api');
                $results['success'] = count($apiRates);
                $results['source'] = 'exchange_rate_api';
                return $results;
            }
        }

        $results['failed'] = 1;
        $results['errors'] = ['Failed to fetch live rates from all sources'];
        return $results;
    }

    private function fetchFromFrankfurter(): array
    {
        $rates = [];
        try {
            $context = stream_context_create([
                'http' => ['timeout' => 10, 'ignore_errors' => true]
            ]);
            $response = @file_get_contents('https://api.frankfurter.app/latest?from=USD', false, $context);
            
            if ($response) {
                $data = json_decode($response, true);
                if (isset($data['rates']) && is_array($data['rates'])) {
                    foreach ($data['rates'] as $currency => $rate) {
                        if ($currency !== 'EUR') {
                            $rates[$currency] = $rate;
                        }
                    }
                    $rates['USD'] = 1.0;
                }
            }
        } catch (Exception $e) {
            error_log('Frankfurter API error: ' . $e->getMessage());
        }
        return $rates;
    }

    private function fetchFromExchangeRateApi(string $apiKey): array
    {
        $rates = [];
        try {
            $context = stream_context_create([
                'http' => ['timeout' => 10, 'ignore_errors' => true]
            ]);
            $url = "https://open.er-api.com/v6/latest/USD";
            $response = @file_get_contents($url, false, $context);
            
            if ($response) {
                $data = json_decode($response, true);
                if (isset($data['rates']) && is_array($data['rates'])) {
                    foreach ($data['rates'] as $currency => $rate) {
                        if ($currency !== 'USD') {
                            $rates[$currency] = $rate;
                        }
                    }
                }
            }
        } catch (Exception $e) {
            error_log('ExchangeRate-API error: ' . $e->getMessage());
        }
        return $rates;
    }

    private function saveRates(array $rates, string $source): void
    {
        $baseCurrency = 'USD';
        
        foreach ($rates as $currency => $rate) {
            if ($currency === $baseCurrency || !is_numeric($rate) || $rate <= 0) {
                continue;
            }

            $id = 'fx_' . bin2hex(random_bytes(6));
            $stmt = $this->db->prepare("
                INSERT INTO exchange_rates (id, base_currency, target_currency, rate, source, valid_from, valid_until, is_active)
                VALUES (?, ?, ?, ?, ?, CURRENT_TIMESTAMP, DATE_ADD(CURRENT_TIMESTAMP, INTERVAL 24 HOUR), 1)
                ON DUPLICATE KEY UPDATE rate = VALUES(rate), source = VALUES(source), 
                    valid_from = CURRENT_TIMESTAMP, valid_until = DATE_ADD(CURRENT_TIMESTAMP, INTERVAL 24 HOUR), is_active = 1
            ");
            $stmt->execute([$id, $baseCurrency, strtoupper($currency), $rate, $source]);
        }

        $stmt = $this->db->prepare("
            UPDATE exchange_rates 
            SET is_active = 0 
            WHERE valid_until < CURRENT_TIMESTAMP AND valid_until IS NOT NULL
        ");
        $stmt->execute();
    }

    public function getRateSource(): ?string
    {
        $stmt = $this->db->query("
            SELECT source FROM exchange_rates 
            WHERE base_currency = 'USD' AND is_active = 1 
            ORDER BY valid_from DESC LIMIT 1
        ");
        return $stmt->fetchColumn() ?: null;
    }

    private function getFallbackRate(string $from, string $to): float
    {
        $fallbackRates = [
            'USD' => ['NGN' => 1550, 'GHS' => 15.5, 'KES' => 129, 'UGX' => 3750, 'TZS' => 2650,
                       'RWF' => 1350, 'ZAR' => 18.5, 'EGP' => 50, 'MAD' => 10, 'GBP' => 0.79,
                       'EUR' => 0.92, 'AED' => 3.67, 'INR' => 83, 'CNY' => 7.2, 'AUD' => 1.53,
                       'CAD' => 1.36, 'JPY' => 155, 'KRW' => 1350, 'BIF' => 2850, 'CDF' => 2800,
                       'DJF' => 177, 'ERN' => 15, 'ETB' => 57, 'GMD' => 71, 'GNF' => 8600,
                       'LRD' => 190, 'LSL' => 18.5, 'MGA' => 4500, 'MWK' => 1730, 'MUR' => 46,
                       'MZN' => 64, 'NAD' => 18.5, 'SCR' => 13.5, 'SLL' => 22000, 'SOS' => 570,
                       'SZL' => 18.5, 'TND' => 3.1, 'ZMW' => 26, 'BHD' => 0.376, 'KWD' => 0.307,
                       'OMR' => 0.385, 'QAR' => 3.64, 'SAR' => 3.75, 'CFA' => 605, 'XOF' => 605,
                       'XAF' => 605],
        ];

        if (isset($fallbackRates[$from][$to])) {
            return $fallbackRates[$from][$to];
        }

        if ($from !== 'USD' && isset($fallbackRates['USD'][$from])) {
            $fromToUsd = $fallbackRates['USD'][$from];
            if (isset($fallbackRates['USD'][$to])) {
                return $fallbackRates['USD'][$to] / $fromToUsd;
            }
        }

        if (isset($fallbackRates['USD'][$to]) && $from === 'USD') {
            return $fallbackRates['USD'][$to];
        }

        return 1.0;
    }
}
