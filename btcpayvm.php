<?php
/**
 * @author        BTCPay Server
 * @package       VirtueMart
 * @subpackage    payment
 * @copyright     Copyright (C) 2022 BTCPay Server. All rights reserved.
 * @license       http://www.gnu.org/copyleft/gpl.html GNU/GPL
 */

use BTCPayServer\Client\Webhook;
use BTCPayServer\Util\PreciseNumber;

defined('_JEXEC') or die(
  'Direct Access to ' . basename(
    __FILE__
  ) . 'is not allowed.'
);

if (!class_exists('vmPSPlugin')) {
    require(JPATH_VM_PLUGINS . DIRECTORY_SEPARATOR . 'vmpsplugin.php');
}

class plgVMPaymentBTCPayVM extends vmPSPlugin
{

    private $callback;

    // instance of class
    public static $_this = false;

    function __construct(&$subject, $config)
    {
        parent::__construct($subject, $config);
        /**
         * Here we should assign data for two payment tables to work with. Some additional initializations can be done.
         */
        $jlang = JFactory::getLanguage();
        $jlang->load('plg_vmpayment_btcpayvm', JPATH_ADMINISTRATOR, null, true);
        $this->_loggable = true;
        $this->_debug = true;
        /**
         * assign columns for btcpayvm payment plugin table #_virtuemart_payment_plg_btcpayvm
         */
        $this->tableFields = array_keys($this->getTableSQLFields());
        $this->_tablepkey = 'id'; //virtuemart_BTCPAYVM_id';
        $this->_tableId = 'id'; //'virtuemart_BTCPAYVM_id';
        //assign payment parameters from plugin configuration to paymentmethod table #_virtuemart_paymentmethods (payment_params column)
        $varsToPush = $this->getVarsToPush();
        $this->setConfigParameterable(
          $this->_configTableFieldName,
          $varsToPush
        );

        // Load our dependencies.
        $autoloader = dirname(__FILE__) . '/vendor/autoload.php';
        if (file_exists($autoloader)) {
            /** @noinspection PhpIncludeInspection */
            require_once $autoloader;
        }
    }
    //===============================================================================
    // BACKEND
    /**
     * Functions to initialize parameters from configuration
     * to be saved in payment table #_virtuemart_paymentmethods (payment_params
     * field)
     *
     *
     * @param type $name
     * @param type $id
     * @param type $data
     *
     * @return type
     */
    function plgVmDeclarePluginParamsPayment($name, $id, &$data)
    {
        return $this->declarePluginParams('payment', $name, $id, $data);
    }

    function plgVmDeclarePluginParamsPaymentVM3(&$data)
    {
        return $this->declarePluginParams('payment', $data);
    }

    function plgVmSetOnTablePluginParamsPayment($name, $id, &$table)
    {
        return $this->setOnTablePluginParams($name, $id, $table);
    }

    /**
     * Create the #_virtuemart_payment_plg_btcpayvm table for this plugin if it
     * does not yet exist.
     */
    protected function getVmPluginCreateTableSQL()
    {
        return $this->createTableSQL('Payment BTCPayVM Table');
    }

    /**
     * Fields to create the payment table
     *
     * @return array SQL Fileds
     */
    function getTableSQLFields()
    {
        return [
          'id'                          => 'bigint(1) unsigned NOT NULL AUTO_INCREMENT',
          'virtuemart_order_id'         => 'int(11) UNSIGNED ',
          'order_number'                => 'char(64)',
          'virtuemart_paymentmethod_id' => 'mediumint(1) UNSIGNED ',
          'payment_name'                => 'char(255) NOT NULL DEFAULT \'\' ',
          'payment_order_total'         => 'decimal(30,8) NOT NULL DEFAULT \'0.00000\' ',
          'refund_total'                => 'decimal(30,8) NOT NULL DEFAULT \'0.00000\' ',
          'payment_currency'            => 'char(3) ',
          'cost_per_transaction'        => ' decimal(10,2) ',
          'cost_percent_total'          => ' decimal(10,2) ',
          'tax_id'                      => 'smallint(1) ',
          'user_session'                => 'varchar(255)',
            // BTCPay data.
          'btcpay_invoice_id'           => 'char(64)',
          'btcpay_invoice_status'       => 'char(10)',
          'btcpay_invoice_additional_status' => 'char(20)',
          'btcpay_redirect'             => 'char(255)',
          'btcpay_destination'          => 'text',
          'btcpay_amount'               => 'decimal(30,8)',
          'btcpay_total_paid'           => 'decimal(30,8)',
          'btcpay_network_fee'          => 'decimal(30,8)',
          'btcpay_rate'                 => 'decimal(30,8)',
          'btcpay_rate_formatted'       => 'decimal(30,8)',
        ];
    }

