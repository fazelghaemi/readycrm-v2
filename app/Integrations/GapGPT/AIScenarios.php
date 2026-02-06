<?php
/**
 * File: app/Integrations/GapGPT/AIScenarios.php
 *
 * CRM V2 - AI Scenarios (GapGPT)
 * -----------------------------------------------------------------------------
 * این فایل مهم‌ترین بخش برای «AI قابل کنترل» است.
 *
 * مشکل رایج:
 * - وقتی مستقیم به LLM پیام می‌دهی، جواب‌ها غیرقابل پیش‌بینی و پراکنده می‌شود.
 *
 * راه‌حل:
 * - یک لیست سناریو (Scenario) تعریف می‌کنیم:
 *   - هر سناریو:
 *      1) هدف مشخص
 *      2) سیستم پرامپت استاندارد و پایدار
 *      3) قالب ورودی
 *      4) قالب خروجی (ترجیحاً JSON)
 *      5) تنظیمات مدل (temperature, max_tokens)
 *      6) اعتبارسنجی خروجی
 *
 * نتیجه:
 * - AIController فقط scenario_key می‌گیرد و اجرا می‌کند.
 * - شما می‌توانی در فاز دوم با وایب‌کدینگ:
 *   - سناریوهای جدید اضافه کنی
 *   - سناریوها را برای بخش‌های CRM (محصولات/مشتریان/فروش/تیکت) توسعه بدهی
 *
 * -----------------------------------------------------------------------------
 * نحوه استفاده (در AIController):
 *
 * $scenario = AIScenarios::get('product.description.generate');
 * $messages = AIScenarios::buildMessages($scenario, $input);
 * $options = AIScenarios::buildOptions($scenario, $override);
 * $resp = $gapgpt->chat($messages, $options);
 * $parsed = AIScenarios::parseOutput($scenario, $resp['text'], $resp['raw'] ?? null);
 */

declare(strict_types=1);

namespace App\Integrations\GapGPT;

use RuntimeException;

