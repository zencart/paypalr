<?php
/**
 * Debug logging class for the PayPalRestful (paypalr) Payment Module
 *
 * @copyright Copyright 2003-2026 Zen Cart Development Team
 * @license https://www.zen-cart.com/license/2_0.txt GNU Public License V2.0
 *
 * Last updated: v1.3.0
 */

namespace PayPalRestful\Common;

use PayPalRestful\Common\Helpers;

class Logger
{
    /**
     * Static variables associated with interface logging;
     *
     * @debugLogFile string
     * @debug bool
     */
    protected static bool $debug = false;
    protected static string $debugLogFile;

    // -----
    // Class constructor.
    //
    public function __construct(string $uniqueName = '')
    {
        global $current_page_base;

        // -----
        // Using the same log-file name for each page-load.
        // If it's already set, simply return.
        //
        if (isset(self::$debugLogFile)) {
            return;
        }
        
        if (!empty($current_page_base) && \str_contains($current_page_base, 'webhook')) {
            $logfile_suffix = 'webhook-' . $uniqueName;
            $logfile_suffix = trim($logfile_suffix, '-');
        } elseif (IS_ADMIN_FLAG === false) {
            $logfile_suffix = 'c-' . ($_SESSION['customer_id'] ?? 'na') . '-' . Helpers::getCustomerNameSuffix();
        } else {
            $logfile_suffix = 'adm-a' . $_SESSION['admin_id'];
            global $order;
            if (isset($order)) {
                $logfile_suffix .= '-o' . $order->info['order_id'];
            }
        }
        self::$debugLogFile = DIR_FS_LOGS . '/paypalr-' . $logfile_suffix . '-' . date('Ymd') . '.log';
    }

    public function enableDebug(): void
    {
        self::$debug = true;
    }
    public function disableDebug(): void
    {
        self::$debug = false;
    }

    // -----
    // Format pretty-printed JSON for the debug-log, removing any HTTP Header
    // information (present in the CURL options) and/or the actual access-token as well
    // as obfuscating any credit-card information and PII in the data supplied.
    //
    // Also remove unneeded return values that will just 'clutter up' the logged information,
    // unless requested to keep them.
    //
    public static function logJSON($data, bool $keep_links = false, bool $use_var_export = false): string
    {
        if (is_array($data)) {
            unset(
                $data[CURLOPT_HTTPHEADER],
                $data['access_token'],
                $data['scope'],
                $data['app_id'],
                $data['nonce']
            );

            // -----
            // CURLOPT_POSTFIELDS carries the JSON-encoded request body for card orders
            // (full PAN, CVV, billing address). Decode, redact, re-encode so the log
            // captures request shape without sensitive content.  The live curl options
            // array is never modified — this operates only on the local copy used for
            // logging (issueRequest passes $curl_options only to these log calls after
            // curl_exec() has already completed).
            //
            if (isset($data[CURLOPT_POSTFIELDS]) && is_string($data[CURLOPT_POSTFIELDS])) {
                $decoded = json_decode($data[CURLOPT_POSTFIELDS], true);
                if (is_array($decoded)) {
                    $data[CURLOPT_POSTFIELDS] = json_encode(self::redactPii($decoded));
                }
            }

            // -----
            // Redact PII fields that appear in PayPal order responses and webhook
            // bodies (payer name/email/phone/address, shipping name/address, card
            // number/CVV).  The recursive walk covers every nesting depth so new
            // PayPal response shapes are handled without additional case statements.
            //
            $data = self::redactPii($data);

            if ($keep_links === false) {
                unset(
                    $data['links'],
                    $data['purchase_units'][0]['payments']['authorizations']['links'],
                    $data['purchase_units'][0]['payments']['captures']['links'],
                    $data['purchase_units'][0]['payments']['refunds']['links']
                );
            }
            foreach (['authorizations', 'captures', 'refunds'] as $next_payment_type) {
                if (!isset($data['purchase_units'][0]['payments'][$next_payment_type])) {
                    continue;
                }
                for ($i = 0, $n = count($data['purchase_units'][0]['payments'][$next_payment_type]); $i < $n; $i++) {
                    unset($data['purchase_units'][0]['payments'][$next_payment_type][$i]['links']);
                }
            }
        }
        return ($use_var_export === true) ? var_export($data, true) : json_encode($data, JSON_PRETTY_PRINT);
    }

    // -----
    // Recursively walk an array and mask the value of any key that carries
    // PII or payment credentials.  Keys are matched case-insensitively.
    // Three masking tiers apply:
    //
    //   CVV (security_code)  — PCI-DSS 3.3.2 prohibits retaining SAD in any
    //                          form after auth; always fully redacted.
    //
    //   Card PAN (number)    — PCI-DSS 3.3.1 permits first-6 + last-4;
    //                          middle digits replaced with '#'.  Output is
    //                          the same length as the original PAN.
    //
    //   PII (name/email/     — Not PCI-governed (GDPR data minimisation).
    //   address/phone)         Output always matches the original length:
    //                          ≥5 chars: first-2 + '*' × (len-4) + last-2
    //                          3-4 chars: first-1 + '*' × (len-2) + last-1
    //                          1-2 chars: left unmasked (too coarse to identify)
    //
    private static function redactPii(array $data): array
    {
        static $fullRedact   = ['security_code'];
        static $panMask      = ['number'];
        static $partialMask  = [
            'given_name', 'surname', 'full_name', 'email_address', 'national_number',
            'address_line_1', 'address_line_2', 'postal_code', 'admin_area_2',
        ];
        // admin_area_1 (state/province code e.g. "CA") is coarse-grained location,
        // not personally identifying — left unmasked for debugging utility.

        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = self::redactPii($value);
                continue;
            }
            if (!is_string($value)) {
                continue;
            }
            $lkey = strtolower((string)$key);
            if (in_array($lkey, $fullRedact, true)) {
                $data[$key] = '**redacted**';
            } elseif (in_array($lkey, $panMask, true)) {
                $len = strlen($value);
                $data[$key] = $len >= 10
                    ? substr($value, 0, 6) . str_repeat('#', $len - 10) . substr($value, -4)
                    : '**redacted**';
            } elseif (in_array($lkey, $partialMask, true)) {
                $len = strlen($value);
                if ($len >= 5) {
                    // first-2 + same-length stars + last-2
                    $data[$key] = substr($value, 0, 2) . str_repeat('*', $len - 4) . substr($value, -2);
                } elseif ($len >= 3) {
                    // short values (e.g. 4-char city names): first-1 + stars + last-1
                    $data[$key] = substr($value, 0, 1) . str_repeat('*', $len - 2) . substr($value, -1);
                }
                // 1-2 char values are too coarse-grained to need masking
            }
        }
        return $data;
    }

    public function write(string $message, bool $include_timestamp = false, string $include_separator = ''): void
    {
        global $current_page_base;

        if (self::$debug === true) {
            $timestamp = ($include_timestamp === false) ? '' : ("\n" . date('Y-m-d H:i:s: ') . "($current_page_base) ");
            $separator = '';
            $separator_before = '';
            $separator_after = '';
            if ($include_separator !== '') {
                $separator = "************************************************";
                if ($include_separator === 'before') {
                    $separator_before = (strpos($message, "\n") === 0) ? "\n$separator" : "\n$separator\n";
                } else {
                    $separator_after = (substr($message, -1) === "\n") ? "$separator\n" : "\n$separator\n";
                }
            }
            error_log($separator_before . $timestamp . $message . $separator_after, 3, self::$debugLogFile);
        }
    }
}