    /**
     * Create the table for this plugin if it does not yet exist.
     * This functions checks if the called plugin is active one.
     * When yes it is calling the standard method to create the tables
     *
     * @author Valerie Isaksen
     *
     * We must reimplement this trigger for joomla 1.7
     *
     */
    function plgVmOnStoreInstallPaymentPluginTable($jplugin_id)
    {
        return $this->onStoreInstallPluginTable($jplugin_id);
    }

    /**
     * This method is called after payer set confirm purchase in check out.
     * Redirects to BTCPay Server.
     */
    function plgVmConfirmedOrder($cart, $order)
    {
        $pm_id = $order['details']['BT']->virtuemart_paymentmethod_id;
        if (!($method = $this->getVmPluginMethod($pm_id))) {
            return null; // Another method was selected, do nothing
        }
        if (!$this->selectedThisElement($method->payment_element)) {
            return false;
        }
        if (!class_exists('VirtueMartModelOrders')) {
            require(JPATH_VM_ADMINISTRATOR . DIRECTORY_SEPARATOR . 'models' . DIRECTORY_SEPARATOR . 'orders.php');
        }
        if (!class_exists('VirtueMartModelCurrency')) {
            require(JPATH_VM_ADMINISTRATOR . DIRECTORY_SEPARATOR . 'models' . DIRECTORY_SEPARATOR . 'currency.php');
        }

        $session = JFactory::getSession();
        $return_context = $session->getId();
        $lang = JFactory::getLanguage();
        $filename = 'com_virtuemart';
        $lang->load($filename, JPATH_ADMINISTRATOR);

        $paymentCurrency = CurrencyDisplay::getInstance(
          $method->payment_currency
        );
        $totalInPaymentAmount = round(
          $paymentCurrency->convertCurrencyTo(
            $method->payment_currency,
            $order['details']['BT']->order_total,
            false
          ),
          8
        );
        if ($totalInPaymentAmount <= 0) {
            vmInfo(vmText::_('VMPAYMENT_BTCPAYVM_PAYMENT_AMOUNT_INCORRECT'));

            return false;
        }
        /**
         * Prepare payments parameters.
         */
        $trx_id = $order['details']['BT']->virtuemart_order_id;
        $order_number = $order['details']['BT']->order_number;
        $user_id = $order['details']['BT']->virtuemart_user_id;

        if (!$user_id) {
            $user_id = '';
        }

        // Get config from settings.
        $metadata = [];

        $metadata['taxIncluded'] = PreciseNumber::parseString(
          $order['details']['BT']->order_tax
        );
        //todo: pos data
        //$metadata['posData'] = $this->preparePosMetadata();

        // Set checkout options.
        $returnUrl = JURI::root() . "index.php?option=com_virtuemart&amp;view=pluginresponse&amp;task=pluginresponsereceived&amp;on=${order_number}&amp;pm=${pm_id}";
        $checkoutOptions = \BTCPayServer\Client\InvoiceCheckoutOptions::create(
          null,
          null,
          null,
          null,
          null,
          $returnUrl,
          null,
          null
        );

        // Create the invoice on BTCPay Server.
        try {
            $client = new \BTCPayServer\Client\Invoice(
              $method->api_url,
              $method->api_key
            );

            $invoice = $client->createInvoice(
              $method->store_id,
              $paymentCurrency->ensureUsingCurrencyCode(
                $paymentCurrency->getId()
              ),
              PreciseNumber::parseString($totalInPaymentAmount),
              $trx_id,
              $order['details']['BT']->email,
              $metadata,
              $checkoutOptions
            );

        } catch (\Throwable $e) {
            $this->logInfo($e->getMessage(), 'error', true);
            self::redirectToCart('VMPAYMENT_BTCPAYVM_ERROR_CREATING_INVOICE');
        }

        /**
         * Prepare data that should be stored in the database for btcpayvm
         * payment method.
         */
        $dbValues['user_session'] = $return_context;
        $dbValues['order_number'] = $order_number;
        $dbValues['payment_name'] = $this->renderPluginName($method, $order);
        $dbValues['virtuemart_paymentmethod_id'] = $cart->virtuemart_paymentmethod_id;
        $dbValues['payment_order_total'] = $totalInPaymentAmount;
        $dbValues['payment_currency'] = $paymentCurrency;
        $dbValues['refund_total'] = 0;
        $dbValues['cost_per_transaction'] = $method->cost_per_transaction;
        $dbValues['cost_percent_total'] = $method->cost_percent_total;
        $dbValues['tax_id'] = $method->tax_id;
        // BTCPay values.
        $dbValues['btcpay_invoice_id'] = $invoice->getData()['id'];
        $dbValues['btcpay_invoice_status'] = $invoice->getStatus();
        $dbValues['btcpay_redirect'] = $invoice->getData()['checkoutLink'];

        // save prepared data to btcpayvm table.
        $this->storePSPluginInternalData($dbValues);

        /**
         * Assign the pending status.
         */
        $modelOrder = VmModel::getModel('orders');
        $order['order_status'] = $method->status_pending;
        $order['customer_notified'] = 1;
        $order['comments'] = vmText::sprintf(
          'VMPAYMENT_BTCPAYVM_WIDGET_STATUS',
          $trx_id
        );
        $modelOrder->updateStatusForOneOrder(
          $order['details']['BT']->virtuemart_order_id,
          $order,
          true
        );
        /**
         * Do nothing while the order will not be confirmed.
         */
        $cart->_confirmDone = false;
        $cart->_dataValidated = false;
        $cart->setCartIntoSession();

        $session->clear('btcpayvm', 'vm');

        $cart->emptyCart();

        // Redirect to BTCPay Server.
        $app = JFactory::getApplication();
        $app->redirect(JRoute::_($invoice->getData()['checkoutLink'], false));
    }
    //========================================================================================
    //********************  Here are methods used in processing a callback  ***************//
    //========================================================================================
    function plgVmOnPaymentNotification()
    {

        if (!class_exists('VirtueMartCart')) {
            require(JPATH_VM_SITE . DIRECTORY_SEPARATOR . 'helpers' . DIRECTORY_SEPARATOR . 'cart.php');
        }
        if (!class_exists('shopFunctionsF')) {
            require(JPATH_VM_SITE . DIRECTORY_SEPARATOR . 'helpers' . DIRECTORY_SEPARATOR . 'shopfunctionsf.php');
        }
        if (!class_exists('VirtueMartModelOrders')) {
            require(JPATH_VM_ADMINISTRATOR . DIRECTORY_SEPARATOR . 'models' . DIRECTORY_SEPARATOR . 'orders.php');
        }

        $virtuemart_paymentmethod_id = vRequest::getInt('pm', 0);
        if(!($this->_currentMethod = $this->getVmPluginMethod($virtuemart_paymentmethod_id))) {
            return NULL; // Another method was selected, do nothing
        }

        if(!$this->selectedThisElement($this->_currentMethod->payment_element)) {
            return FALSE;
        }

        $app = JFactory::getApplication();
        /** @var JInput $input */
        $input = $app->input;
        $invoiceId = $input->json->getString('invoiceId');
        $event = $input->json->getString('type');
        $this->logInfo(
          'Received webhook event: ' . $event,
          'message'
        );

        if (!($this->_checkWebhookSignature())) {
                $this->logInfo(
                  'getDataByInvoiceId payment not found: exit ',
                  'ERROR'
                );
           $this->exitf('Error validating webhook signature.');
        }

        if (!($payment_table = $this->getDataByInvoiceId($invoiceId))) {
            $this->logInfo(
              'getDataByInvoiceId payment not found: exit ',
              'ERROR'
            );
            $this->exitf('ERR');
        }

        $virtuemart_order_id = $payment_table->virtuemart_order_id;
        $order_info = VirtueMartModelOrders::getOrder($virtuemart_order_id);

        $payment_table->order_status = $order_info['details']['BT']->order_status;
        $payment_table->order_info = $order_info;

        switch ($event) {
            case 'InvoiceCreated':
                // Do nothing.
                break;
            case 'InvoiceReceivedPayment':
                // Todo: Check if after expiration and update order status? + add comment (or better on InvoicePaymentSettled?)
                break;
            case 'InvoicePaymentSettled':
                // see above.
                break;
            case 'InvoiceProcessing':
                // Update order.
                $data = [
                  'order_status'      => $this->_currentMethod->status_processing,
                  'customer_notified' => 1,
                  'comments'          => vmText::sprintf(
                    'VMPAYMENT_BTCPAYVM_PAYMENT_STATUS_PROCESSING',
                    $payment_table->order_number
                  ),
                ];
                $this->_updateOrder($virtuemart_order_id, $data);
                // Update payment table BTCPay invoice status.
                $this->storePSPluginInternalData(['btcpay_invoice_status' => 'Processing'] );
                break;
            case 'InvoiceExpired':
                // Update order.
                $data = [
                  'order_status'      => $this->_currentMethod->status_expired,
                  'customer_notified' => 1,
                  'comments'          => vmText::sprintf(
                    'VMPAYMENT_BTCPAYVM_PAYMENT_STATUS_EXPIRED',
                    $payment_table->order_number
                  ),
                ];
                $this->_updateOrder($virtuemart_order_id, $data);
                // Update payment table BTCPay invoice status.
                $this->storePSPluginInternalData(['btcpay_invoice_status' => 'Expired'] );
                break;
            case 'InvoiceSettled':
                // Update order.
                $data = [
                  'order_status'      => $this->_currentMethod->status_settled,
                  'customer_notified' => 1,
                  'comments'          => vmText::sprintf(
                    'VMPAYMENT_BTCPAYVM_PAYMENT_STATUS_SETTLED',
                    $payment_table->order_number
                  ),
                ];
                $this->_updateOrder($virtuemart_order_id, $data);
                // Update payment table BTCPay invoice status.
                $this->storePSPluginInternalData(['btcpay_invoice_status' => 'Settled'] );
                break;
            case 'InvoiceInvalid':
                // Update order.
                $data = [
                  'order_status'      => $this->_currentMethod->status_invalid,
                  'customer_notified' => 1,
                  'comments'          => vmText::sprintf(
                    'VMPAYMENT_BTCPAYVM_PAYMENT_STATUS_INVALID',
                    $payment_table->order_number
                  ),
                ];
                $this->_updateOrder($virtuemart_order_id, $data);
                // Update payment table BTCPay invoice status.
                $this->storePSPluginInternalData(['btcpay_invoice_status' => 'Invalid'] );
                break;

            default:
                // Do nothing.
        }
    }

