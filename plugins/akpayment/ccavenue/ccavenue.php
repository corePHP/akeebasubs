<?php
/**
 * @package		akeebasubs
 * @copyright	Copyright (c)2010-2011 Nicholas K. Dionysopoulos / AkeebaBackup.com
 * @license		GNU GPLv3 <http://www.gnu.org/licenses/gpl.html> or later
 */

defined('_JEXEC') or die();

jimport('joomla.plugin.plugin');

class plgAkpaymentCcavenue extends JPlugin
{
	private $ppName = 'ccavenue';
	private $ppKey = 'PLG_AKPAYMENT_CCAVENUE_TITLE';

	public function __construct(&$subject, $config = array())
	{
		parent::__construct($subject, $config);
		
		// Load the language files
		$jlang =& JFactory::getLanguage();
		$jlang->load('plg_akpayment_ccavenue', JPATH_ADMINISTRATOR, 'en-GB', true);
		$jlang->load('plg_akpayment_ccavenue', JPATH_ADMINISTRATOR, $jlang->getDefault(), true);
		$jlang->load('plg_akpayment_ccavenue', JPATH_ADMINISTRATOR, null, true);
	}

	public function onAKPaymentGetIdentity()
	{
		$title = $this->params->get('title','');
		if(empty($title)) $title = JText::_($this->ppKey);
		$ret = array(
			'name'		=> $this->ppName,
			'title'		=> $title
		);
		return (object)$ret;
	}
	
	/**
	 * Returns the payment form to be submitted by the user's browser. The form must have an ID of
	 * "paymentForm" and a visible submit button.
	 * 
	 * @param string $paymentmethod
	 * @param JUser $user
	 * @param KDatabaseRow $level
	 * @param KDatabaseRow $subscription
	 * @return string
	 */
	public function onAKPaymentNew($paymentmethod, $user, $level, $subscription)
	{
		if($paymentmethod != $this->ppName) return false;
		
		$nameParts = explode(' ', $user->name, 2);
		$firstName = $nameParts[0];
		if(count($nameParts) > 1) {
			$lastName = $nameParts[1];
		} else {
			$lastName = '';
		}
		
		$merchant = $this->params->get('merchant','');
		$WorkingKey = $this->params->get('workingkey','');
		$redirectURL = rtrim(JURI::base(),'/').'/index.php?option=com_akeebasubs&view=callback&paymentmethod=ccavenue';
		$checksum = $this->getCheckSum($merchant, $subscription->net_amount, $subscription->id,
			$redirectURL, $WorkingKey);
		
		$slug = KFactory::tmp('admin::com.akeebasubs.model.levels')
				->id($subscription->akeebasubs_level_id)
				->getItem()
				->slug;
		$data = (object)array(
			'url'			=> 'https://www.ccavenue.com/shopzone/cc_details.jsp',
			'merchant'		=> $merchant,
			'postback'		=> $redirectURL,
			'currency'		=> strtoupper(KFactory::get('site::com.akeebasubs.model.configs')->getConfig()->currency),
			'firstname'		=> $firstName,
			'lastname'		=> $lastName,
			'checksum'		=> $checksum
		);
		
		$kuser = KFactory::tmp('admin::com.akeebasubs.model.users')
			->user_id($user->id)
			->getItem();

		@ob_start();
		include dirname(__FILE__).DS.'ccavenue'.DS.'form.php';
		$html = @ob_get_clean();
		
		return $html;
	}
	
