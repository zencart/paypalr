<?php
/**
 * PayPal REST API Webhook Contract
 * This abstract class is the base for all configured webhook handler classes.
 *
 * @copyright Copyright 2003-2026 Zen Cart Development Team
 * @license https://www.zen-cart.com/license/2_0.txt GNU Public License V2.0
 * @version $Id: lat9 2026 Mar 17 New in v2.2.1 $
 *
 * Last updated: v2.1.0
 */

namespace PayPalRestful\Webhooks;

use PayPalRestful\Api\PayPalRestfulApi;
use PayPalRestful\Common\Logger;

abstract class WebhookHandlerContract
{
    protected array $eventsHandled = [];

    protected WebhookObject $webhook;
    protected array $data;
    protected string $eventType;

    protected Logger $log;

    protected PayPalRestfulApi $ppr;
    protected \paypalr $paymentModule;


    public function __construct(WebhookObject $webhook)
    {
        $this->webhook = $webhook;
        $this->data = $this->webhook->getJsonBody();
        $this->eventType = $this->data['event_type'];

        $this->log = new Logger();
    }

    abstract public function action(): void;

    /**
     * Instantiate paypalr payment module, including its language string dependencies.
     *
     * Zen Cart's payment class publishes $GLOBALS['paypalr'] only when the module
     * reports itself enabled, so a module that disabled itself while loading -- because
     * PayPal was unreachable, say -- leaves nothing to assign here.  Throw, rather than
     * letting the typed-property assignment raise a bare TypeError: ppr_webhook.php
     * answers 500 so PayPal retries the delivery once the module is usable again, and
     * the reason reaches the error log (GitHub issue #51).
     */
    protected function loadCorePaymentModuleAndLanguageStrings(): void
    {
        require DIR_WS_CLASSES . 'payment.php';
        $payment_modules = new \payment ('paypalr');

        $payment_module = $GLOBALS[$payment_modules->selected_module ?? ''] ?? null;
        if (!($payment_module instanceof \paypalr)) {
            throw new \RuntimeException(
                'Webhook ' . $this->eventType . ' cannot be processed: the paypalr payment module is not currently loadable. ' .
                'Check that it is installed and enabled, and that PayPal is reachable from this server.'
            );
        }

        $this->paymentModule = $payment_module;
    }

    /**
     * Call this before making API calls if needed by the webhook
     * It will grab the active merchant credentials and instantiate the API class object.
     */
    protected function getApiAndCredentials(): bool
    {
        if (!empty($this->ppr)) {
            return true;
        }

        [$client_id, $secret] = \paypalr::getEnvironmentInfo();
        if ($client_id !== '' && $secret !== '') {
            $this->ppr = new PayPalRestfulApi(zen_config('MODULE_PAYMENT_PAYPALR_SERVER'), $client_id, $secret);
            return true;
        }

        return false;
    }

    /**
     * Determine whether the selected class should respond.
     * In the case of PayPal webhooks, we currently just check whether the selected class
     * has the EventType registered as a property (it should, because filename is based on event name).
     * Other checks could be added by overriding this function.
     */
    public function eventTypeIsSupported(): bool
    {
        if (!empty($this->eventsHandled) && \in_array($this->eventType, $this->eventsHandled, true)) {
            return true;
        }

        $this->log->write('WARNING: ' . __CLASS__ . ' does not support requested action: [' . $this->eventType . ']');
        return false;
    }

    public function getEventType(): string
    {
        return $this->eventType;
    }

}