    private function log($msg)
    {
        $this->logInfo($msg, 'message');
    }

    /**
     * Load payment method table data by btcpay invoice id.
     *
     * Similar to VM getDataByOrderId().
     */
    public function getDataByInvoiceId($invoice_id)
    {
        $t = substr($this->_tablename,3);
        if(!vmTable::checkTableExists($t)) return false;
        $db = JFactory::getDBO ();
        $q = 'SELECT * FROM `' . $this->_tablename . '` '
          . 'WHERE `btcpay_invoice_id` = "' . $invoice_id . '"';

        $db->setQuery($q);
        return $db->loadObject();
    }

    private function _updateOrder($order_id, $order_data)
    {
        $modelOrder = VmModel::getModel('orders');
        $modelOrder->updateStatusForOneOrder($order_id, $order_data, true);
    }

    private function _checkWebhookSignature()
    {
        // Get signature header.
        $signature = null;
        $headers = getallheaders();
        foreach ($headers as $key => $value) {
            if (strtolower($key) === 'btcpay-sig') {
                $signature = $value;
            }
        }

        // Request raw Data.
        $rawPostData = file_get_contents("php://input");

        // Webhook secret from config.
        $webhookSecret = $this->_currentMethod->webhook_secret;

        // Validate Webhook.
        if (!isset($signature) || !Webhook::isIncomingWebhookRequestValid($rawPostData, $signature, $webhookSecret)) {
            $msg = 'Failed to validate signature of webhook request, aborting.';
            $this->logInfo($msg, 'error');
            $this->exitf($msg);
        }

        return true;
    }