	public function onAKPaymentCallback($paymentmethod, $data)
	{
		jimport('joomla.utilities.date');
		
		// Check if we're supposed to handle this
		if($paymentmethod != $this->ppName) return false;
		
		// Check IPN data for validity (i.e. protect against fraud attempt)
		$isValid = $this->isValidIPN($data);
		$hackAttempt = false;
		if(!$isValid) {
			$data['akeebasubs_failure_reason'] = 'Invalid checksum; the request has been tampered';
		}
		
		// Load the relevant subscription row
		if($isValid) {
			$id = array_key_exists('Order_Id', $data) ? (int)$data['Order_Id'] : -1;
			$subscription = null;
			if($id > 0) {
				$subscription = KFactory::tmp('admin::com.akeebasubs.model.subscriptions')
					->id($id)
					->getItem();
				if( ($subscription->id <= 0) || ($subscription->id != $id) ) {
					$subscription = null;
					$isValid = false;
				}
			} else {
				$isValid = false;
			}
			if(!$isValid) $data['akeebasubs_failure_reason'] = 'The referenced subscription ID ("Order_Id" field) is invalid';
		}
		
		// Check that the merchant ID is what the site owner has configured
		if($isValid) {
			$merchant_id = $data['Merchant_Id'];
			$valid_id = $this->params->get('merchant','');
			$isValid = ($merchant_id == $valid_id);
			if(!$isValid) $data['akeebasubs_failure_reason'] = 'Merchant ID does not match';
		}
				
		// Check that the amount is correct
		if($isValid && !is_null($subscription)) {
			$mc_gross = floatval($data['Amount']);
			$gross = $subscription->gross_amount;
			// Important: NEVER, EVER compare two floating point values for equality.
			$isValid = ($gross - $mc_gross) < 0.01;
			if(!$isValid) $data['akeebasubs_failure_reason'] = 'Paid amount does not match the subscription amount';
		}
		
		// Log the IPN data
		$this->logIPN($data, $isValid);
		
		// Fraud attempt? Do nothing more!
		if(!$isValid) die('Hacking attempt; payment processing refused');
		
		// Load the subscription level and get its slug
		$slug = KFactory::tmp('admin::com.akeebasubs.model.levels')
				->id($subscription->akeebasubs_level_id)
				->getItem()
				->slug;

		// Check the payment_status
		switch($data['AuthDesc'])
		{
			case 'Y':
				$newStatus = 'C';
				$returnURL = rtrim(JURI::base(),'/').str_replace('&amp;','&',JRoute::_('index.php?option=com_akeebasubs&view=message&layout=default&slug='.$slug.'&layout=order'));
				break;
			
			case 'B':
				$newStatus = 'P';
				$returnURL = rtrim(JURI::base(),'/').str_replace('&amp;','&',JRoute::_('index.php?option=com_akeebasubs&view=message&layout=default&slug='.$slug.'&layout=order'));
				break;
			
			case 'N':
			default:
				$newStatus = 'X';
				$returnURL = rtrim(JURI::base(),'/').str_replace('&amp;','&',JRoute::_('index.php?option=com_akeebasubs&view=message&layout=default&slug='.$slug.'&layout=cancel'));
				break;
		}

		// Update subscription status (this also automatically calls the plugins)
		$updates = array(
			'id'				=> $id,
			'processor_key'		=> $data['Order_Id'],
			'state'				=> $newStatus,
			'enabled'			=> 0
		);
		if($newStatus == 'C') {
			// Fix the starting date if the payment was accepted after the subscription's start date. This
			// works around the case where someone pays by e-Check on January 1st and the check is cleared
			// on January 5th. He'd lose those 4 days without this trick. Or, worse, if it was a one-day pass
			// the user would have paid us and we'd never given him a subscription!
			$jNow = new JDate();
			$jStart = new JDate($subscription->publish_up);
			$jEnd = new JDate($subscription->publish_down);
			$now = $jNow->toUnix();
			$start = $jStart->toUnix();
			$end = $jEnd->toUnix();
			
			if($start < $now) {
				$duration = $end - $start;
				$start = $now;
				$end = $start + $duration;
				$jStart = new JDate($start);
				$jEnd = new JDate($end);
			}
			
			$updates['publish_up'] = $jStart->toMySQL();
			$updates['publish_down'] = $jEnd->toMySQL();
			$updates['enabled'] = 1;
			
			// Also update the user, enabling him
			KFactory::tmp('admin::com.akeebasubs.model.jusers')
				->id($subscription->user_id)
				->getItem()
				->setData(array(
					'block'		=> 0
				))->save();
		}
		$subscription->setData($updates)->save();
		
		$app = JFactory::getApplication();
		$app->redirect($returnURL);
		
		return true;
	}

