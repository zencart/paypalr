<?php
/**
 * PayPal REST API Webhooks
 *
 * @copyright Copyright 2003-2026 Zen Cart Development Team
 * @license https://www.zen-cart.com/license/2_0.txt GNU Public License V2.0
 * @version $Id: lat9 2026 Mar 17 New in v2.2.1 $
 *
 * Last updated: v2.1.0
 */

namespace PayPalRestful\Webhooks\Events;

use PayPalRestful\Admin\GetPayPalOrderTransactions;
use PayPalRestful\Webhooks\WebhookHandlerContract;

class PaymentCaptureCompleted extends WebhookHandlerContract
{
    protected array $eventsHandled = [
        'PAYMENT.CAPTURE.COMPLETED',
    ];

    public function action(): void
    {
        global $zco_notifier;

        // A payment capture completes
        // https://developer.paypal.com/docs/api/payments/v2/#authorizations_capture - with response `status` of `completed`

        $this->log->write('PAYMENT.CAPTURE.COMPLETED - action() triggered');

        // A payment capture can be triggered via the Store's Admin Orders page, or via the PayPal portal.
        // And it could complete "later", out-of-band, and not in-real-time.
        // Therefore we use the webhook to listen for when PayPal completes the capture, so we can update the order accordingly, if needed.

        // Ensure order's status is updated to reflect that payment has been captured
        // - look up order
        // - ensure it was paid via paypalr
        // - CHECK WHETHER WAS ALREADY CAPTURED, so we're not duplicating status records and notifier calls
        // - update payment status, including a note with any safe-to-share info from webhook


        // Instantiate paypalr module to load its language strings for status messages
        $this->loadCorePaymentModuleAndLanguageStrings();

        $txnID = $this->data['resource']['id'] ?? null;
        $oID = GetPayPalOrderTransactions::getOrderIdFromPayPalTxnId($txnID);

        if (empty($oID)) {
            $this->log->write("\n\n---\nNOTICE: Order ID lookup returned no results.\n\n");
            return;
        }

        // -----
        // Idempotency guard against duplicate capture-processing.
        //
        // A capture can be recorded *synchronously* by the storefront checkout flow
        // (an immediate "Final Sale") or by the admin Orders page *before* PayPal
        // delivers this PAYMENT.CAPTURE.COMPLETED webhook.  WebhookController's
        // webhook_id idempotency guard only suppresses re-delivery of the *same*
        // webhook event; it cannot know that another code path already recorded the
        // capture.  Read our pre-sync record of this capture: if it is already marked
        // COMPLETED, the order-status-history, merchant alert email and the
        // NOTIFY_PAYPALR_FUNDS_CAPTURED notifier were already issued, so we must not
        // repeat them here (duplicate history rows, duplicate emails, double-counted
        // funds).  Reading the status *before* syncPaypalTxns() is essential: the sync
        // itself advances a still-PENDING capture to COMPLETED, and a genuine
        // pending->completed transition must still be processed.
        //
        $already_completed = $this->captureAlreadyCompleted((int)$oID, (string)$txnID);

        // Sync our database with all updates from PayPal
        $this->getApiAndCredentials();
        $ppr_txns = new GetPayPalOrderTransactions($this->paymentModule->code, $this->paymentModule->getCurrentVersion(), $oID, $this->ppr);
        $ppr_txns->syncPaypalTxns();

        if ($already_completed === true) {
            // -----
            // The status-history rows and merchant alert email were already written by
            // whichever code path first recorded this capture as COMPLETED, so skip them.
            //
            // However, the two capture paths fire *different* notifiers:
            //   - Checkout before_process() fires NOTIFY_PAYPALR_FUNDS_CAPTURED (paypalr.php:2053).
            //   - Admin DoCapture fires only NOTIFY_PAYPALR_ADMIN_FUNDS_IN_OUT via addDbTransaction().
            //
            // For admin-initiated captures the webhook is therefore the only opportunity to
            // fire NOTIFY_PAYPALR_FUNDS_CAPTURED.  Detect the admin path by the presence of an
            // AUTHORIZE row: immediate checkout sales are a single create+capture transaction
            // (no AUTHORIZE row); deferred admin captures always follow a prior authorization.
            //
            if ($this->orderWasDeferredCapture((int)$oID)) {
                $this->log->write("PAYMENT.CAPTURE.COMPLETED - capture $txnID for order $oID was admin-initiated; status-history/email already written, firing NOTIFY_PAYPALR_FUNDS_CAPTURED.");
                $zco_notifier->notify('NOTIFY_PAYPALR_FUNDS_CAPTURED', ['webhook' => $this->data]);
            } else {
                $this->log->write("PAYMENT.CAPTURE.COMPLETED - capture $txnID for order $oID was already fully processed by checkout; skipping duplicate status-history, merchant email and NOTIFY_PAYPALR_FUNDS_CAPTURED.");
            }
            return;
        }

        // Update order-status records noting what's happened
        $summary = $this->data['summary'];

        $amount = $this->data['resource']['amount']['value'];
        $comments =
            "Notice: FUNDS CAPTURED. Trans ID: $txnID \n" .
            "Amount: $amount\n$summary\n";

        if ($this->data['resource']['final_capture'] === false) {
            $admin_message = MODULE_PAYMENT_PAYPALR_PARTIAL_CAPTURE;
            $status = -1;
        } else {
            $admin_message = MODULE_PAYMENT_PAYPALR_FINAL_CAPTURE;
            $status = (int)zen_config('MODULE_PAYMENT_PAYPALR_ORDER_STATUS_ID');
            $status = ($status > 0) ? $status : 2;
        }

        // Save update without notifying customer
        zen_update_orders_history($oID, $comments, 'webhook', $status, 0);

        // Notify merchant via email
        zen_update_orders_history($oID, $admin_message, 'webhook', -1, -2);
        $this->paymentModule->sendAlertEmail(MODULE_PAYMENT_PAYPALR_ALERT_SUBJECT_ORDER_ATTN, $comments . "\n" .
            sprintf(MODULE_PAYMENT_PAYPALR_ALERT_ORDER_CREATION, $oID, $this->data['resource']['status'])
        );

        // -----
        // Reaching here means this capture had not previously been recorded as
        // COMPLETED in our records (guarded above), so this is the first time the
        // funds-captured notification is being raised for it.  Fire it so that sites
        // which manage payments are aware of the incoming funds.
        //
        $zco_notifier->notify('NOTIFY_PAYPALR_FUNDS_CAPTURED', ['webhook' => $this->data]);
    }