final class AIScenarios
{
    /**
     * لیست سناریوها.
     * هر سناریو یک آرایه است با کلیدهای:
     * - key: string
     * - title: string
     * - description: string
     * - system_prompt: string
     * - input_schema: array (اختیاری)
     * - output_schema: array (اختیاری)
     * - response_format: 'text'|'json'
     * - provider/model defaults: provider, model, temperature, max_tokens
     * - post_processors: array of postprocessor keys (اختیاری)
     *
     * @return array<string,array<string,mixed>>
     */
    public static function all(): array
    {
        // نکته: سناریوها را کوتاه/تمیز نگه می‌داریم، اما system_prompt ها دقیق‌اند.
        return [
            // -----------------------------------------------------------------
            // Products
            // -----------------------------------------------------------------
            'product.description.generate' => [
                'key' => 'product.description.generate',
                'title' => 'تولید توضیحات محصول',
                'description' => 'تولید توضیحات حرفه‌ای محصول (کوتاه + بلند) با توجه به ویژگی‌ها، کاربردها و سئو.',
                'response_format' => 'json',
                'provider' => null,
                'model' => null,
                'temperature' => 0.4,
                'max_tokens' => 1800,
                'system_prompt' => self::sysBaseFa() . "\n" . self::sysJsonRules() . "\n" . self::sysProductCopywriterFa(),
                'input_schema' => [
                    'required' => ['name'],
                    'optional' => ['sku', 'type', 'attributes', 'categories', 'price', 'brand', 'audience', 'tone', 'language', 'seo_keywords'],
                ],
                'output_schema' => [
                    'type' => 'object',
                    'required' => ['title', 'short_description', 'description', 'seo'],
                    'properties' => [
                        'title' => ['type' => 'string'],
                        'short_description' => ['type' => 'string'],
                        'description' => ['type' => 'string'],
                        'seo' => [
                            'type' => 'object',
                            'required' => ['focus_keywords', 'meta_title', 'meta_description', 'slug_suggestion'],
                            'properties' => [
                                'focus_keywords' => ['type' => 'array'],
                                'meta_title' => ['type' => 'string'],
                                'meta_description' => ['type' => 'string'],
                                'slug_suggestion' => ['type' => 'string'],
                            ],
                        ],
                        'bullets' => ['type' => 'array'],
                        'faq' => ['type' => 'array'],
                    ],
                ],
                'post_processors' => ['json_sanitize', 'trim_fields'],
            ],

            'product.attributes.extract' => [
                'key' => 'product.attributes.extract',
                'title' => 'استخراج ویژگی‌ها و مشخصات محصول',
                'description' => 'از متن خام یا توضیحات، ویژگی‌ها/مشخصات/گزینه‌های وارییشن را استخراج می‌کند.',
                'response_format' => 'json',
                'temperature' => 0.1,
                'max_tokens' => 1400,
                'system_prompt' => self::sysBaseFa() . "\n" . self::sysJsonRules() . "\n" . self::sysExtractorFa(),
                'input_schema' => [
                    'required' => ['text'],
                    'optional' => ['known_attributes', 'variation_mode'],
                ],
                'output_schema' => [
                    'type' => 'object',
                    'required' => ['attributes'],
                    'properties' => [
                        'attributes' => [
                            'type' => 'array',
                            'items' => [
                                'type' => 'object',
                                'required' => ['name', 'options'],
                                'properties' => [
                                    'name' => ['type' => 'string'],
                                    'options' => ['type' => 'array'],
                                    'variation' => ['type' => 'boolean'],
                                    'visible' => ['type' => 'boolean'],
                                ],
                            ],
                        ],
                        'suggested_type' => ['type' => 'string'], // simple|variable
                    ],
                ],
                'post_processors' => ['json_sanitize'],
            ],

            'product.pricing.suggest' => [
                'key' => 'product.pricing.suggest',
                'title' => 'پیشنهاد قیمت محصول',
                'description' => 'پیشنهاد قیمت/بازه قیمت با توجه به هزینه، سود هدف، رقبا، موجودی و تقاضا.',
                'response_format' => 'json',
                'temperature' => 0.2,
                'max_tokens' => 1200,
                'system_prompt' => self::sysBaseFa() . "\n" . self::sysJsonRules() . "\n" . self::sysPricingFa(),
                'input_schema' => [
                    'required' => ['name'],
                    'optional' => ['cost', 'target_margin', 'market_range', 'currency', 'inventory', 'seasonality', 'notes'],
                ],
                'output_schema' => [
                    'type' => 'object',
                    'required' => ['suggested_price', 'rationale'],
                    'properties' => [
                        'suggested_price' => ['type' => 'number'],
                        'price_range' => ['type' => 'object'],
                        'rationale' => ['type' => 'array'],
                        'risks' => ['type' => 'array'],
                        'next_steps' => ['type' => 'array'],
                    ],
                ],
                'post_processors' => ['json_sanitize', 'numbers_normalize'],
            ],

            // -----------------------------------------------------------------
            // Customers / CRM
            // -----------------------------------------------------------------
            'customer.profile.summarize' => [
                'key' => 'customer.profile.summarize',
                'title' => 'خلاصه پروفایل مشتری',
                'description' => 'با استفاده از تاریخچه سفارش/تعاملات، یک خلاصه عملیاتی از مشتری می‌سازد.',
                'response_format' => 'json',
                'temperature' => 0.2,
                'max_tokens' => 1200,
                'system_prompt' => self::sysBaseFa() . "\n" . self::sysJsonRules() . "\n" . self::sysCrmAnalystFa(),
                'input_schema' => [
                    'required' => ['customer'],
                    'optional' => ['orders', 'notes', 'tickets', 'messages'],
                ],
                'output_schema' => [
                    'type' => 'object',
                    'required' => ['summary', 'segments', 'recommendations'],
                    'properties' => [
                        'summary' => ['type' => 'string'],
                        'segments' => ['type' => 'array'],
                        'risks' => ['type' => 'array'],
                        'recommendations' => ['type' => 'array'],
                        'next_actions' => ['type' => 'array'],
                    ],
                ],
                'post_processors' => ['json_sanitize', 'trim_fields'],
            ],

            'customer.message.reply_draft' => [
                'key' => 'customer.message.reply_draft',
                'title' => 'پیشنویس پاسخ به مشتری',
                'description' => 'پاسخ محترمانه/فنی به پیام مشتری تولید می‌کند (قابل ارسال توسط اپراتور).',
                'response_format' => 'json',
                'temperature' => 0.5,
                'max_tokens' => 1000,
                'system_prompt' => self::sysBaseFa() . "\n" . self::sysJsonRules() . "\n" . self::sysSupportAgentFa(),
                'input_schema' => [
                    'required' => ['customer_message'],
                    'optional' => ['customer_context', 'tone', 'policy', 'store_rules'],
                ],
                'output_schema' => [
                    'type' => 'object',
                    'required' => ['reply', 'tone', 'notes_for_agent'],
                    'properties' => [
                        'reply' => ['type' => 'string'],
                        'tone' => ['type' => 'string'],
                        'notes_for_agent' => ['type' => 'array'],
                        'follow_up_questions' => ['type' => 'array'],
                    ],
                ],
                'post_processors' => ['json_sanitize', 'trim_fields'],
            ],

            // -----------------------------------------------------------------
            // Orders / Sales
            // -----------------------------------------------------------------
            'order.risk.detect' => [
                'key' => 'order.risk.detect',
                'title' => 'تشخیص ریسک سفارش',
                'description' => 'نشانه‌های ریسک (تقلب/مغایرت/لغو/مرجوعی) را از داده‌های سفارش استخراج می‌کند.',
                'response_format' => 'json',
                'temperature' => 0.1,
                'max_tokens' => 1200,
                'system_prompt' => self::sysBaseFa() . "\n" . self::sysJsonRules() . "\n" . self::sysRiskAnalystFa(),
                'input_schema' => [
                    'required' => ['order'],
                    'optional' => ['customer', 'history', 'payment', 'shipping'],
                ],
                'output_schema' => [
                    'type' => 'object',
                    'required' => ['risk_score', 'flags', 'recommendations'],
                    'properties' => [
                        'risk_score' => ['type' => 'number'], // 0..100
                        'flags' => ['type' => 'array'],
                        'recommendations' => ['type' => 'array'],
                        'needs_manual_review' => ['type' => 'boolean'],
                    ],
                ],
                'post_processors' => ['json_sanitize', 'numbers_normalize'],
            ],

            'order.status.explain' => [
                'key' => 'order.status.explain',
                'title' => 'توضیح وضعیت سفارش برای مشتری',
                'description' => 'متن کوتاه و قابل فهم برای توضیح وضعیت سفارش تولید می‌کند.',
                'response_format' => 'json',
                'temperature' => 0.4,
                'max_tokens' => 700,
                'system_prompt' => self::sysBaseFa() . "\n" . self::sysJsonRules() . "\n" . self::sysFriendlyFa(),
                'input_schema' => [
                    'required' => ['order_status'],
                    'optional' => ['order_id', 'eta', 'notes', 'language', 'tone'],
                ],
                'output_schema' => [
                    'type' => 'object',
                    'required' => ['message'],
                    'properties' => [
                        'message' => ['type' => 'string'],
                        'short_sms' => ['type' => 'string'],
                    ],
                ],
                'post_processors' => ['json_sanitize', 'trim_fields'],
            ],

            // -----------------------------------------------------------------
            // Accounting-lite
            // -----------------------------------------------------------------
            'accounting.invoice.extract' => [
                'key' => 'accounting.invoice.extract',
                'title' => 'استخراج اطلاعات فاکتور از متن',
                'description' => 'از متن/پی‌دی‌اف (متن استخراج‌شده) فاکتور، آیتم‌ها، مبالغ، مالیات و... را استخراج می‌کند.',
                'response_format' => 'json',
                'temperature' => 0.0,
                'max_tokens' => 1400,
                'system_prompt' => self::sysBaseFa() . "\n" . self::sysJsonRules() . "\n" . self::sysInvoiceExtractorFa(),
                'input_schema' => [
                    'required' => ['invoice_text'],
                    'optional' => ['currency', 'seller', 'buyer'],
                ],
                'output_schema' => [
                    'type' => 'object',
                    'required' => ['items', 'totals'],
                    'properties' => [
                        'items' => ['type' => 'array'],
                        'totals' => ['type' => 'object'],
                        'warnings' => ['type' => 'array'],
                    ],
                ],
                'post_processors' => ['json_sanitize', 'numbers_normalize'],
            ],
        ];
    }