    private function _setRefund($total_refunded, $payment_table)
    {
        $dbValues['id'] = $payment_table->id;
        $dbValues['virtuemart_order_id'] = $payment_table->virtuemart_order_id;
        $dbValues['user_session'] = $payment_table->user_session;
        $dbValues['order_number'] = $payment_table->order_number;
        $dbValues['payment_name'] = $payment_table->payment_name;
        $dbValues['virtuemart_paymentmethod_id'] = $payment_table->virtuemart_paymentmethod_id;
        $dbValues['payment_order_total'] = $payment_table->payment_order_total;
        $dbValues['payment_currency'] = $payment_table->payment_currency;
        $dbValues['refund_total'] = $total_refunded;
        $dbValues['cost_per_transaction'] = $payment_table->cost_per_transaction;
        $dbValues['cost_percent_total'] = $payment_table->cost_percent_total;
        $dbValues['payment_currency'] = $payment_table->payment_currency;
        $dbValues['tax_id'] = $payment_table->tax_id;
        $this->storePSPluginInternalData(
          $dbValues,
          0,
          true
        ); // save prepared data to btcpayvm database
    }

    /**
     * plgVmOnPaymentResponseReceived
     * This event is fired when the  method returns to the shop after the
     * transaction
     *
     * The method itself should send in the URL the parameters needed
     * NOTE for Plugin developers:
     * If the plugin is NOT actually executed (not the selected payment
     * method), this method must return NULL
     *
     * @param int  $virtuemart_order_id : should return the virtuemart_order_id
     * @param text $html                : the html to display
     *
     * @return mixed Null when this method was not selected, otherwise the true
     *               or false
     *
     * @author Valerie Isaksen
     *
     */
    // actions after response is received, to redirect user to the order result page after confirmation.
    function plgVmOnPaymentResponseReceived(&$html)
    {

        if (!class_exists('VirtueMartCart')) {
            require(JPATH_VM_SITE . DIRECTORY_SEPARATOR . 'helpers' . DIRECTORY_SEPARATOR . 'cart.php');
        }
        if (!class_exists('shopFunctionsF')) {
            require(JPATH_VM_SITE . DIRECTORY_SEPARATOR . 'helpers' . DIRECTORY_SEPARATOR . 'shopfunctionsf.php');
        }
        if (!class_exists('VirtueMartModelOrders')) {
            require(JPATH_VM_ADMINISTRATOR . DIRECTORY_SEPARATOR . 'models' . DIRECTORY_SEPARATOR . 'orders.php');
        }

        $order_number = vRequest::getString('on', '');
        $virtuemart_paymentmethod_id = vRequest::getInt('pm', '');
        if (empty($order_number) or empty($virtuemart_paymentmethod_id) or !$this->selectedThisByMethodId(
            $virtuemart_paymentmethod_id
          )) {
            return null;
        }

        if (!($virtuemart_order_id = VirtueMartModelOrders::getOrderIdByOrderNumber(
          $order_number
        ))) {
            $this->logInfo(
              'getOrderIdByOrderNumber payment not found: exit ',
              'ERROR'
            );

            return null;
        }

        if (!($payment_table = $this->getDataByOrderNumber($order_number))) {
            $this->logInfo(
              'getDataByOrderId payment not found: exit ',
              'ERROR'
            );

            return null;
        }


        if (!($method = $this->getVmPluginMethod($payment_table->virtuemart_paymentmethod_id))) {
            $this->log('Error in payment method');
            $this->exitf('ERR');
        }

        $orderModel = new VirtueMartModelOrders();
        $order_info = $orderModel->getOrder($virtuemart_order_id);

        // Bail early here if the order is already confirmed 'C' or payment in
        // progress 'U' (by webhook), no need to change order state anymore.
        $skipOrderStates = ['C', 'U'];
        if (in_array($order_info['details']['BT']->order_status, $skipOrderStates)) {
            $cart = VirtueMartCart::getCart();
            $cart->emptyCart();

            return true;
        }

        $payment_table->order_status = $order_info['details']['BT']->order_status;
        $payment_table->order_info   = $order_info;

        if (!($invoice = $this->btcPayGetInvoice($virtuemart_paymentmethod_id, $payment_table->btcpay_invoice_id))) {
            $this->logInfo(
              'Error loading invoice from BTCPay Server.',
              'ERROR'
            );
        }

        $invStatus = $invoice->getStatus();
        $invAdditionalStatus = $invoice->getData()['additionalStatus'];
        // todo: add additional status

        // Update order status with invoice status.
        switch ($invStatus) {
            case 'Processing':
                $data = [
                  'order_status'      => $method->status_processing,
                  'customer_notified' => 1,
                  'comments'          => vmText::sprintf('VMPAYMENT_BTCPAYVM_PAYMENT_STATUS_PROCESSING', $payment_table->order_number),
                ];
                $this->_updateOrder($virtuemart_order_id, $data);
                vmInfo(vmText::_('VMPAYMENT_BTCPAYVM_PAYMENT_PROCESSING'));
                break;
            case 'Settled':
                $data = [
                  'order_status'      => $method->status_settled,
                  'customer_notified' => 1,
                  'comments'          => vmText::sprintf('VMPAYMENT_BTCPAYVM_PAYMENT_STATUS_SETTLED', $payment_table->order_number),
                ];
                $this->_updateOrder($virtuemart_order_id, $data);
                vmInfo(vmText::_('VMPAYMENT_BTCPAYVM_PAYMENT_SETTLED'));
                break;
            case 'Expired':
                $data = [
                  'order_status'      => $method->status_expired,
                  'customer_notified' => 1,
                  'comments'          => vmText::sprintf('VMPAYMENT_BTCPAYVM_PAYMENT_STATUS_EXPIRED', $payment_table->order_number),
                ];
                $this->_updateOrder($virtuemart_order_id, $data);
                vmInfo(vmText::_('VMPAYMENT_BTCPAYVM_PAYMENT_EXPIRED'));
                break;
            case 'Invalid':
                $data = [
                  'order_status'      => $method->status_invalid,
                  'customer_notified' => 1,
                  'comments'          => vmText::sprintf('VMPAYMENT_BTCPAYVM_PAYMENT_STATUS_INVALID', $payment_table->order_number),
                ];
                $this->_updateOrder($virtuemart_order_id, $data);
                vmInfo(vmText::_('VMPAYMENT_BTCPAYVM_PAYMENT_INVALID'));
                break;
            default:
                // Payment state "New", do nothing.
        }

        $cart = VirtueMartCart::getCart();
        $cart->emptyCart();

        return true;
    }