    /**
     * Determine whether our records already show this capture transaction as COMPLETED.
     *
     * Idempotency guard so a PAYMENT.CAPTURE.COMPLETED webhook does not duplicate the
     * order-status-history, merchant alert email and funds-captured notifier already
     * issued when the capture was recorded by the storefront checkout or admin Orders
     * page.  MUST be called *before* syncPaypalTxns(), which would otherwise advance a
     * still-PENDING capture to COMPLETED and mask a genuine transition.
     */
    protected function captureAlreadyCompleted(int $oID, string $txn_id): bool
    {
        global $db;

        if ($oID <= 0 || $txn_id === '') {
            return false;
        }

        $txn_id = $db->prepare_input($txn_id);
        $check = $db->ExecuteNoCache(
            "SELECT txn_id
               FROM " . TABLE_PAYPAL . "
              WHERE order_id = " . $oID . "
                AND txn_id = '" . $txn_id . "'
                AND txn_type = 'CAPTURE'
                AND payment_status = 'COMPLETED'
              LIMIT 1"
        );
        return !$check->EOF;
    }

    /**
     * Determine whether this order went through an authorize-then-capture flow
     * (as opposed to an immediate checkout capture).
     *
     * Immediate checkout sales are a single create+capture transaction: no AUTHORIZE
     * row is written and NOTIFY_PAYPALR_FUNDS_CAPTURED is fired by before_process().
     *
     * Admin DoCapture always follows a prior authorization, so an AUTHORIZE row exists
     * and only NOTIFY_PAYPALR_ADMIN_FUNDS_IN_OUT is fired — making the webhook the
     * only opportunity to fire NOTIFY_PAYPALR_FUNDS_CAPTURED for that path.
     */
    protected function orderWasDeferredCapture(int $oID): bool
    {
        global $db;

        $check = $db->ExecuteNoCache(
            "SELECT txn_id
               FROM " . TABLE_PAYPAL . "
              WHERE order_id = " . $oID . "
                AND txn_type = 'AUTHORIZE'
              LIMIT 1"
        );
        return !$check->EOF;
    }
}


/*
{
    "id": "WH-7Y7254563A4550640-11V2185806837105M",
    "event_version": "1.0",
    "create_time": "2015-02-17T18:51:33Z",
    "resource_type": "capture",
    "resource_version": "2.0",
    "event_type": "PAYMENT.CAPTURE.COMPLETED",
    "summary": "Payment completed for $ 57.0 USD",
    "resource": {
        "id": "42311647XV020574X",
        "amount": {
            "currency_code": "USD",
            "value": "57.00"
        },
        "final_capture": true,
        "seller_protection": {
            "status": "ELIGIBLE",
            "dispute_categories": [
                "ITEM_NOT_RECEIVED",
                "UNAUTHORIZED_TRANSACTION"
            ]
        },
        "disbursement_mode": "DELAYED",
        "seller_receivable_breakdown": {
            "gross_amount": {
                "currency_code": "USD",
                "value": "57.00"
            },
            "paypal_fee": {
                "currency_code": "USD",
                "value": "2.48"
            },
            "platform_fees": [
                {
                    "amount": {
                        "currency_code": "USD",
                        "value": "5.13"
                    },
                    "payee": {
                        "merchant_id": "CDF4K6247RPFF"
                    }
                }
            ],
            "net_amount": {
                "currency_code": "USD",
                "value": "49.39"
            }
        },
        "invoice_id": "3942613:fav09c49-a3g6-4cbf-1358-f6d241dacea2",
        "custom_id": "d93e4fce-d3af-137c-82fe-1a8101f1ad11",
        "status": "COMPLETED",
        "supplementary_data": {
            "related_ids": {
                "order_id": "8U481631H66031715"
            }
        },
        "create_time": "2022-08-26T18:29:50Z",
        "update_time": "2022-08-26T18:29:50Z",
        "links": [
            {
                "href": "https:\/\/api.paypal.com\/v2\/payments\/captures\/0KF12345VG343800K",
                "rel": "self",
                "method": "GET"
            },
            {
                "href": "https:\/\/api.paypal.com\/v2\/payments\/captures\/0KF12345VG343880K\/refund",
                "rel": "refund",
                "method": "POST"
            },
            {
                "href": "https:\/\/api.paypal.com\/v2\/checkout\/orders\/8U431637H66031715",
                "rel": "up",
                "method": "GET"
            }
        ]
    }
}
 */