    /**
     * Get scenario by key.
     * @return array<string,mixed>
     */
    public static function get(string $key): array
    {
        $all = self::all();
        if (!isset($all[$key])) {
            throw new RuntimeException("AI scenario not found: {$key}");
        }
        return $all[$key];
    }

    /**
     * Build standard messages for GapGPTClient::chat
     *
     * @param array<string,mixed> $scenario
     * @param array<string,mixed> $input
     * @return array<int,array{role:string,content:string}>
     */
    public static function buildMessages(array $scenario, array $input): array
    {
        $system = (string)($scenario['system_prompt'] ?? self::sysBaseFa());
        $format = (string)($scenario['response_format'] ?? 'text');

        $inputJson = json_encode($input, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        if ($inputJson === false) {
            $inputJson = '{}';
        }

        $user = "INPUT:\n" . $inputJson . "\n\n";
        if ($format === 'json') {
            $user .= "OUTPUT: فقط JSON معتبر بده. هیچ متن اضافی ننویس.";
        } else {
            $user .= "OUTPUT: متن.";
        }

        return [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => $user],
        ];
    }

    /**
     * Build options for GapGPTClient::chat
     * @param array<string,mixed> $scenario
     * @param array<string,mixed> $override
     * @return array<string,mixed>
     */
    public static function buildOptions(array $scenario, array $override = []): array
    {
        $opt = [
            'provider' => $scenario['provider'] ?? null,
            'model' => $scenario['model'] ?? null,
            'temperature' => $scenario['temperature'] ?? null,
            'max_tokens' => $scenario['max_tokens'] ?? null,
        ];

        // حذف null ها
        foreach ($opt as $k => $v) {
            if ($v === null) unset($opt[$k]);
        }

        // اگر سناریو json است، بهتر است response_format هم تنظیم شود (اگر API پشتیبانی کند)
        if (($scenario['response_format'] ?? 'text') === 'json') {
            // این استاندارد OpenAI-like است؛ اگر GapGPT پشتیبانی نکند، بی‌اثر است.
            $opt['response_format'] = ['type' => 'json_object'];
        }

        // override
        foreach ($override as $k => $v) {
            $opt[$k] = $v;
        }

        return $opt;
    }