    /**
     * Fetch invoice from BTCPay server.
     *
     * @param $virtuemart_paymentmethod_id
     * @param $invoice_id
     *
     * @return \BTCPayServer\Result\Invoice|null
     *
     * @since version
     */
    public function btcPayGetInvoice($virtuemart_paymentmethod_id, $invoice_id)
    {
        $method = $this->getVmPluginMethod(
          $virtuemart_paymentmethod_id
        );

        $invoice = null;

        try {
            $client = new \BTCPayServer\Client\Invoice(
              $method->api_url,
              $method->api_key
            );

            $invoice = $client->getInvoice($method->store_id, $invoice_id);

        } catch (\Throwable $e) {
            $this->logInfo('Error fetching invoice: ' . $e->getMessage(), 'error');
        }

        return $invoice;
    }

    /**
     * What to do after payment cancel
     */
    function plgVmOnUserPaymentCancel()
    {
        if (!class_exists('VirtueMartModelOrders')) {
            require(JPATH_VM_ADMINISTRATOR . DIRECTORY_SEPARATOR . 'models' . DIRECTORY_SEPARATOR . 'orders.php');
        }
        $order_number = vRequest::getString('on', '');
        $virtuemart_paymentmethod_id = vRequest::getInt('pm', '');
        if (empty($order_number) or empty($virtuemart_paymentmethod_id) or !$this->selectedThisByMethodId(
            $virtuemart_paymentmethod_id
          )) {
            return null;
        }
        if (!($virtuemart_order_id = VirtueMartModelOrders::getOrderIdByOrderNumber(
          $order_number
        ))) {
            $this->logInfo(
              'getOrderIdByOrderNumber payment not found: exit ',
              'ERROR'
            );

            return null;
        }
        if (!($payment_table = $this->getDataByOrderNumber($order_number))) {
            $this->logInfo(
              'getDataByOrderId payment not found: exit ',
              'ERROR'
            );

            return null;
        }
        vmInfo(vmText::_('VMPAYMENT_BTCPAYVM_PAYMENT_CANCELLED'));
        $session = JFactory::getSession();
        $return_context = $session->getId();
        if (strcmp($payment_table->user_session, $return_context) === 0) {
            $this->handlePaymentUserCancel($virtuemart_order_id);
        }

        return true;
    }

