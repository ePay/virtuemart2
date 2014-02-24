<?php
/*
  Copyright (c) 2010. All rights reserved ePay - www.epay.dk.

  This program is free software. You are allowed to use the software but NOT allowed to modify the software. 
  It is also not legal to do any changes to the software and distribute it in your own name / brand. 
*/

defined('_JEXEC') or die('Direct Access to ' . basename(__FILE__) . ' is not allowed.');

if (!class_exists('vmPSPlugin'))
    require(JPATH_VM_PLUGINS . DS . 'vmpsplugin.php');

class plgVMPaymentEpay extends vmPSPlugin {

    // instance of class
    public static $_this = false;

    function __construct(& $subject, $config) {
	//if (self::$_this)
	 //   return self::$_this;
	parent::__construct($subject, $config);

	$this->_loggable = true;
	$this->tableFields = array_keys($this->getTableSQLFields());

	$varsToPush = array('epay_merchant' => array('', 'char'),
		'epay_windowstate' => array('1', 'int'),
	    'epay_instantcapture' => array('', 'int'),
		'epay_ownreceipt' => array('', 'int'),
		'epay_group' => array('', 'char'),
		'epay_authmail' => array('', 'char'),
		'epay_authsms' => array('', 'char'),
		'epay_md5key' => array('', 'char'),
		'status_pending' => array(0, 'char'),
	    'status_success' => array(0, 'char'),
	    'status_canceled' => array(0, 'char'),
		'payment_logos'       => array('', 'char')
	);

	$this->setConfigParameterable($this->_configTableFieldName, $varsToPush);

	//self::$_this = $this;
    }
	
	private function _getEpayLanguage()
	{
		if(JText::_('VMPAYMENT_EPAY_LANGUAGE'))
			return JText::_('VMPAYMENT_EPAY_LANGUAGE');
		else
			return 1;
	}
	
    function _getPaymentResponseHtml($epayData, $payment_name)
	{
		
		$html = '<table>' . "\n";
		$html .= $this->getHtmlRow('EPAY_PAYMENT_NAME', $payment_name);
		$html .= $this->getHtmlRow('EPAY_TRANSACTION_ID', $epayData["txnid"]);
		$html .= $this->getHtmlRow('EPAY_ORDER_NUMBER', $epayData["orderid"]);

		$html .= '</table>' . "\n";
		
		return $html;
	}

    protected function getVmPluginCreateTableSQL()
	{
		return $this->createTableSQL('ePay Payment Solutions');
	}

    function getTableSQLFields()
	{
		$SQLfields = array
		(
			'id' => 'int(11) UNSIGNED NOT NULL AUTO_INCREMENT',
			'virtuemart_order_id' => 'int(11) UNSIGNED DEFAULT NULL',
			'order_number' => 'char(32) DEFAULT NULL',
			'virtuemart_paymentmethod_id' => 'mediumint(1) UNSIGNED DEFAULT NULL',
			'payment_name' => 'char(255) NOT NULL DEFAULT \'\' ',
			'payment_order_total' => 'decimal(15,5) NOT NULL DEFAULT \'0.00000\' ',
			'payment_currency' => 'char(3) ',
			'cost_per_transaction' => 'decimal(10,2) DEFAULT NULL ',
			'cost_percent_total' => 'decimal(10,2) DEFAULT NULL ',
			'tax_id' => 'smallint(1) DEFAULT NULL',
			'epay_response' => 'varchar(9000) DEFAULT NULL'
		);
		return $SQLfields;
	}