    /**
     * Parse output for scenario.
     * - اگر JSON بود، decode می‌کند
     * - post-processors را اعمال می‌کند
     *
     * @param array<string,mixed> $scenario
     * @param string $text
     * @param array<string,mixed>|null $raw
     * @return array<string,mixed>
     */
    public static function parseOutput(array $scenario, string $text, ?array $raw = null): array
    {
        $format = (string)($scenario['response_format'] ?? 'text');

        $result = [
            'ok' => true,
            'format' => $format,
            'data' => null,
            'text' => $text,
            'raw' => $raw,
            'warnings' => [],
        ];

        if ($format === 'json') {
            $decoded = self::decodeJsonLenient($text);
            if (!is_array($decoded)) {
                $result['ok'] = false;
                $result['warnings'][] = 'AI output is not valid JSON.';
                $result['data'] = null;
            } else {
                $result['data'] = $decoded;
            }
        } else {
            $result['data'] = ['text' => $text];
        }

        // post-processors
        $pps = $scenario['post_processors'] ?? [];
        if (is_array($pps) && count($pps) > 0) {
            foreach ($pps as $ppKey) {
                $ppKey = is_string($ppKey) ? $ppKey : '';
                if ($ppKey === '') continue;

                $result = OutputPostProcessors::apply($ppKey, $result);
            }
        }

        // optional: validate schema (lightweight)
        if (($scenario['response_format'] ?? 'text') === 'json' && isset($scenario['output_schema']) && is_array($scenario['output_schema'])) {
            $schemaWarnings = self::lightValidate($scenario['output_schema'], $result['data']);
            foreach ($schemaWarnings as $w) {
                $result['warnings'][] = $w;
            }
        }

        return $result;
    }

    // =============================================================================
    // Internal - prompts
    // =============================================================================

    private static function sysBaseFa(): string
    {
        return
            "تو یک دستیار حرفه‌ای برای CRM و فروشگاه آنلاین هستی.\n" .
            "قواعد:\n" .
            "1) خروجی دقیق، کاربردی و قابل اجرا بده.\n" .
            "2) اگر داده ناقص است، حدس نزن؛ در خروجی warnings را بنویس.\n" .
            "3) فارسی بنویس، اما کلیدهای JSON انگلیسی باشند.\n" .
            "4) از ارائه اطلاعات حساس/غیرضروری خودداری کن.\n";
    }

    private static function sysJsonRules(): string
    {
        return
            "قواعد JSON:\n" .
            "- فقط و فقط JSON معتبر بده.\n" .
            "- هیچ متن اضافی، توضیح، markdown، یا سه بک‌تیک ننویس.\n" .
            "- رشته‌ها کوتاه و تمیز، لیست‌ها قابل استفاده.\n";
    }

    private static function sysProductCopywriterFa(): string
    {
        return
            "نقش: کپی‌رایتر و کارشناس محصول و سئو.\n" .
            "خروجی شامل: title, short_description, description, bullets, faq, seo.\n" .
            "seo.focus_keywords یک آرایه از 3 تا 8 کلمه کلیدی.\n" .
            "meta_title <= 60 کاراکتر، meta_description <= 155 کاراکتر.\n";
    }

