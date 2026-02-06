<?php
/**
 * File: app/Integrations/GapGPT/OutputPostProcessors.php
 *
 * CRM V2 - GapGPT Output Post-Processors
 * -----------------------------------------------------------------------------
 * این فایل برای «کنترل کیفیت خروجی AI» است.
 *
 * چرا لازم است؟
 * - مدل‌ها گاهی:
 *   - قبل/بعد JSON متن اضافی می‌نویسند
 *   - اعداد را با ویرگول/فارسی/متن برمی‌گردانند
 *   - فاصله‌ها، کاراکترهای عجیب یا کلیدهای اضافی تولید می‌کنند
 *
 * این PostProcessorها کمک می‌کنند خروجی:
 * - قابل ذخیره در DB
 * - قابل نمایش در UI
 * - قابل استفاده برای اتوماسیون‌ها
 * باشد.
 *
 * Contract:
 * - AIScenarios::parseOutput() یک آرایه result تولید می‌کند:
 *   [
 *     'ok' => bool,
 *     'format' => 'json'|'text',
 *     'data' => mixed|null,
 *     'text' => string,
 *     'raw' => array|null,
 *     'warnings' => array,
 *   ]
 *
 * - این کلاس روی همین result عمل می‌کند و آن را بهبود می‌دهد.
 */

declare(strict_types=1);

namespace App\Integrations\GapGPT;

final class OutputPostProcessors
{
    /**
     * Apply post-processor by key.
     *
     * @param string $key
     * @param array<string,mixed> $result
     * @return array<string,mixed>
     */
    public static function apply(string $key, array $result): array
    {
        $key = trim(strtolower($key));

        switch ($key) {
            case 'json_sanitize':
                return self::ppJsonSanitize($result);

            case 'trim_fields':
                return self::ppTrimFields($result);

            case 'numbers_normalize':
                return self::ppNumbersNormalize($result);

            case 'remove_nulls':
                return self::ppRemoveNulls($result);

            case 'limit_lengths':
                return self::ppLimitLengths($result);

            case 'keys_snakecase':
                return self::ppKeysSnakeCase($result);

            case 'strip_markdown':
                return self::ppStripMarkdown($result);

            default:
                // unknown processor: do nothing but warn
                $result['warnings'] = self::addWarning($result['warnings'] ?? [], "Unknown post-processor: {$key}");
                return $result;
        }
    }

    // =============================================================================
    // Post processors
    // =============================================================================

    /**
     * Sanitize JSON:
     * - اگر format=json و data=null است، تلاش می‌کند از text JSON را استخراج کند
     * - اگر data آرایه است، رشته‌های آن را تمیز می‌کند
     */
    private static function ppJsonSanitize(array $result): array
    {
        $format = (string)($result['format'] ?? 'text');

        if ($format !== 'json') {
            return $result;
        }

        $data = $result['data'] ?? null;
        $text = (string)($result['text'] ?? '');

        if (!is_array($data)) {
            $decoded = self::decodeJsonLenient($text);
            if (is_array($decoded)) {
                $result['data'] = $decoded;
                $result['ok'] = true;
                $result['warnings'] = self::addWarning($result['warnings'] ?? [], 'JSON was repaired via lenient decode.');
            } else {
                $result['ok'] = false;
                $result['warnings'] = self::addWarning($result['warnings'] ?? [], 'Failed to parse JSON output.');
                return $result;
            }
        }

        // deep trim strings
        $result['data'] = self::deepTrim($result['data']);

        return $result;
    }

    /**
     * Trim fields:
     * - همه رشته‌ها trim
     * - حذف تکرار فاصله‌ها
     */
    private static function ppTrimFields(array $result): array
    {
        $data = $result['data'] ?? null;

        if (is_array($data)) {
            $data = self::deepTrim($data);
            $data = self::deepCollapseSpaces($data);
            $result['data'] = $data;
        } else {
            // for text mode, trim text
            $result['text'] = trim((string)($result['text'] ?? ''));
        }

        return $result;
    }

    /**
     * Normalize numbers:
     * - تبدیل اعداد فارسی/عربی به انگلیسی
     * - حذف جداکننده هزارگان
     * - تبدیل رشته‌های عددی به عدد (int/float)
     *
     * نکته:
     * - همه‌چیز را عدد نمی‌کنیم (مثلاً SKU ممکن است "0012" باشد و نباید عدد شود)
     * - بنابراین فقط روی کلیدهایی که "قیمت/جمع/مقدار/امتیاز" هستند حساس‌تر عمل می‌کنیم
     */
    private static function ppNumbersNormalize(array $result): array
    {
        $data = $result['data'] ?? null;

        if (!is_array($data)) {
            return $result;
        }

        $data = self::deepNormalizeNumbers($data);

        $result['data'] = $data;
        return $result;
    }