	/**
	 * Validates the incoming data against PayPal's IPN to make sure this is not a
	 * fraudelent request.
	 */
	private function isValidIPN($data)
	{
		$WorkingKey		= $this->params->get('workingkey','');
		
		$Merchant_Id	= $data['Merchant_Id'];
		$Amount			= $data['Amount'];
		$Order_Id		= $data['Order_Id'];
		$Checksum		= $data['Checksum'];
		$AuthDesc		= $data['AuthDesc'];
		
		return $this->verifyChecksum($Merchant_Id, $Order_Id , $Amount,$AuthDesc,$Checksum,$WorkingKey);
	}
	
	private function logIPN($data, $isValid)
	{
		$config = JFactory::getConfig();
		$logpath = $config->getValue('log_path');
		$logFile = $logpath.'/akpayment_ccavenue_ipn.php';
		jimport('joomla.filesystem.file');
		if(!JFile::exists($logFile)) {
			$dummy = "<?php die(); ?>\n";
			JFile::write($logFile, $dummy);
		} else {
			if(@filesize($logFile) > 1048756) {
				$altLog = $logpath.DS.'akpayment_ccavenue_ipn-1.php';
				if(JFile::exists($altLog)) {
					JFile::delete($altLog);
				}
				JFile::copy($logFile, $altLog);
				JFile::delete($logFile);
				$dummy = "<?php die(); ?>\n";
				JFile::write($logFile, $dummy);
			}
		}
		$logData = JFile::read($logFile);
		if($logData === false) $logData = '';
		$logData .= "\n" . str_repeat('-', 80);
		$logData .= $isValid ? 'Valid ccAvenue callback' : 'INVALID ccAvenue CALLBACK *** FRAUD ATTEMPT OR INVALID NOTIFICATION ***';
		$logData .= "\nDate/time : ".gmdate('Y-m-d H:i:s')." GMT\n\n";
		foreach($data as $key => $value) {
			$logData .= '  ' . str_pad($key, 30, ' ') . $value . "\n";
		}
		$logData .= "\n";
		JFile::write($logFile, $logData);
	}
	
	/**
	 * Get the checksum for an outbound paymenet request (form POST)
	 */
	private function getchecksum($MerchantId,$Amount,$OrderId ,$URL,$WorkingKey)
	{
		$str ="$MerchantId|$OrderId|$Amount|$URL|$WorkingKey";
		$adler = 1;
		$adler = $this->adler32($adler,$str);
		return $adler;
	}
	
	/**
	 * Verify the checksum for an inbound payment notification
	 */
	private function verifychecksum($MerchantId,$OrderId,$Amount,$AuthDesc,$CheckSum,$WorkingKey)
	{
		$str = "$MerchantId|$OrderId|$Amount|$AuthDesc|$WorkingKey";
		$adler = 1;
		$adler = $this->adler32($adler,$str);
		
		if($adler == $CheckSum)
			return true ;
		else
			return false ;
	}
	
	private function adler32($adler , $str)
	{
		$BASE =  65521 ;
	
		$s1 = $adler & 0xffff ;
		$s2 = ($adler >> 16) & 0xffff;
		for($i = 0 ; $i < strlen($str) ; $i++)
		{
			$s1 = ($s1 + Ord($str[$i])) % $BASE ;
			$s2 = ($s2 + $s1) % $BASE ;
		}
		return $this->leftshift($s2 , 16) + $s1;
	}
	
	private function leftshift($str , $num)
	{
	
		$str = DecBin($str);
	
		for( $i = 0 ; $i < (64 - strlen($str)) ; $i++)
			$str = "0".$str ;
	
		for($i = 0 ; $i < $num ; $i++) 
		{
			$str = $str."0";
			$str = substr($str , 1 ) ;
		}
		return $this->cdec($str) ;
	}
	
	private function cdec($num)
	{
	
		for ($n = 0 ; $n < strlen($num) ; $n++)
		{
		   $temp = $num[$n] ;
		   $dec =  $dec + $temp*pow(2 , strlen($num) - $n - 1);
		}
	
		return $dec;
	}
}