    function plgVmConfirmedOrder($cart, $order)
	{
		
		if(!($method = $this->getVmPluginMethod($order["details"]["BT"]->virtuemart_paymentmethod_id)))
		{
			return null;
			// Another method was selected, do nothing
		}
		if(!$this->selectedThisElement($method->payment_element))
		{
			return false;
		}
		
		$this->logInfo('plgVmOnConfirmedOrderGetPaymentForm order number: ' . $order['details']['BT']->order_number, 'message');
		$lang = JFactory::getLanguage();
		$lang->load('plg_vmpayment_epay', JPATH_ADMINISTRATOR);
		
		if(!class_exists('VirtueMartModelOrders'))
			require (JPATH_VM_ADMINISTRATOR . DS . 'models' . DS . 'orders.php');
		if(!class_exists('VirtueMartModelCurrency'))
			require (JPATH_VM_ADMINISTRATOR . DS . 'models' . DS . 'currency.php');
		
		$new_status = '';

		$usrBT = $order['details']['BT'];
		$address = ((isset($order['details']['ST'])) ? $order['details']['ST'] : $order['details']['BT']);

		$vendorModel = new VirtueMartModelVendor();
		$vendorModel->setId(1);
		$vendor = $vendorModel->getVendor();
		$this->getPaymentCurrency($method);
		$q = 'SELECT `currency_numeric_code` FROM `#__virtuemart_currencies` WHERE `virtuemart_currency_id`="' . $method->payment_currency . '" ';
		$db = &JFactory::getDBO();
		$db->setQuery($q);
		$currency_numeric_code = $db->loadResult();

		$paymentCurrency = CurrencyDisplay::getInstance($method->payment_currency);
		$totalInPaymentCurrency = round($paymentCurrency->convertCurrencyTo($method->payment_currency, $order['details']['BT']->order_total,false), 2);
		$cd = CurrencyDisplay::getInstance($cart->pricesCurrency);

		$session = JFactory::getSession();
		
		$post_variables = array
		(
			'merchantnumber' => $method->epay_merchant,
			'instantcapture' => $method->epay_instantcapture,
			'ownreceipt' => $method->epay_ownreceipt,
			'group' => $method->epay_group,
			'mailreceipt' => $method->epay_authmail,
			'smsreceipt' => $method->epay_authsms,
			'language' => $this->_getEpayLanguage(),
			'orderid' => $order['details']['BT']->order_number,
			"amount" => $totalInPaymentCurrency*100,
			"currency" => $currency_numeric_code,
	    	"accepturl" => JROUTE::_(JURI::root() . 'index.php?option=com_virtuemart&view=pluginresponse&task=pluginresponsereceived&pm=' . $order['details']['BT']->virtuemart_paymentmethod_id),
	    	"callbackurl" => JROUTE::_(JURI::root() . 'index.php?callback=1&option=com_virtuemart&view=pluginresponse&task=pluginresponsereceived&pm=' . $order['details']['BT']->virtuemart_paymentmethod_id),
	    	"cancelurl" => JROUTE::_(JURI::root() . 'index.php?option=com_virtuemart&view=pluginresponse&task=pluginUserPaymentCancel&on=' . $order['details']['BT']->order_number . '&pm=' . $order['details']['BT']->virtuemart_paymentmethod_id),
			"windowstate" => $method->epay_windowstate
		);
		
		$hash = md5(implode($post_variables, "") . $method->epay_md5key);
		
		// Prepare data that should be stored in the database
		$dbValues['payment_name'] = $this->renderPluginName($method, $order);
		$dbValues['order_number'] = $order['details']['BT']->order_number;
		$dbValues['virtuemart_paymentmethod_id'] = $cart->virtuemart_paymentmethod_id;
		$this->storePSPluginInternalData($dbValues);
				
		// add spin image
		$html = '<script type="text/javascript" src="https://ssl.ditonlinebetalingssystem.dk/integration/ewindow/paymentwindow.js" charset="UTF-8"></script>';
		$html .= '<script type="text/javascript">';
        	$html .= 'paymentwindow = new PaymentWindow({';
				foreach ($post_variables as $name => $value)
				{
					$html .= '\''.$name.'\': "'.$value.'",';
				}
				
				$html .= '\'hash\': "'.$hash.'"';
		$html .= '});';
		$html .= '</script><input type="button" onclick="javascript: paymentwindow.open()" value="Go to payment" />';
		
		$html .= ' <script type="text/javascript">';
		$html .= ' paymentwindow.open();';
		$html .= ' </script>';
		// 	2 = don't delete the cart, don't send email and don't redirect
		$cart->_confirmDone = false;
		$cart->_dataValidated = false;
		$cart->setCartIntoSession();
		JRequest::setVar('html', $html);
	}

    function plgVmgetPaymentCurrency($virtuemart_paymentmethod_id, &$paymentCurrencyId)
	{
		if(!($method = $this->getVmPluginMethod($virtuemart_paymentmethod_id)))
		{
			return null;
			// Another method was selected, do nothing
		}
		if(!$this->selectedThisElement($method->payment_element))
		{
			return false;
		}
		$this->getPaymentCurrency($method);
		$paymentCurrencyId = $method->payment_currency;
	}