    /**
     * Remove null values recursively.
     */
    private static function ppRemoveNulls(array $result): array
    {
        $data = $result['data'] ?? null;
        if (!is_array($data)) return $result;

        $result['data'] = self::deepRemoveNulls($data);
        return $result;
    }

    /**
     * Limit lengths to keep UI/DB safe.
     */
    private static function ppLimitLengths(array $result): array
    {
        $data = $result['data'] ?? null;

        // default limits
        $maxStr = 5000;
        $maxArr = 200; // max items

        if (is_array($data)) {
            $result['data'] = self::deepLimit($data, $maxStr, $maxArr);
        } else {
            $result['text'] = self::truncate((string)($result['text'] ?? ''), $maxStr);
        }

        return $result;
    }

    /**
     * Convert keys to snake_case (optional)
     */
    private static function ppKeysSnakeCase(array $result): array
    {
        $data = $result['data'] ?? null;
        if (!is_array($data)) return $result;

        $result['data'] = self::deepKeysSnakeCase($data);
        return $result;
    }

    /**
     * Remove markdown wrappers from text outputs
     */
    private static function ppStripMarkdown(array $result): array
    {
        $txt = (string)($result['text'] ?? '');
        if ($txt === '') return $result;

        // remove triple backticks blocks
        $txt = preg_replace('/```[a-zA-Z0-9_-]*\s*/', '', $txt) ?? $txt;
        $txt = str_replace('```', '', $txt);

        // remove leading bullets markdown
        $txt = preg_replace('/^\s*[-*]\s+/m', '', $txt) ?? $txt;

        $result['text'] = trim($txt);
        return $result;
    }

    // =============================================================================
    // Deep helpers
    // =============================================================================

    /**
     * @param mixed $v
     * @return mixed
     */
    private static function deepTrim($v)
    {
        if (is_string($v)) {
            return trim($v);
        }
        if (is_array($v)) {
            $out = [];
            foreach ($v as $k => $val) {
                $out[$k] = self::deepTrim($val);
            }
            return $out;
        }
        return $v;
    }

    /**
     * @param mixed $v
     * @return mixed
     */
    private static function deepCollapseSpaces($v)
    {
        if (is_string($v)) {
            // Replace multiple whitespace with single space
            $v = preg_replace('/\s+/u', ' ', $v) ?? $v;
            return trim($v);
        }
        if (is_array($v)) {
            foreach ($v as $k => $val) {
                $v[$k] = self::deepCollapseSpaces($val);
            }
            return $v;
        }
        return $v;
    }

    /**
     * Normalize numeric strings safely.
     * @param mixed $v
     * @return mixed
     */
    private static function deepNormalizeNumbers($v)
    {
        if (is_array($v)) {
            $out = [];
            foreach ($v as $k => $val) {
                $out[$k] = self::deepNormalizeNumbersWithKey($k, $val);
            }
            return $out;
        }
        return $v;
    }

    /**
     * @param mixed $key
     * @param mixed $val
     * @return mixed
     */
    private static function deepNormalizeNumbersWithKey($key, $val)
    {
        if (is_array($val)) {
            $out = [];
            foreach ($val as $k2 => $v2) {
                $out[$k2] = self::deepNormalizeNumbersWithKey($k2, $v2);
            }
            return $out;
        }

        if (!is_string($val)) {
            return $val;
        }

        $k = is_string($key) ? strtolower($key) : '';

        // only normalize numbers in fields likely to be numeric
        $likelyNumeric = self::keyLooksNumeric($k);

        // convert Persian/Arabic digits
        $s = self::faDigitsToEn($val);

        // Remove thousands separators (comma, Arabic comma, space)
        $s2 = str_replace([',', '٬', ' '], '', $s);
        $s2 = trim($s2);

        // If not likely numeric, return cleaned string only
        if (!$likelyNumeric) {
            // still return cleaned digits (but keep original formatting if not numeric)
            return trim($s);
        }

        // numeric conversion
        if ($s2 === '') return $s2;

        // handle percent e.g. "12%" -> 12
        $percent = false;
        if (str_ends_with($s2, '%')) {
            $percent = true;
            $s2 = rtrim($s2, '%');
        }

        if (!is_numeric($s2)) {
            return trim($s);
        }

        // If it contains dot => float
        $num = (strpos($s2, '.') !== false) ? (float)$s2 : (int)$s2;
        if ($percent) {
            // keep percent as number (not divide by 100) - you can change policy later
            return $num;
        }

        return $num;
    }

