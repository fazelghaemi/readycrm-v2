<?php
/**
 * File: app/Services/SMS/MessageWayClient.php
 *
 * MessageWay API Client
 * ------------------------------------------------------------
 * کلاینت کامل برای ارتباط با وب سرویس راه پیام (MessageWay)
 * 
 * قابلیت‌ها:
 * - ارسال OTP از طریق SMS، Messenger (Gap, iGap, Soroush), IVR
 * - تایید کد OTP
 * - بررسی وضعیت OTP
 * - دریافت اطلاعات قالب‌ها
 * - چک موجودی حساب
 * - ارسال پیامک تکی و گروهی
 * 
 * مستندات MessageWay: https://messageway.com/docs
 */

declare(strict_types=1);

namespace App\Services\SMS;

use RuntimeException;
use Throwable;

final class MessageWayClient
{
    private string $apiKey;
    private string $baseUrl;
    private int $timeoutSec;
    private bool $verifySsl;
    
    /** @var callable|null */
    private $logger;

    /**
     * @param array<string,mixed> $config
     */
    public function __construct(array $config, ?callable $logger = null)
    {
        $this->apiKey = (string)($config['api_key'] ?? '');
        if (trim($this->apiKey) === '') {
            throw new RuntimeException("MessageWay API key is required.");
        }

        $this->baseUrl = rtrim((string)($config['base_url'] ?? 'https://messageway.com/api/v1'), '/');
        $this->timeoutSec = (int)($config['timeout_sec'] ?? 30);
        $this->verifySsl = (bool)($config['verify_ssl'] ?? true);
        $this->logger = $logger;
    }

    // =========================================================
    // OTP Methods
    // =========================================================

    /**
     * ارسال OTP از طریق SMS
     * 
     * @param string $mobile شماره موبایل (09xxxxxxxxx)
     * @param string $templateId شناسه قالب (از پنل MessageWay)
     * @param array<string,mixed> $parameters پارامترهای قالب (مثل: ['code' => '1234'])
     * @return array<string,mixed>
     */
    public function sendOtpSms(string $mobile, string $templateId, array $parameters = []): array
    {
        return $this->request('POST', '/otp/sms/send', [
            'mobile' => $this->normalizeMobile($mobile),
            'template_id' => $templateId,
            'parameters' => $parameters,
        ]);
    }

    /**
     * ارسال OTP از طریق پیام‌رسان (Gap, iGap, Soroush)
     * 
     * @param string $mobile شماره موبایل
     * @param string $templateId شناسه قالب
     * @param string $messenger نام پیام‌رسان: gap, igap, soroush
     * @param array<string,mixed> $parameters
     * @return array<string,mixed>
     */
    public function sendOtpMessenger(string $mobile, string $templateId, string $messenger, array $parameters = []): array
    {
        $validMessengers = ['gap', 'igap', 'soroush'];
        $messenger = strtolower(trim($messenger));
        
        if (!in_array($messenger, $validMessengers, true)) {
            throw new RuntimeException("Invalid messenger. Must be: " . implode(', ', $validMessengers));
        }

        return $this->request('POST', '/otp/messenger/send', [
            'mobile' => $this->normalizeMobile($mobile),
            'template_id' => $templateId,
            'messenger' => $messenger,
            'parameters' => $parameters,
        ]);
    }

    /**
     * ارسال OTP از طریق تماس صوتی (IVR)
     * 
     * @param string $mobile شماره موبایل
     * @param string $code کد OTP (فقط اعداد)
     * @return array<string,mixed>
     */
    public function sendOtpIvr(string $mobile, string $code): array
    {
        return $this->request('POST', '/otp/ivr/send', [
            'mobile' => $this->normalizeMobile($mobile),
            'code' => $code,
        ]);
    }

    /**
     * تایید کد OTP
     * 
     * @param string $mobile شماره موبایل
     * @param string $code کد دریافت شده از کاربر
     * @param string $referenceId شناسه مرجع از پاسخ ارسال OTP
     * @return array<string,mixed> ['verified' => true/false, 'message' => '...']
     */
    public function verifyOtp(string $mobile, string $code, string $referenceId): array
    {
        return $this->request('POST', '/otp/verify', [
            'mobile' => $this->normalizeMobile($mobile),
            'code' => $code,
            'reference_id' => $referenceId,
        ]);
    }

    /**
     * بررسی وضعیت OTP
     * 
     * @param string $referenceId
     * @return array<string,mixed>
     */
    public function checkOtpStatus(string $referenceId): array
    {
        return $this->request('GET', '/otp/status/' . $referenceId);
    }

    // =========================================================
    // Template Methods
    // =========================================================

    /**
     * دریافت اطلاعات یک قالب
     * 
     * @param string $templateId
     * @return array<string,mixed>
     */
    public function getTemplate(string $templateId): array
    {
        return $this->request('GET', '/templates/' . $templateId);
    }

    /**
     * لیست تمام قالب‌ها
     * 
     * @return array<string,mixed>
     */
    public function listTemplates(): array
    {
        return $this->request('GET', '/templates');
    }

    // =========================================================
    // SMS Sending Methods
    // =========================================================

    /**
     * ارسال پیامک تکی
     * 
     * @param string $mobile شماره موبایل گیرنده
     * @param string $message متن پیام
     * @param string|null $sender خط ارسال (اختیاری - از تنظیمات پنل استفاده می‌شود)
     * @return array<string,mixed>
     */
    public function sendSms(string $mobile, string $message, ?string $sender = null): array
    {
        $payload = [
            'mobile' => $this->normalizeMobile($mobile),
            'message' => $message,
        ];

        if ($sender !== null) {
            $payload['sender'] = $sender;
        }

        return $this->request('POST', '/sms/send', $payload);
    }