    public function exitf($msg, $fiscal = [])
    {
        if (isset($this->callback['FORMAT']) && $this->callback['FORMAT'] == 'json') {
            $msg = ["response" => $msg];
            if ($fiscal && isset($this->callback['OFD']) && $this->callback['OFD'] == 1) {
                $msg['ofd'] = $fiscal;
            }
            $msg = json_encode($msg);
        }
        ob_start();
        $this->log('Process callback ' . vmText::sprintf($msg));
        ob_end_clean();
        echo $msg;
        jexit();
    }

    //==========================================================================================
    //***********      Additional standard vmpayment methods   *****************************
    //==========================================================================================
    //FRONTEND
    /**
     * Display stored order payment data
     *
     */
    function plgVmOnShowOrderBEPayment(
      $virtuemart_order_id,
      $virtuemart_payment_id
    ) {
        if (!$this->selectedThisByMethodId($virtuemart_payment_id)) {
            return null; // Another method was selected, do nothing
        }
        $db = JFactory::getDBO();
        $q = 'SELECT * FROM `' . $this->_tablename . '` '
          . 'WHERE `virtuemart_order_id` = ' . $virtuemart_order_id;
        $db->setQuery($q);
        if (!($payment_table = $db->loadObject())) {
            vmWarn(500, $q . " " . $db->getErrorMsg());

            return '';
        }
        $this->getPaymentCurrency($payment_table);

        $html = '<table class="adminlist">' . "\n";
        $html .= $this->getHtmlHeaderBE();
        $html .= $this->getHtmlRowBE(
          'BTCPAYVM_PAYMENT_NAME',
          $payment_table->payment_name
        );
        $html .= $this->getHtmlRowBE(
          'BTCPAYVM_PAYMENT_TOTAL_CURRENCY',
          $payment_table->payment_order_total . ' ' . $payment_table->payment_currency
        );
        $html .= '</table>' . "\n";

        return $html;
    }