    private static function keyLooksNumeric(string $k): bool
    {
        // common numeric keys
        $needles = [
            'price', 'amount', 'total', 'subtotal', 'tax', 'discount',
            'qty', 'quantity', 'count', 'score', 'risk_score',
            'unit_price', 'grand_total', 'shipping', 'margin', 'cost',
        ];

        foreach ($needles as $n) {
            if ($k === $n) return true;
            if (str_contains($k, $n)) return true;
        }

        return false;
    }

    /**
     * @param array<string,mixed> $arr
     * @return array<string,mixed>
     */
    private static function deepRemoveNulls(array $arr): array
    {
        $out = [];
        foreach ($arr as $k => $v) {
            if ($v === null) continue;
            if (is_array($v)) {
                $v = self::deepRemoveNulls($v);
                if ($v === []) continue;
            }
            $out[$k] = $v;
        }
        return $out;
    }

    /**
     * @param mixed $v
     * @return mixed
     */
    private static function deepLimit($v, int $maxStr, int $maxArr)
    {
        if (is_string($v)) {
            return self::truncate($v, $maxStr);
        }
        if (is_array($v)) {
            // limit arrays
            $out = [];
            $i = 0;
            foreach ($v as $k => $val) {
                $i++;
                if ($i > $maxArr) break;
                $out[$k] = self::deepLimit($val, $maxStr, $maxArr);
            }
            return $out;
        }
        return $v;
    }

    /**
     * @param mixed $v
     * @return mixed
     */
    private static function deepKeysSnakeCase($v)
    {
        if (!is_array($v)) return $v;

        $out = [];
        foreach ($v as $k => $val) {
            $nk = is_string($k) ? self::toSnakeCase($k) : $k;
            $out[$nk] = self::deepKeysSnakeCase($val);
        }
        return $out;
    }

    private static function toSnakeCase(string $s): string
    {
        $s = preg_replace('/[^\pL\pN]+/u', '_', $s) ?? $s;
        $s = preg_replace('/([a-z])([A-Z])/', '$1_$2', $s) ?? $s;
        $s = strtolower($s);
        $s = trim($s, '_');
        $s = preg_replace('/_+/', '_', $s) ?? $s;
        return $s;
    }

    // =============================================================================
    // JSON extraction utilities
    // =============================================================================

    /**
     * Lenient JSON decode. Extracts {...} or [...] if needed.
     * @return mixed
     */
    private static function decodeJsonLenient(string $text)
    {
        $t = trim($text);
        if ($t === '') return null;

        $decoded = json_decode($t, true);
        if (json_last_error() === JSON_ERROR_NONE) return $decoded;

        // Remove leading BOM or weird chars
        $t2 = self::stripBom($t);

        $decoded2 = json_decode($t2, true);
        if (json_last_error() === JSON_ERROR_NONE) return $decoded2;

        // Extract object
        $start = strpos($t2, '{');
        $end = strrpos($t2, '}');
        if ($start !== false && $end !== false && $end > $start) {
            $sub = substr($t2, $start, $end - $start + 1);
            $d = json_decode($sub, true);
            if (json_last_error() === JSON_ERROR_NONE) return $d;
        }

        // Extract array
        $start = strpos($t2, '[');
        $end = strrpos($t2, ']');
        if ($start !== false && $end !== false && $end > $start) {
            $sub = substr($t2, $start, $end - $start + 1);
            $d = json_decode($sub, true);
            if (json_last_error() === JSON_ERROR_NONE) return $d;
        }

        return null;
    }

    private static function stripBom(string $s): string
    {
        // UTF-8 BOM
        if (substr($s, 0, 3) === "\xEF\xBB\xBF") {
            return substr($s, 3);
        }
        return $s;
    }

    // =============================================================================
    // Digit normalizer
    // =============================================================================

    /**
     * Convert Persian/Arabic digits to English digits.
     */
    private static function faDigitsToEn(string $s): string
    {
        $fa = ['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹'];
        $ar = ['٠','١','٢','٣','٤','٥','٦','٧','٨','٩'];
        $en = ['0','1','2','3','4','5','6','7','8','9'];

        $s = str_replace($fa, $en, $s);
        $s = str_replace($ar, $en, $s);
        return $s;
    }

    // =============================================================================
    // Warnings + misc
    // =============================================================================

    /**
     * @param mixed $warnings
     * @return array<int,string>
     */
    private static function addWarning($warnings, string $w): array
    {
        $arr = [];
        if (is_array($warnings)) {
            foreach ($warnings as $x) {
                if (is_string($x) && trim($x) !== '') $arr[] = trim($x);
            }
        }
        $arr[] = $w;
        // unique
        $arr = array_values(array_unique($arr));
        return $arr;
    }

    private static function truncate(string $s, int $max): string
    {
        if ($max <= 0) return '';
        if (mb_strlen($s) <= $max) return $s;
        return mb_substr($s, 0, $max) . '...[truncated]';
    }
}