    function plgVmOnPaymentResponseReceived(&$html="")
	{
		if(!class_exists('VirtueMartModelOrders'))
			require (JPATH_VM_ADMINISTRATOR . DS . 'models' . DS . 'orders.php');
		
		$payment_data = $_GET;
		
		// the payment itself should send the parameter needed.
		$virtuemart_paymentmethod_id = $payment_data["pm"];
		$virtuemart_order_id = VirtueMartModelOrders::getOrderIdByOrderNumber($payment_data["orderid"]);
		
		
		
		$vendorId = 0;
		if(!($method = $this->getVmPluginMethod($virtuemart_paymentmethod_id)))
		{
			return null;
		}
		
		if(!$this->selectedThisElement($method->payment_element))
		{
			return false;
		}
		
		$db =& JFactory::getDBO();
		$query = "SELECT * FROM #__virtuemart_orders WHERE virtuemart_order_id =".$virtuemart_order_id;
		$db->setQuery($query);
		$payment = $db->loadObject();
		
		//if(!$payment = $this->getDataByOrderId($virtuemart_order_id))
		//{
		//	return;	
		//}
		
		if(@$payment_data["callback"] == 1)
		{
			$this->logInfo('plgVmOnPaymentNotification: virtuemart_order_id  found ' . $virtuemart_order_id, 'message');
			
			$vendorId = 0;
			
			$method = $this->getVmPluginMethod($payment->virtuemart_paymentmethod_id);

			$this->logInfo('epay_data ' . implode('   ', $_GET), 'message');
			
			// get all know columns of the table
			$response_fields = $payment_data;
			unset($response_fields["option"]);
			unset($response_fields["view"]);
			unset($response_fields["task"]);
			unset($response_fields["tmpl"]);
			
			$response_fields["payment_name"] = $this->renderPluginName($method);
			$response_fields["order_number"] = $payment_data["orderid"];
			$response_fields["virtuemart_order_id"] = $virtuemart_order_id;
			$response_fields["epay_response"] = addslashes(serialize($response_fields));
			$response_fields["virtuemart_paymentmethod_id"] = $payment->virtuemart_paymentmethod_id;
			
			//$this->storePSPluginInternalData($response_fields);
			$this->storePSPluginInternalData ($response_fields, 'virtuemart_order_id', TRUE);
			
			if(strlen($method->epay_md5key) > 0)
			{
				$params = $payment_data;
				$var = "";

				foreach ($params as $key => $value)
				{
				    if($key != "hash")
				    {
				        $var .= $value;
				    }
				}

				if($payment_data["hash"] != md5($var . $method->epay_md5key))
				{	
					echo "MD5 ERROR";
					$this->logInfo('MD5 Error: exit ', 'ERROR');
					return null;
				}
			}
			
			if((int)$payment_data["txnfee"] > 0)
			{
				$fee = (int)$payment_data["txnfee"] / 100;
				$db = JFactory::getDBO();
				$q = "UPDATE #__virtuemart_orders SET order_payment = " . (float)$fee . ", order_total = order_total+$fee WHERE virtuemart_order_id=" . $virtuemart_order_id;
				$db->setQuery($q);
				$db->query();
			}
			
			$new_status = $method->status_success;
			
			if($virtuemart_order_id)
			{
				// send the email only if payment has been accepted
				if(!class_exists('VirtueMartModelOrders'))
					require (JPATH_VM_ADMINISTRATOR . DS . 'models' . DS . 'orders.php');
				$modelOrder = new VirtueMartModelOrders();
				$order["order_status"] = $new_status;
				$order["virtuemart_order_id"] = $virtuemart_order_id;
				$order["customer_notified"] = 1;
	    		$order['comments'] = JText::sprintf('VMPAYMENT_EPAY_PAYMENT_STATUS_CONFIRMED', $payment_data["orderid"]);
				$modelOrder->updateStatusForOneOrder($virtuemart_order_id, $order, true);
			}
			
			echo "OK";
		}
		else
		{
			$session = JFactory::getSession ();
			
			vmdebug('plgVmOnPaymentResponseReceived', $payment_data);
			
			if(!class_exists('VirtueMartModelOrders'))
				require (JPATH_VM_ADMINISTRATOR . DS . 'models' . DS . 'orders.php');
			
			$virtuemart_order_id = VirtueMartModelOrders::getOrderIdByOrderNumber($payment_data["orderid"]);
			$payment_name = $this->renderPluginName($method);
			
			$payment_name = $this->renderPluginName($method);
			$html = $this->_getPaymentResponseHtml($payment_data, $payment_name);
			
			$this->emptyCart($session->getId());
		}
		
		return true;
	}