    /**
     * Calculations for this payment method and final cost with tax calculation
     * etc.
     */
    function getCosts(VirtueMartCart $cart, $method, $cart_prices)
    {
        if (preg_match('/%$/', $method->cost_percent_total)) {
            $cost_percent_total = substr($method->cost_percent_total, 0, -1);
        } else {
            $cost_percent_total = $method->cost_percent_total;
        }

        return ($method->cost_per_transaction + ($cart_prices['salesPrice'] * $cost_percent_total * 0.01));
    }

    /**
     * Check if the payment conditions are fulfilled for this payment method
     *
     * @param $cart_prices : cart prices
     * @param $payment
     *
     * @return true: if the conditions are fulfilled, false otherwise
     *
     * @author: Valerie Isaksen
     *
     */
    protected function checkConditions($cart, $method, $cart_prices)
    {
        $address = (($cart->ST == 0) ? $cart->BT : $cart->ST);
        $method->min_amount = (!empty($method->min_amount) ? $method->min_amount : 0);
        $method->max_amount = (!empty($method->max_amount) ? $method->max_amount : 0);

        $totalFormatted = $cart_prices['salesPrice'];
        $totalFormatted_cond = ($totalFormatted >= $method->min_amount and $totalFormatted <= $method->max_amount
          or
          ($method->min_amount <= $totalFormatted and ($method->max_amount == 0)));
        if (!$totalFormatted_cond) {
            return false;
        }
        $countries = [];
        if (!empty($method->countries)) {
            if (!is_array($method->countries)) {
                $countries[0] = $method->countries;
            } else {
                $countries = $method->countries;
            }
        }
        /**
         * probably did not gave his BT:ST address
         */
        if (!is_array($address)) {
            $address = [];
            $address['virtuemart_country_id'] = 0;
        }
        if (!isset($address['virtuemart_country_id'])) {
            $address['virtuemart_country_id'] = 0;
        }
        if (count($countries) == 0 || in_array(
            $address['virtuemart_country_id'],
            $countries
          ) || count($countries) == 0) {
            return true;
        }

        return false;
    }

    //=========================================================================================================================
    /*
     * We must reimplement this triggers for joomla 1.7
     */
    /**
     * This event is fired after the payment method has been selected. It can
     * be used to store additional payment info in the cart.
     *
     * @param VirtueMartCart $cart : the actual cart
     *
     * @return null if the payment was not selected, true if the data is valid,
     *              error message if the data is not vlaid
     *
     * @author Max Milbers
     * @author Valerie isaksen
     *
     */
    public function plgVmOnSelectCheckPayment(VirtueMartCart $cart, &$msg)
    {
        return $this->OnSelectCheck($cart);
    }

    /**
     * plgVmDisplayListFEPayment
     * This event is fired to display the pluginmethods in the cart (edit
     * shipment/payment) for exampel
     *
     * @param object  $cart     Cart object
     * @param integer $selected ID of the method selected
     *
     * @return boolean True on succes, false on failures, null when this plugin
     *                 was not selected. On errors, JError::raiseWarning (or
     *                 JError::raiseError) must be used to set a message.
     *
     * @author Valerie Isaksen
     * @author Max Milbers
     */
    public function plgVmDisplayListFEPayment(
      VirtueMartCart $cart,
      $selected = 0,
      &$htmlIn
    ) {
        return $this->displayListFE($cart, $selected, $htmlIn);
    }