    /**
     * ارسال پیامک گروهی
     * 
     * @param array<int,string> $mobiles لیست شماره موبایل‌های گیرنده
     * @param string $message متن پیام
     * @param string|null $sender خط ارسال
     * @return array<string,mixed>
     */
    public function sendBulkSms(array $mobiles, string $message, ?string $sender = null): array
    {
        $normalized = array_map([$this, 'normalizeMobile'], $mobiles);
        
        $payload = [
            'mobiles' => $normalized,
            'message' => $message,
        ];

        if ($sender !== null) {
            $payload['sender'] = $sender;
        }

        return $this->request('POST', '/sms/send/bulk', $payload);
    }

    /**
     * ارسال پیامک با قالب
     * 
     * @param string $mobile
     * @param string $templateId
     * @param array<string,mixed> $parameters
     * @return array<string,mixed>
     */
    public function sendTemplateSms(string $mobile, string $templateId, array $parameters = []): array
    {
        return $this->request('POST', '/sms/send/template', [
            'mobile' => $this->normalizeMobile($mobile),
            'template_id' => $templateId,
            'parameters' => $parameters,
        ]);
    }

    /**
     * بررسی وضعیت ارسال پیامک
     * 
     * @param string $messageId شناسه پیام
     * @return array<string,mixed>
     */
    public function getSmsStatus(string $messageId): array
    {
        return $this->request('GET', '/sms/status/' . $messageId);
    }

    // =========================================================
    // Account Methods
    // =========================================================

    /**
     * دریافت موجودی حساب
     * 
     * @return array<string,mixed> ['balance' => 123456.78, 'currency' => 'IRR']
     */
    public function getBalance(): array
    {
        return $this->request('GET', '/account/balance');
    }

    /**
     * دریافت اطلاعات حساب کاربری
     * 
     * @return array<string,mixed>
     */
    public function getAccountInfo(): array
    {
        return $this->request('GET', '/account/info');
    }

    // =========================================================
    // HTTP Request Handler
    // =========================================================

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function request(string $method, string $path, array $payload = []): array
    {
        $method = strtoupper($method);
        $url = $this->baseUrl . $path;

        $this->log("MessageWay API: {$method} {$url}");

        $ch = curl_init();
        if (!$ch) {
            throw new RuntimeException("cURL initialization failed.");
        }

        $headers = [
            'Authorization: Bearer ' . $this->apiKey,
            'Content-Type: application/json',
            'Accept: application/json',
            'User-Agent: CRM-V2-MessageWay-Client/1.0',
        ];

        $opts = [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => max(1, $this->timeoutSec),
            CURLOPT_CONNECTTIMEOUT => min(10, max(1, $this->timeoutSec)),
        ];

        if ($method === 'POST' || $method === 'PUT' || $method === 'PATCH') {
            $opts[CURLOPT_POSTFIELDS] = json_encode($payload, JSON_UNESCAPED_UNICODE);
        } elseif ($method === 'GET' && !empty($payload)) {
            // Add query string for GET
            $query = http_build_query($payload);
            $opts[CURLOPT_URL] = $url . '?' . $query;
        }

        if (!$this->verifySsl) {
            $opts[CURLOPT_SSL_VERIFYPEER] = false;
            $opts[CURLOPT_SSL_VERIFYHOST] = 0;
        }

        curl_setopt_array($ch, $opts);

        $raw = curl_exec($ch);
        if ($raw === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException("MessageWay API request failed: " . $err);
        }

        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        $rawBody = substr($raw, $headerSize);

        $this->log("MessageWay API Response: HTTP {$status}");

        $decoded = json_decode($rawBody, true);

        if ($status < 200 || $status >= 300) {
            $errorMsg = "MessageWay API returned HTTP {$status}.";
            
            if (is_array($decoded)) {
                if (isset($decoded['error'])) {
                    $errorMsg .= " Error: " . (is_string($decoded['error']) ? $decoded['error'] : json_encode($decoded['error']));
                }
                if (isset($decoded['message'])) {
                    $errorMsg .= " Message: " . $decoded['message'];
                }
            }

            throw new RuntimeException($errorMsg);
        }

        if (!is_array($decoded)) {
            throw new RuntimeException("Invalid JSON response from MessageWay API. Body: " . substr($rawBody, 0, 500));
        }

        return $decoded;
    }

    // =========================================================
    // Helpers
    // =========================================================

    /**
     * نرمال‌سازی شماره موبایل ایران
     * مثال: 09123456789 یا 989123456789 یا +989123456789
     */
    private function normalizeMobile(string $mobile): string
    {
        $mobile = trim($mobile);
        
        // حذف کاراکترهای غیرعددی به جز +
        $mobile = preg_replace('/[^\d\+]/u', '', $mobile) ?? $mobile;
        
        // حذف + از ابتدا
        $mobile = ltrim($mobile, '+');
        
        // اگر با 98 شروع شود، حذف می‌کنیم
        if (str_starts_with($mobile, '98')) {
            $mobile = substr($mobile, 2);
        }
        
        // اگر با 0 شروع نشود، اضافه می‌کنیم
        if (!str_starts_with($mobile, '0')) {
            $mobile = '0' . $mobile;
        }
        
        // بررسی طول (باید 11 رقم باشد برای ایران)
        if (strlen($mobile) !== 11) {
            throw new RuntimeException("Invalid mobile number: {$mobile}. Must be 11 digits starting with 09");
        }

        return $mobile;
    }

    private function log(string $message): void
    {
        if ($this->logger) {
            try {
                ($this->logger)($message);
            } catch (Throwable $e) {
                // Ignore logging errors
            }
        }
    }
}