    function plgVmOnUserPaymentCancel(&$virtuemart_order_id)
	{
		if(!class_exists('VirtueMartModelOrders'))
			require (JPATH_VM_ADMINISTRATOR . DS . 'models' . DS . 'orders.php');
		
		$order_number = JRequest::getVar('orderid');
		$payment_method_id = JRequest::getVar('pm');
		if(!$order_number)
			return false;
		$db = JFactory::getDBO();
		$query = ' SELECT '  . $this->_tablename . '.`virtuemart_order_id` FROM ' . $this->_tablename . " WHERE  `order_number`= '" . $order_number . "'" . ' AND  `virtuemart_paymentmethod_id` = ' . $payment_method_id;
		$db->setQuery($query);
		$virtuemart_order_id = $db->loadResult();
		
		//fwrite($fp, "order" . $virtuemart_order_id);
		if(!$virtuemart_order_id)
		{
			return null;
		}

		return true;
	}

    /**
     * Display stored payment data for an order
     * @see components/com_virtuemart/helpers/vmPSPlugin::plgVmOnShowOrderBEPayment()
     */
    function plgVmOnShowOrderBEPayment($virtuemart_order_id, $payment_method_id)
	{
		if (!$this->selectedThisByMethodId($payment_method_id)) {
		    return null; // Another method was selected, do nothing
		}
		
		$db = JFactory::getDBO();
		$q = 'SELECT * FROM `' . $this->_tablename . '` '
			. 'WHERE `virtuemart_order_id` = ' . $virtuemart_order_id;
		$db->setQuery($q);
		if (!($paymentTable = $db->loadObject())) {
	   	// JError::raiseWarning(500, $db->getErrorMsg());
	    return '';
		}
		
		$html = '<table class="adminlist">' . "\n";
		$html .= $this->getHtmlHeaderBE();
		
		$epay_response = @unserialize(str_replace('\"', '"', $paymentTable->epay_response));
		
		if(is_array($epay_response))
		{
			foreach ($epay_response as $key => $value)
			{
				if($key != "HTTP_COOKIE" && $key != "Itemid")
					$html .= "<tr><td class=\"key\">" . $key . "</td><td align=\"left\">" . $value . "</td></tr>";
			}
		}
		
		$html .= '</table>' . "\n";
		return $html;
	}

    function getCosts(VirtueMartCart $cart, $method, $cart_prices) {
		return 0;
    }

    /**
     * Check if the payment conditions are fulfilled for this payment method
     * @author: Valerie Isaksen
     *
     * @param $cart_prices: cart prices
     * @param $payment
     * @return true: if the conditions are fulfilled, false otherwise
     *
     */
    protected function checkConditions($cart, $method, $cart_prices) {
		return true;
    }
    /**
     * We must reimplement this triggers for joomla 1.7
     */

    /**
     * Create the table for this plugin if it does not yet exist.
     * This functions checks if the called plugin is active one.
     * When yes it is calling the standard method to create the tables
     * @author Valérie Isaksen
     *
     */
    function plgVmOnStoreInstallPaymentPluginTable($jplugin_id) {

	return $this->onStoreInstallPluginTable($jplugin_id);
    }

    /**
     * This event is fired after the payment method has been selected. It can be used to store
     * additional payment info in the cart.
     *
     * @author Max Milbers
     * @author Valérie isaksen
     *
     * @param VirtueMartCart $cart: the actual cart
     * @return null if the payment was not selected, true if the data is valid, error message if the data is not vlaid
     *
     */
    public function plgVmOnSelectCheckPayment(VirtueMartCart $cart) {
	return $this->OnSelectCheck($cart);
    }

    /**
     * plgVmDisplayListFEPayment
     * This event is fired to display the pluginmethods in the cart (edit shipment/payment) for exampel
     *
     * @param object $cart Cart object
     * @param integer $selected ID of the method selected
     * @return boolean True on succes, false on failures, null when this plugin was not selected.
     * On errors, JError::raiseWarning (or JError::raiseError) must be used to set a message.
     *
     * @author Valerie Isaksen
     * @author Max Milbers
     */
    public function plgVmDisplayListFEPayment(VirtueMartCart $cart, $selected = 0, &$htmlIn) {
	return $this->displayListFE($cart, $selected, $htmlIn);
    }

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