    //===============================================================================
    //FRONTEND
    /*
     * plgVmonSelectedCalculatePricePayment
     * Calculate the price (value, tax_id) of the selected method
     * It is called by the calculator
     * This function does NOT to be reimplemented. If not reimplemented, then the default values from this function are taken.
     * @author Valerie Isaksen
     * @cart: VirtueMartCart the current cart
     * @cart_prices: array the new cart prices
     * @return null if the method was not selected, false if the shiiping rate is not valid any more, true otherwise
     *
     *
     */

    public function plgVmonSelectedCalculatePricePayment(
      VirtueMartCart $cart,
      array &$cart_prices,
      &$cart_prices_name
    ) {
        return $this->onSelectedCalculatePrice(
          $cart,
          $cart_prices,
          $cart_prices_name
        );
    }

    //===================================================================================
    function plgVmgetPaymentCurrency(
      $virtuemart_paymentmethod_id,
      &$paymentCurrencyId
    ) {
        if (!($method = $this->getVmPluginMethod(
          $virtuemart_paymentmethod_id
        ))) {
            return null; // Another method was selected, do nothing
        }
        if (!$this->selectedThisElement($method->payment_element)) {
            return false;
        }
        $this->getPaymentCurrency($method);

        $paymentCurrencyId = $method->payment_currency;
    }

    //==============================================================

    /**
     * plgVmOnCheckAutomaticSelectedPayment
     * Checks how many plugins are available. If only one, the user will not
     * have the choice. Enter edit_xxx page The plugin must check first if it
     * is the correct type
     *
     * @param VirtueMartCart cart: the cart object
     *
     * @return null if no plugin was found, 0 if more then one plugin was
     *              found,  virtuemart_xxx_id if only one plugin is found
     *
     * @author Valerie Isaksen
     *
     */
    function plgVmOnCheckAutomaticSelectedPayment(
      VirtueMartCart $cart,
      array $cart_prices = [],
      &$paymentCounter
    ) {
        return $this->onCheckAutomaticSelected(
          $cart,
          $cart_prices,
          $paymentCounter
        );
    }

    //======================================================================

    /**
     * This method is fired when showing the order details in the frontend.
     * It displays the method-specific data.
     *
     * @param integer $order_id The order ID
     *
     * @return mixed Null for methods that aren't active, text (HTML) otherwise
     * @author Max Milbers
     * @author Valerie Isaksen
     */
    public function plgVmOnShowOrderFEPayment(
      $virtuemart_order_id,
      $virtuemart_paymentmethod_id,
      &$payment_name
    ) {
        $this->onShowOrderFE(
          $virtuemart_order_id,
          $virtuemart_paymentmethod_id,
          $payment_name
        );
    }

    //============================================================================

    /**
     * This method is fired when showing when priting an Order
     * It displays the payment method-specific data.
     *
     * @param integer $_virtuemart_order_id The order ID
     * @param integer $method_id            method used for this order
     *
     * @return mixed Null when for payment methods that were not selected, text
     *               (HTML) otherwise
     * @author Valerie Isaksen
     */
    function plgVmonShowOrderPrintPayment($order_number, $method_id)
    {
        return $this->onShowOrderPrint($order_number, $method_id);
    }

    protected function preparePosMetadata(
      $order_id,
      $order_number,
      $order_url
    ): string {
        $posData = [
          'JoomlaVirtuemart' => [
            'Order ID'       => $order_id,
            'Order Number'   => $order_number,
            'Order URL'      => $order_url,
            'Plugin Version' => $this->get('version'),
          ],
        ];

        return json_encode($posData, JSON_THROW_ON_ERROR);
    }

    /**
     * Redirect to cart.
     *
     * @since 1.0.4
     */
    private static function redirectToCart($message = 'VMPAYMENT_BTCPAYVM_ERROR_DEFAULT') {
        $app = JFactory::getApplication();
        $app->enqueueMessage(vmText::_($message), 'error');
        $app->redirect(JRoute::_('index.php?option=com_virtuemart&view=cart&lg=&Itemid=' . vRequest::getInt('Itemid'), false));
        die();
    }

}

// No closing tag

    
    
    

	
