<?php
/**
 * @author        BTCPay Server
 * @package       VirtueMart
 * @subpackage    payment
 * @copyright     Copyright (C) 2022 BTCPay Server. All rights reserved.
 * @license       http://www.gnu.org/copyleft/gpl.html GNU/GPL
 */

defined('_JEXEC') or die(
  'Direct Access to ' . basename(
    __FILE__
  ) . 'is not allowed.'
);

jimport('joomla.form.formfield');

class JFormFieldBtcpayVMInfo extends JFormField
{

    var $type = 'btcpayvminfo';
    var $classes = 'btcpayvminfo';

    function getLabel()
    {
        return vmText::_('VMPAYMENT_BTCPAYVM_PAYMENT_CALLBACK_URL');
    }

    function getInput()
    {
        $cid = vRequest::getvar('cid', NULL, 'array');
        if (is_array($cid)) {
            $virtuemart_paymentmethod_id = $cid[0];
        } else {
            $virtuemart_paymentmethod_id = $cid;
        }

        $webhookUrl = JURI::root() . 'index.php?option=com_virtuemart&amp;view=pluginresponse&amp;task=pluginnotification&amp;pm=' . $virtuemart_paymentmethod_id;
        //$webhookUrlHttps = str_replace('http://', 'https://', $webhookUrl);

        $html = "<input size='50' onclick='this.select();'
                    type='text' class='$this->classes' 
                    value='$webhookUrl' />";
        $html .= '<p>' . vmText::_('VMPAYMENT_BTCPAYVM_PAYMENT_CALLBACK_URL_DESC') . '</p>';
        return $html;
    }

}