    public function plgVmonSelectedCalculatePricePayment(VirtueMartCart $cart, array &$cart_prices, &$cart_prices_name) {
	return $this->onSelectedCalculatePrice($cart, $cart_prices, $cart_prices_name);
    }

    /**
     * plgVmOnCheckAutomaticSelectedPayment
     * Checks how many plugins are available. If only one, the user will not have the choice. Enter edit_xxx page
     * The plugin must check first if it is the correct type
     * @author Valerie Isaksen
     * @param VirtueMartCart cart: the cart object
     * @return null if no plugin was found, 0 if more then one plugin was found,  virtuemart_xxx_id if only one plugin is found
     *
     */
    function plgVmOnCheckAutomaticSelectedPayment(VirtueMartCart $cart, array $cart_prices = array()) {
	return $this->onCheckAutomaticSelected($cart, $cart_prices);
    }

    /**
     * This method is fired when showing the order details in the frontend.
     * It displays the method-specific data.
     *
     * @param integer $order_id The order ID
     * @return mixed Null for methods that aren't active, text (HTML) otherwise
     * @author Max Milbers
     * @author Valerie Isaksen
     */
    protected function plgVmOnShowOrderFEPayment($virtuemart_order_id, $virtuemart_paymentmethod_id, &$payment_name) {
	  $this->onShowOrderFE($virtuemart_order_id, $virtuemart_paymentmethod_id, $payment_name);
    }

    /**
     * This event is fired during the checkout process. It can be used to validate the
     * method data as entered by the user.
     *
     * @return boolean True when the data was valid, false otherwise. If the plugin is not activated, it should return null.
     * @author Max Milbers

      public function plgVmOnCheckoutCheckDataPayment($psType, VirtueMartCart $cart) {
      return null;
      }
     */

    /**
     * This method is fired when showing when priting an Order
     * It displays the the payment method-specific data.
     *
     * @param integer $_virtuemart_order_id The order ID
     * @param integer $method_id  method used for this order
     * @return mixed Null when for payment methods that were not selected, text (HTML) otherwise
     * @author Valerie Isaksen
     */
    function plgVmonShowOrderPrintPayment($order_number, $method_id) {
	return $this->onShowOrderPrint($order_number, $method_id);
    }

    /**
     * Save updated order data to the method specific table
     *
     * @param array $_formData Form data
     * @return mixed, True on success, false on failures (the rest of the save-process will be
     * skipped!), or null when this method is not actived.
     * @author Oscar van Eijk

      public function plgVmOnUpdateOrderPayment(  $_formData) {
      return null;
      }
     */
    /**
     * Save updated orderline data to the method specific table
     *
     * @param array $_formData Form data
     * @return mixed, True on success, false on failures (the rest of the save-process will be
     * skipped!), or null when this method is not actived.
     * @author Oscar van Eijk

      public function plgVmOnUpdateOrderLine(  $_formData) {
      return null;
      }
     */
    /**
     * plgVmOnEditOrderLineBE
     * This method is fired when editing the order line details in the backend.
     * It can be used to add line specific package codes
     *
     * @param integer $_orderId The order ID
     * @param integer $_lineId
     * @return mixed Null for method that aren't active, text (HTML) otherwise
     * @author Oscar van Eijk

      public function plgVmOnEditOrderLineBE(  $_orderId, $_lineId) {
      return null;
      }
     */

    /**
     * This method is fired when showing the order details in the frontend, for every orderline.
     * It can be used to display line specific package codes, e.g. with a link to external tracking and
     * tracing systems
     *
     * @param integer $_orderId The order ID
     * @param integer $_lineId
     * @return mixed Null for method that aren't active, text (HTML) otherwise
     * @author Oscar van Eijk

      public function plgVmOnShowOrderLineFE(  $_orderId, $_lineId) {
      return null;
      }
     */
    function plgVmDeclarePluginParamsPayment($name, $id, &$data) {
	return $this->declarePluginParams('payment', $name, $id, $data);
    }

    function plgVmSetOnTablePluginParamsPayment($name, $id, &$table) {
	return $this->setOnTablePluginParams($name, $id, $table);
    }

}

// No closing tag