    private static function sysExtractorFa(): string
    {
        return
            "نقش: استخراج‌کننده داده.\n" .
            "از متن خام، ویژگی‌های قابل تبدیل به attributes را استخراج کن.\n" .
            "اگر ویژگی‌ها برای وارییشن مناسب‌اند، variation=true بگذار.\n" .
            "اگر متن مشخص نمی‌کند، در warnings اشاره کن.\n";
    }

    private static function sysPricingFa(): string
    {
        return
            "نقش: تحلیلگر قیمت‌گذاری.\n" .
            "هدف: پیشنهاد قیمت منطقی بر اساس هزینه/سود/بازار.\n" .
            "اگر داده کافی نیست، یک بازه پیشنهادی بده و دلیل را در rationale بنویس.\n" .
            "اعداد را عددی بده (نه با متن).\n";
    }

    private static function sysCrmAnalystFa(): string
    {
        return
            "نقش: تحلیلگر CRM.\n" .
            "از داده مشتری و سفارش‌ها، یک خلاصه عملیاتی برای اپراتور بساز.\n" .
            "خروجی باید actionable باشد.\n";
    }

    private static function sysSupportAgentFa(): string
    {
        return
            "نقش: کارشناس پشتیبانی.\n" .
            "پاسخ مودبانه، دقیق و قابل ارسال تولید کن.\n" .
            "اگر نیاز به سوال تکمیلی است، در follow_up_questions بنویس.\n";
    }

    private static function sysRiskAnalystFa(): string
    {
        return
            "نقش: تحلیلگر ریسک سفارش.\n" .
            "risk_score بین 0 تا 100.\n" .
            "اگر نشانه‌های تقلب/ریسک دیدی، flags را دقیق بنویس.\n";
    }

    private static function sysFriendlyFa(): string
    {
        return
            "نقش: تولید متن ساده و قابل فهم برای مشتری.\n" .
            "پیام کوتاه و شفاف.\n";
    }

    private static function sysInvoiceExtractorFa(): string
    {
        return
            "نقش: استخراج‌کننده فاکتور.\n" .
            "آیتم‌ها را شامل: name, qty, unit_price, total.\n" .
            "totals شامل: subtotal, tax, shipping, discount, grand_total.\n" .
            "اگر اعداد مبهم‌اند، warnings بده.\n";
    }

    // =============================================================================
    // Internal - JSON decode lenient + schema validate
    // =============================================================================

    /**
     * تلاش می‌کند JSON را حتی اگر قبل/بعدش متن اضافه باشد استخراج کند.
     * @return mixed
     */
    private static function decodeJsonLenient(string $text)
    {
        $t = trim($text);
        if ($t === '') return null;

        // Try direct decode
        $decoded = json_decode($t, true);
        if (json_last_error() === JSON_ERROR_NONE) return $decoded;

        // Try to extract first {...} block
        $start = strpos($t, '{');
        $end = strrpos($t, '}');
        if ($start !== false && $end !== false && $end > $start) {
            $sub = substr($t, $start, $end - $start + 1);
            $decoded2 = json_decode($sub, true);
            if (json_last_error() === JSON_ERROR_NONE) return $decoded2;
        }

        // Try to extract first [...] block
        $start = strpos($t, '[');
        $end = strrpos($t, ']');
        if ($start !== false && $end !== false && $end > $start) {
            $sub = substr($t, $start, $end - $start + 1);
            $decoded3 = json_decode($sub, true);
            if (json_last_error() === JSON_ERROR_NONE) return $decoded3;
        }

        return null;
    }

    /**
     * اعتبارسنجی سبک و ساده (نه JSON Schema کامل)
     * @param array<string,mixed> $schema
     * @param mixed $data
     * @return array<int,string> warnings
     */
    private static function lightValidate(array $schema, $data): array
    {
        $w = [];

        $type = $schema['type'] ?? null;
        if ($type === 'object') {
            if (!is_array($data)) {
                $w[] = 'Output is not an object.';
                return $w;
            }

            $required = $schema['required'] ?? [];
            if (is_array($required)) {
                foreach ($required as $rk) {
                    if (is_string($rk) && !array_key_exists($rk, $data)) {
                        $w[] = "Missing required key: {$rk}";
                    }
                }
            }
        }

        // (برای جلوگیری از پیچیدگی، اینجا ادامه نمی‌دهیم. همین مقدار برای کنترل کافی است.)
        return $w;
    }
}
