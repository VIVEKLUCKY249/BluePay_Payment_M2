<?php

/**
 * Magento
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://opensource.org/licenses/osl-3.0.php
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@magentocommerce.com so we can send you a copy immediately.
 *
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade Magento to newer
 * versions in the future. If you wish to customize Magento for your
 * needs please refer to http://www.magentocommerce.com for more information.
 *
 * @category    BluePay
 * @package     BluePay_Payment
 * @copyright   Copyright (c) 2016 BluePay Processing, LLC (http://www.bluepay.com)
 * @license     http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */
 
namespace BluePay\Payment\Model;

class Payment extends \Magento\Payment\Model\Method\Cc
{
    const CGI_URL = 'https://secure.bluepay.com/interfaces/bp10emu';
    const STQ_URL = 'https://secure.bluepay.com/interfaces/stq';
    const CURRENT_VERSION = '1.0.0.0';

    const CODE = 'bluepay_payment';

    const REQUEST_METHOD_CC     = 'CREDIT';
    const REQUEST_METHOD_ECHECK = 'ACH';

    const REQUEST_TYPE_AUTH_CAPTURE = 'SALE';
    const REQUEST_TYPE_AUTH_ONLY    = 'AUTH';
    const REQUEST_TYPE_CAPTURE_ONLY = 'CAPTURE';
    const REQUEST_TYPE_CREDIT       = 'REFUND';
    const REQUEST_TYPE_VOID         = 'VOID';
    const REQUEST_TYPE_PRIOR_AUTH_CAPTURE = 'PRIOR_AUTH_CAPTURE';

    const ECHECK_ACCT_TYPE_CHECKING = 'CHECKING';
    const ECHECK_ACCT_TYPE_BUSINESS = 'BUSINESSCHECKING';
    const ECHECK_ACCT_TYPE_SAVINGS  = 'SAVINGS';

    const ECHECK_TRANS_TYPE_CCD = 'CCD';
    const ECHECK_TRANS_TYPE_PPD = 'PPD';
    const ECHECK_TRANS_TYPE_TEL = 'TEL';
    const ECHECK_TRANS_TYPE_WEB = 'WEB';

    const RESPONSE_DELIM_CHAR = ',';

    const RESPONSE_CODE_APPROVED = 'APPROVED';
    const RESPONSE_CODE_DECLINED = 'DECLINED';
    const RESPONSE_CODE_ERROR    = 'ERROR';
    const RESPONSE_CODE_MISSING  = 'MISSING';
    const RESPONSE_CODE_HELD     = 4;

	protected $responseHeaders;
	protected $tempVar;

    protected $_code  = 'bluepay_payment';
	//protected $_formBlockType = 'creditcard/form';
	protected static $_dupe = true;
	protected static $_underscoreCache = array();

    protected $_stripeApi = false;

    protected $_countryFactory;

    protected $_minAmount = null;
    protected $_maxAmount = null;
    protected $_supportedCurrencyCodes = array('USD');

    /**
     * Availability options
     */
    protected $_isGateway               = true;
    protected $_canAuthorize            = true;
    protected $_canCapture              = true;
    protected $_canCapturePartial       = true;
    protected $_canRefund               = true;
    protected $_canRefundInvoicePartial = true;
    protected $_canVoid                 = true;
    protected $_canUseInternal          = true;
    protected $_canUseCheckout          = true;
    protected $_canUseForMultishipping  = true;
    protected $_canSaveCc 		= false;

    protected $_allowCurrencyCode = array('USD');

    /**
     * Fields that should be replaced in debug with '***'
     *
     * @var array
     */
    protected $_debugReplacePrivateDataKeys = array('ach_account');

    protected $customerRegistry;

    /**
     * @var \Magento\Authorizenet\Helper\Data
     */
    protected $dataHelper;

    /**
     * @var \Magento\Checkout\Helper\Cart
     */
    protected $checkoutCartHelper;

    /**
     * Request factory
     *
     * @var \Magento\Authorizenet\Model\RequestFactory
     */
    protected $requestFactory;

    /**
     * Response factory
     *
     * @var \Magento\Authorizenet\Model\ResponseFactory
     */
    protected $responseFactory;

    public function __construct(
        \Magento\Framework\Model\Context $context,
        \Magento\Framework\Registry $registry,
        \Magento\Customer\Model\CustomerRegistry $customerRegistry,
        \Magento\Framework\Api\ExtensionAttributesFactory $extensionFactory,
        \Magento\Framework\Api\AttributeValueFactory $customAttributeFactory,
        \Magento\Payment\Helper\Data $paymentData,
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
        \Magento\Payment\Model\Method\Logger $logger,
        \Magento\Framework\Module\ModuleListInterface $moduleList,
        \Magento\Framework\Stdlib\DateTime\TimezoneInterface $localeDate,
        \Magento\Checkout\Helper\Cart $checkoutCartHelper,
        \Magento\Checkout\Model\Session $checkoutSession,
        \Magento\Framework\Session\Generic $generic,
        \BluePay\Payment\Model\Request\Factory $requestFactory,
        \BluePay\Payment\Model\Response\Factory $responseFactory,
        \Magento\Framework\HTTP\ZendClientFactory $zendClientFactory,
        \Magento\Framework\Model\ResourceModel\AbstractResource $resource = null,
        \Magento\Framework\Data\Collection\AbstractDb $resourceCollection = null,
        array $data = []
    ) {
        $this->customerRegistry = $customerRegistry;
        $this->checkoutCartHelper = $checkoutCartHelper;
        $this->checkoutSession = $checkoutSession;
        $this->generic = $generic;
        $this->requestFactory = $requestFactory;
        $this->responseFactory = $responseFactory;
        $this->zendClientFactory = $zendClientFactory;

        parent::__construct(
            $context,
            $registry,
            $extensionFactory,
            $customAttributeFactory,
            $paymentData,
            $scopeConfig,
            $logger,
            $moduleList,
            $localeDate,
            $resource,
            $resourceCollection,
            $data
        );



        $this->_minAmount = $this->getConfigData('min_order_total');
        $this->_maxAmount = $this->getConfigData('max_order_total');
    }

/**
     * Determine method availability based on quote amount and config data
     *
     * @param \Magento\Quote\Api\Data\CartInterface|null $quote
     * @return bool
     */
    public function isAvailable(\Magento\Quote\Api\Data\CartInterface $quote = null)
    {
        if ($quote && (
            $quote->getBaseGrandTotal() < $this->_minAmount
            || ($this->_maxAmount && $quote->getBaseGrandTotal() > $this->_maxAmount))
        ) {
            return false;
        }
        if (!$this->getConfigData('account_id')) {
            return false;
        }

        return parent::isAvailable($quote);
    }

    /**
     * Check method for processing with base currency
     *
     * @param string $currencyCode
     * @return boolean
     */
    public function canUseForCurrency($currencyCode)
    {
        if (!in_array($currencyCode, $this->getAcceptedCurrencyCodes())) {
            return false;
        }
        return true;
    }

    /**
     * Return array of currency codes supplied by Payment Gateway
     *
     * @return array
     */
    public function getAcceptedCurrencyCodes()
    {
        if (!$this->hasData('_accepted_currency')) {
            $acceptedCurrencyCodes = $this->_allowCurrencyCode;
            $acceptedCurrencyCodes[] = $this->getConfigData('currency');
            $this->setData('_accepted_currency', $acceptedCurrencyCodes);
        }
        return $this->_getData('_accepted_currency');
    }

    /**
     * Send authorize request to gateway
    */
	
    public function authorize(\Magento\Payment\Model\InfoInterface $payment, $amount)
    {
        if ($amount <= 0) {
            throw new \Magento\Framework\Exception\LocalizedException(__('Invalid amount for authorization.'));
        }
        $payment->setTransactionType(self::REQUEST_TYPE_AUTH_ONLY);
        $payment->setAmount($amount);
        $request= $this->_buildRequest($payment);
        $result = $this->_postRequest($request);
        $payment->setCcApproval($result->getAuthCode())
            ->setLastTransId($result->getRrno())
            ->setTransactionId($result->getRrno())
            ->setIsTransactionClosed(0)
            ->setCcTransId($result->getRrno())
            ->setCcAvsStatus($result->getAvs())
            ->setCcCidStatus($result->getCvv2());
		if ($payment->getCcType() == '') $payment->setCcType($result->getCardType());
		if ($payment->getCcLast4() == '') $payment->setCcLast4(substr($result->getCcNumber(), -4));
        switch ($result->getResult()) {
            case self::RESPONSE_CODE_APPROVED:
                if ($result->getMessage() != 'DUPLICATE')
                    $payment->setStatus(self::STATUS_APPROVED);
                else
                    throw new \Magento\Framework\Exception\LocalizedException(__('Error: ' . $result->getMessage()));
                return $this;
            case self::RESPONSE_CODE_DECLINED:
                throw new \Magento\Framework\Exception\LocalizedException(__('The transaction has been declined'));
			case self::RESPONSE_CODE_ERROR:
                throw new \Magento\Framework\Exception\LocalizedException(__('Error: ' . $result->getMessage()));
            case self::RESPONSE_CODE_MISSING:
                throw new \Magento\Framework\Exception\LocalizedException(__('Error: ' . $result->getMessage()));
			default:
                throw new \Magento\Framework\Exception\LocalizedException(__('An error has occured with your payment.'));
        }
    }

    /**
     * Send capture request to gateway
     */
    public function capture(\Magento\Payment\Model\InfoInterface $payment, $amount)
    {
	$payment->setAmount($amount);
	//$result =$this->_checkDuplicate($payment);
        if ($payment->getCcTransId()) {
            $payment->setTransactionType(self::REQUEST_TYPE_CAPTURE_ONLY);
        } else {
            $payment->setTransactionType(self::REQUEST_TYPE_AUTH_CAPTURE);
        }
	$payment->setRrno($payment->getCcTransId());
        $request = $this->_buildRequest($payment);
        $result = $this->_postRequest($request); 
        if ($result->getResult() == self::RESPONSE_CODE_APPROVED) {
            $payment->setStatus(self::STATUS_APPROVED);
			if ($payment->getCcType() == '') $payment->setCcType($result->getCardType());
			if ($payment->getCcLast4() == '') $payment->setCcLast4(substr($result->getCcNumber(), -4));
            ////$payment->setCcTransId($result->getTransactionId());
            $payment->setLastTransId($result->getRrno());
            if (!$payment->getParentTransactionId() || $result->getRrno() != $payment->getParentTransactionId()) {
                $payment->setTransactionId($result->getRrno());
            }
            return $this;
        }
	switch ($result->getResult()) {
		case self::RESPONSE_CODE_DECLINED:
			throw new \Magento\Framework\Exception\LocalizedException(__('The transaction has been declined.'));
		case self::RESPONSE_CODE_ERROR || self::RESPONSE_CODE_MISSING:
			if ($result->getMessage() == 'Already%20Captured') {
				$payment->setTransactionType(self::REQUEST_TYPE_AUTH_CAPTURE);
				$request=$this->_buildRequest($payment);
				$result =$this->_postRequest($request);
				        if ($result->getResult() == self::RESPONSE_CODE_APPROVED && $result->getMessage() != 'DUPLICATE') {
            					$payment->setStatus(self::STATUS_APPROVED);
            					$payment->setLastTransId($result->getRrno());
            					if (!$payment->getParentTransactionId() || $result->getRrno() != $payment->getParentTransactionId()) {
                					$payment->setTransactionId($result->getRrno());
            					}
            					return $this;
        				} else {
						throw new \Magento\Framework\Exception\LocalizedException(Mage::helper('paygate')->__('Error: ' . $result->getMessage()));
					}
			} else {
				throw new \Magento\Framework\Exception\LocalizedException(__('Error: ' . $result->getMessage()));
			}
		default:
			throw new \Magento\Framework\Exception\LocalizedException(__('An error has occured with your payment.'));
	}
        throw new \Magento\Framework\Exception\LocalizedException(__('Error in capturing the payment.'));
    }
	

    /**
     * Void the payment through gateway
     */
    public function void(\Magento\Payment\Model\InfoInterface $payment)
    {
        if ($payment->getParentTransactionId()) {
			$order = $payment->getOrder();
            $payment->setTransactionType(self::REQUEST_TYPE_CREDIT);
			$payment->setAmount($amount);
			$payment->setRrno($payment->getParentTransactionId());
            $request = $this->_buildRequest($payment);
            $result = $this->_postRequest($request);
            if ($result->getResult()==self::RESPONSE_CODE_APPROVED) {
                 $payment->setStatus(self::STATUS_APPROVED);
				 $order->setState(\Magento\Sales\Model\Order::STATE_CANCELED, true)->save();
                 return $this;
            }
            $payment->setStatus(self::STATUS_ERROR);
            throw new \Magento\Framework\Exception\LocalizedException(__($result->getMessage()));
        }
        $payment->setStatus(self::STATUS_ERROR);
        throw new \Magento\Framework\Exception\LocalizedException(__('Invalid transaction ID.'));
    }

    /**
     * refund the amount with transaction id
     */
    public function refund(\Magento\Payment\Model\InfoInterface $payment, $amount)
    {
        if ($payment->getRefundTransactionId() && $amount > 0) {
            $payment->setTransactionType(self::REQUEST_TYPE_CREDIT);
			$payment->setRrno($payment->getRefundTransactionId());
			$payment->setAmount($amount);
            $request = $this->_buildRequest($payment);
            $request->setRrno($payment->getRefundTransactionId());
            $result = $this->_postRequest($request);
            if ($result->getResult()==self::RESPONSE_CODE_APPROVED) {
                $payment->setStatus(self::STATUS_SUCCESS);
                return $this;
            }
			if ($result->getResult()==self::RESPONSE_CODE_DECLINED) {
                throw new \Magento\Framework\Exception\LocalizedException($this->_wrapGatewayError('DECLINED'));
            }
			if ($result->getResult()==self::RESPONSE_CODE_ERROR) {
                throw new \Magento\Framework\Exception\LocalizedException($this->_wrapGatewayError('ERROR'));
            }			
            throw new \Magento\Framework\Exception\LocalizedException($this->_wrapGatewayError($result->getRrno()));
        }
        throw new \Magento\Framework\Exception\LocalizedException(__('Error in refunding the payment.'));
    }

    /**
     * Prepare request to gateway
     */
    protected function _buildRequest(\Magento\Payment\Model\InfoInterface $payment)
    {
        $order = $payment->getOrder();
        $this->setStore($order->getStoreId());
        $request = $this->requestFactory->create();
        if (!$payment->getPaymentType() || $payment->getPaymentType() == 'CC') {
            $payment->setPaymentType(self::REQUEST_METHOD_CC);
        } else {
            $payment->setPaymentType(self::REQUEST_METHOD_ECHECK);
        }
        $request = $this->requestFactory->create();
        if ($order && $order->getIncrementId()) {
            $request->setInvoiceId($order->getIncrementId());
        }
        $request->setMode(($this->getConfigData('trans_mode') == 'TEST') ? 'TEST' : 'LIVE');

	if ($payment->getToken() != '' && !$payment->getRrno()) {
	    $request->setRrno($payment->getToken());
	    //$payment->setRrno($payment->getAdditionalData());
        $payment->setRrno($payment->getToken());
	}

        $request->setMerchant($this->getConfigData('account_id'))
            ->setTransactionType($payment->getTransactionType())
            ->setPaymentType($payment->getPaymentType())
            ->setResponseversion('3')
			->setTamperProofSeal($this->calcTPS($payment));
        if($payment->getAmount()){
            $request->setAmount($payment->getAmount(),2);
        }
        if ($payment->getCcTransId()){
                $request->setRrno($payment->getCcTransId());
        }
        switch ($payment->getTransactionType()) {
            case self::REQUEST_TYPE_CREDIT:
            case self::REQUEST_TYPE_VOID:
            case self::REQUEST_TYPE_CAPTURE_ONLY:
                $request->setRrno($payment->getCcTransId());
                break;
        }
		$cart = $this->checkoutCartHelper->getCart()->getItemsCount();
		$cartSummary = $this->checkoutCartHelper->getCart()->getSummaryQty();
		$this->generic;
		$session = $this->checkoutSession;

		$comment = "";

		foreach ($session->getQuote()->getAllItems() as $item) {
    
			$comment .= $item->getQty() . ' ';
			$comment .= '[' . $item->getSku() . ']' . ' ';
			$comment .= $item->getName() . ' ';
			$comment .= $item->getDescription() . ' ';
			$comment .= $item->getAmount() . ' ';
		}

        if (!empty($order)) {
            $billing = $order->getBillingAddress();
            if (!empty($billing)) {
                $request->setCompanyName($billing->getCompany())
                    ->setCity($billing->getCity())
                    ->setState($billing->getRegion())
                    ->setZipcode($billing->getPostcode())
                    ->setCountry($billing->getCountry())
                    ->setPhone($billing->getTelephone())
                    ->setFax($billing->getFax())
                    ->setCustomId($billing->getCustomerId())
                    ->setComment($comment)
                    ->setEmail($order->getCustomerEmail());
                $request["name1"] = $billing->getFirstname();
                $request["name2"] = $billing->getLastname();
                $request["addr1"] = $billing->getStreetLine(1);
                $request["addr2"] = $billing->getStreetLine(2);
            }
        }
        $info = $this->getInfoInstance();
        switch ($payment->getPaymentType()) {
            case self::REQUEST_METHOD_CC:
                if($payment->getCcNumber()){
		    $temp = $payment->getCcExpYear();
	       	    $CcExpYear = str_split($temp, 2);
                    $request->setCcNum($payment->getCcNumber())
                        //->setCcExpires(sprintf('%02d%02d', $payment->getCcExpMonth(), $CcExpYear[1]))
                        ->setCcExpires(sprintf('%02d%02d', $payment->getCcExpMonth(), $payment->getCcExpYear()));
                    $request['CVCCVV2'] = $payment->getCcCid();
                }
                break;

            case self::REQUEST_METHOD_ECHECK:
                $request->setAchRouting($info->getEcheckRoutingNumber())
                    ->setAchAccount($info->getEcheckAcctNumber())
                    ->setAchAccountType($info->getEcheckAcctType())
                    ->setDocType('WEB');
                break;
        }
        return $request;
    }

    protected function _postRequest(\Magento\Framework\DataObject $request)
    {
       	$result = $this->responseFactory->create();
	if (isset($_POST["?Result"])) {
		$_POST["Result"] = $_POST["?Result"];
		unset($_POST["?Result"]);
	}
	if (!isset($_POST["Result"])) {
        	$client = $this->zendClientFactory->create();
        	$uri = self::CGI_URL;
        	$client->setUri($uri ? $uri : self::CGI_URL);
        	$client->setConfig(array(
            	'maxredirects'=>0,
            	'timeout'=>15,
		'useragent'=>'BluePay Magento 2 Payment Plugin/' . self::CURRENT_VERSION,
       		));
        	$client->setParameterPost($request->getData());
		    //$comma_separated = implode(",", $request->getData());
        	$client->setMethod(\Zend_Http_Client::POST);
        	try {
            	    $response = $client->request();
        	}
        	catch (Exception $e) {
            	    $debugData['result'] = $result->getData();
            	    $this->_debug($debugData);
                    throw new \Magento\Framework\Exception\LocalizedException($this->_wrapGatewayError($e->getMessage()));
        	}
		$r = substr($response->getHeader('location'), strpos($response->getHeader('location'), "?") + 1);
        	if ($r) {
                    parse_str($r, $responseFromBP);
            	    isset($responseFromBP["Result"]) ? $result->setResult($responseFromBP["Result"]) : 
                        $result->setResult('');
                    isset($responseFromBP["INVOICE_ID"]) ? $result->setInvoiceId($responseFromBP["INVOICE_ID"]) :
                        $result->setInvoiceId('');
					isset($responseFromBP["BANK_NAME"]) ? $result->setBankName($responseFromBP["BANK_NAME"]) :
                        $result->setBankName('');
                    isset($responseFromBP["MESSAGE"]) ? $result->setMessage($responseFromBP["MESSAGE"]) :
                        $result->setMessage('');
                    isset($responseFromBP["AUTH_CODE"]) ? $result->setAuthCode($responseFromBP["AUTH_CODE"]) :
                        $result->setAuthCode('');
                    isset($responseFromBP["AVS"]) ? $result->setAvs($responseFromBP["AVS"]) :
                        $result->setAvs('');
                    isset($responseFromBP["RRNO"]) ? $result->setRrno($responseFromBP["RRNO"]) :
                        $result->setRrno('');
                    isset($responseFromBP["AMOUNT"]) ? $result->setAmount($responseFromBP["AMOUNT"]) :
                        $result->setAmount('');
                    isset($responseFromBP["PAYMENT_TYPE"]) ? $result->setPaymentType($responseFromBP["PAYMENT_TYPE"]) :
                        $result->setPaymentType('');
                    isset($responseFromBP["ORDER_ID"]) ? $result->setOrderId($responseFromBP["ORDER_ID"]) :
                        $result->setOrderId('');
                    isset($responseFromBP["CVV2"]) ? $result->setCvv2($responseFromBP["CVV2"]) :
                        $result->setCvv2('');
                    isset($responseFromBP["PAYMENT_ACCOUNT"]) ? $result->setPaymentAccountMask($responseFromBP["PAYMENT_ACCOUNT"]) :
                        $result->setPaymentAccountMask('');
                    isset($responseFromBP["CC_EXPIRES"]) ? $result->setCcExpires($responseFromBP["CC_EXPIRES"]) :
                        $result->setCcExpires('');
                    isset($responseFromBP["CARD_TYPE"]) ? $result->setCardType($responseFromBP["CARD_TYPE"]) :
                        $result->setCardType('');
		    $this->assignBluePayToken($result->getRrno());
        	} 
        	else {
             	    throw new \Magento\Framework\Exception\LocalizedException(__('Error in payment gateway.'));
        	}

        	if ($this->getConfigData('debug')) {
                $requestDebug = clone $request;
                foreach ($this->_debugReplacePrivateDataKeys as $key) {
                    if ($requestDebug->hasData($key)) {
                        $requestDebug->setData($key, '***');
                    }
                }
                $debugData = array('request' => $requestDebug);
                $debugData['result'] = $result->getData();
                $this->_debug($debugData);
            }
	} else {
		$result->setResult($_POST["Result"]);
		$result->setMessage($_POST["MESSAGE"]);
		$result->setRrno($_POST["RRNO"]);
		$result->setCcNumber($_POST["PAYMENT_ACCOUNT"]);
		$result->setCcExpMonth($_POST["CC_EXPIRES_MONTH"]);
		$result->setCcExpYear($_POST["CC_EXPIRES_YEAR"]);
		$result->setPaymentType($_POST["PAYMENT_TYPE"]);
		$result->setCardType($_POST["CARD_TYPE"]);
		$result->setAuthCode($_POST["AUTH_CODE"]);
		$result->setAvs($_POST["AVS"]);
		$result->setCvv2($_POST["CVV2"]);
		$this->assignBluePayToken($result->getRrno());

	}
        if ($result->getResult() == 'APPROVED') 
            $this->saveCustomerPaymentInfo($result);
        return $result;
    }

    protected function _checkDuplicate(\Magento\Payment\Model\InfoInterface $payment)
    {
	if ($this->getConfigData('duplicate_check') == '0') {
		return;
	}
	$order = $payment->getOrder();
	$billing = $order->getBillingAddress();
	$reportStart = date("Y-m-d H:i:s", time() - (3600 * 5) - $this->getConfigData('duplicate_check'));
	$reportEnd = date("Y-m-d H:i:s", time() - (3600 * 5));
	$hashstr = $this->getConfigData('secret_key') . $this->getConfigData('account_id') .
	$reportStart . $reportEnd;
	$request = $this->requestFactory->create();
        $request->setData("MODE", $this->getConfigData('trans_mode') == 'TEST' ? 'TEST' : 'LIVE');
        $request->setData("TAMPER_PROOF_SEAL", bin2hex(md5($hashstr, true)));
	$request->setData("ACCOUNT_ID", $this->getConfigData('account_id'));
	$request->setData("REPORT_START_DATE", $reportStart);
	$request->setData("REPORT_END_DATE", $reportEnd);
	$request->setData("EXCLUDE_ERRORS", 1);
	$request->setData("ISNULL_f_void", 1);
	$request->setData("name1", $billing['firstname']);
	$request->setData("name2", $billing['lastname']);
	$request->setData("amount", $payment->getAmount());
	$request->setData("status", '1');
	$request->setData("IGNORE_NULL_STR", '0');
	$request->setData("trans_type", "SALE");
 	$client = $this->zendClientFactory->create();

        $client->setUri($uri ? $uri : self::STQ_URL);
        $client->setConfig(array(
            'maxredirects'=>0,
            'timeout'=>30,
        ));
        $client->setParameterPost($request->getData());
        $client->setMethod(\Zend_Http_Client::POST);
        try {
            $response = $client->request();
        }
        catch (Exception $e) {

            $this->_debug($debugData);
            throw new \Magento\Framework\Exception\LocalizedException($this->_wrapGatewayError($e->getMessage()));
        }
	$p = parse_str($client->request()->getBody());
        if ($id) {
	    $conn = $this->resourceConnection->getConnection('core_read'); 
	    $result = $conn->fetchAll("SELECT * FROM sales_payment_transaction WHERE txn_id='$id'");
	    if ($result)
		return;
	    self::$_dupe = true;
	    $payment->setTransactionType(self::REQUEST_TYPE_CREDIT);
        $payment->setCcTransId($id);
	    $payment->setRrno($id);
        $request = $this->_buildRequest($payment);
        $result = $this->_postRequest($request);
	    $payment->setCcTransId('');
        } 
    }
	

    /**
     * Gateway response wrapper
     */
    protected function _wrapGatewayError($text)
    {
        return Mage::helper('paygate')->__('Gateway error: %s', $text);
    }
	
	protected final function calcTPS(\Magento\Payment\Model\InfoInterface $payment) {
	
		$order = $payment->getOrder();
		$billing = $order->getBillingAddress();

		$hashstr = $this->getConfigData('secret_key') . $this->getConfigData('account_id') . 
		$payment->getTransactionType() . $payment->getAmount() . $payment->getRrno() . 
		$this->getConfigData('trans_mode');
		return bin2hex( md5($hashstr, true) );
	}	
 
	protected function parseHeader($header, $nameVal, $pos) {
		$nameVal = ($nameVal == 'name') ? '0' : '1';
		$s = explode("?", $header);
		$t = explode("&", $s[1]);
		$value = explode("=", $t[$pos]);
		return $value[$nameVal];
	}
	
    public function validate()
    {
        $info = $this->getInfoInstance();
        if ($info->getToken() == '' && $info->getPaymentType() == 'ACH') {
            if ($info->getEcheckAcctNumber() == '') {
                throw new \Magento\Framework\Exception\LocalizedException(__("Invalid account number."));
            }
            if ($info->getEcheckRoutingNumber() == '' || strlen($info->getEcheckRoutingNumber()) != 9) {
                throw new \Magento\Framework\Exception\LocalizedException(__("Invalid routing number."));
            }
            return $this;
        }
        $errorMsg = false;
        $availableTypes = explode(',',$this->getConfigData('cctypes'));

        $ccNumber = $info->getCcNumber();
        // remove credit card number delimiters such as "-" and space
        $ccNumber = preg_replace('/[\-\s]+/', '', $ccNumber);
        $info->setCcNumber($ccNumber);
        if ($info->getPaymentType() == 'CC' && $info->getToken() == '' && $ccNumber == '') {
            throw new \Magento\Framework\Exception\LocalizedException(__("Invalid credit card number."));
        }
        if ($info->getPaymentType() == 'CC' &&  $ccNumber != '' && ($info->getCcExpMonth() == '' || $info->getCcExpYear() == '')) {
            throw new \Magento\Framework\Exception\LocalizedException(__("Invalid card expiration date."));
        } else if ($info->getPaymentType() == 'CC' &&  $this->getConfigData('useccv') == '1' && ($info->getCcCid() == '' || strlen($info->getCcCid()) < 3
            || strlen($info->getCcCid()) > 4)) {
            throw new \Magento\Framework\Exception\LocalizedException(__("Invalid Card Verification Number."));
        }

        $ccType = '';
	
	if (in_array($info->getCcType(), $availableTypes)){
            if ($this->validateCcNum($ccNumber)
                // Other credit card type number validation
                || ($this->OtherCcType($info->getCcType()) && $this->validateCcNumOther($ccNumber))) {

                $ccType = 'OT';
                $ccTypeRegExpList = array(
                    // Solo only
                    'SO' => '/(^(6334)[5-9](\d{11}$|\d{13,14}$))|(^(6767)(\d{12}$|\d{14,15}$))/',
                    'SM' => '/(^(5[0678])\d{11,18}$)|(^(6[^05])\d{11,18}$)|(^(601)[^1]\d{9,16}$)|(^(6011)\d{9,11}$)'
                            . '|(^(6011)\d{13,16}$)|(^(65)\d{11,13}$)|(^(65)\d{15,18}$)'
                            . '|(^(49030)[2-9](\d{10}$|\d{12,13}$))|(^(49033)[5-9](\d{10}$|\d{12,13}$))'
                            . '|(^(49110)[1-2](\d{10}$|\d{12,13}$))|(^(49117)[4-9](\d{10}$|\d{12,13}$))'
                            . '|(^(49118)[0-2](\d{10}$|\d{12,13}$))|(^(4936)(\d{12}$|\d{14,15}$))/',
                    // Visa
                    'VI'  => '/^4[0-9]{12}([0-9]{3})?$/',
                    // Master Card
                    'MC'  => '/^5[1-5][0-9]{14}$/',
                    // American Express
                    'AE'  => '/^3[47][0-9]{13}$/',
                    // Discovery
                    'DI'  => '/^6011[0-9]{12}$/',
                    // JCB
                    'JCB' => '/^(3[0-9]{15}|(2131|1800)[0-9]{11})$/'
                );

                foreach ($ccTypeRegExpList as $ccTypeMatch=>$ccTypeRegExp) {
                    if (preg_match($ccTypeRegExp, $ccNumber)) {
                        $ccType = $ccTypeMatch;
                        break;
                    }
                }

		if (!$this->OtherCcType($info->getCcType()) && $ccType!=$info->getCcType()) {
                    $errorMsg = __('Credit card number mismatch with credit card type.');
                }
            }
            else {
                $errorMsg = __('Invalid Credit Card Number');
            }

        }
        else {
            $errorMsg = __('Credit card type is not allowed for this payment method.');
        }

        //validate credit card verification number
        if ($errorMsg === false && $this->hasVerification()) {
            $verifcationRegEx = $this->getVerificationRegEx();
            $regExp = isset($verifcationRegEx[$info->getCcType()]) ? $verifcationRegEx[$info->getCcType()] : '';
            if (!$info->getCcCid() || !$regExp || !preg_match($regExp ,$info->getCcCid())){
                $errorMsg = __('Please enter a valid credit card verification number.');
            }
        }

        if ($ccType != 'SS' && !$this->_validateExpDate($info->getCcExpYear(), $info->getCcExpMonth())) {
            $errorMsg = __('Incorrect credit card expiration date.');
        }

        if($errorMsg) {
	    if ($this->getConfigData('use_iframe') == '1') {
		$errorMsg = '';
	    }
        }

        //This must be after all validation conditions
        if ($this->getIsCentinelValidationEnabled()) {
            $this->getCentinelValidator()->validate($this->getCentinelValidationData());
        }

        return $this;
    }

    public function assignData(\Magento\Framework\DataObject $data)
    {
        if (is_array($data)) {
            $this->getInfoInstance()->addData($data);
        } elseif ($data instanceof \Magento\Framework\DataObject) {
            $this->getInfoInstance()->addData($data->getData());
        }
        $info = $this->getInfoInstance();
        $info->setCcType($data->getCcType())
            ->setCcOwner($data->getCcOwner())
            ->setCcLast4(substr($data->getCcNumber(), -4))
            ->setCcNumber($data->getCcNumber())
            ->setCcCid($data->getCcCid())
            ->setCcExpMonth($data->getCcExpMonth())
            ->setCcExpYear($data->getCcExpYear())
            ->setCcSsIssue($data->getCcSsIssue())
            ->setCcSsStartMonth($data->getCcSsStartMonth())
            ->setCcSsStartYear($data->getCcSsStartYear())
            ->setToken($data->getToken())
	        ->setAdditionalData($data->getBpToken());
        return $this;

    }

    public function assignBluePayToken($token)
    {
	$info = $this->getInfoInstance();
	$info->setAdditionalData($token);
    }

    public function prepareSave()
    {
        $info = $this->getInfoInstance();
        if ($this->_canSaveCc) {
            $info->setCcNumberEnc($info->encrypt('xxxx-'.$info->getCcLast4()));
        }
		if ($info->getAdditionalData()) {
			$info->setAdditionalData($info->getAdditionalData());
		}
        $info->setCcNumber(null)
            ->setCcCid(null);
        return $this;

    }	
	
	public function hasVerificationBackend()
	{
        $configData = $this->getConfigData('useccv_backend');
        if(is_null($configData)){
            return true;
        }
        return (bool) $configData;
    }

    public function saveCustomerPaymentInfo($result) {
        $info = $this->getInfoInstance();
        if ($info->getSavePaymentInfo() != '1') return;

        $customerId = $this->checkoutSession->getQuote()->getCustomerId();
        $customer = $this->customerRegistry->retrieve($customerId);
        $customerData = $customer->getDataModel();
        $paymentAcctString = $customerData->getCustomAttribute('bluepay_stored_accts') ? $customerData->getCustomAttribute('bluepay_stored_accts')->getValue() : '';
        $oldToken = $info->getToken();
        $newToken = $result->getRrno();
        $newCardType = $result->getCardType();
        $newPaymentAccount = $result->getPaymentAccountMask();
        $newCcExpMonth = substr($result->getCcExpires(),0,2);
        $newCcExpYear = substr($result->getCcExpires(),2,2);

        // This is a brand new payment account
        if ($info->getToken() == '') {
            $paymentAcctString = $info->getPaymentType() == 'ACH' ? 
                $paymentAcctString . $newPaymentAccount . ' - eCheck,' . $newToken . '|' :
                $paymentAcctString . $newPaymentAccount . ' - ' .$newCardType . ' [' . $newCcExpMonth . '/' . $newCcExpYear . 
            '],' . $newToken . '|';
        // update an existing payment account   
        } else {
            $paymentAccts = explode('|',$paymentAcctString);
            foreach($paymentAccts as $paymentAcct) {
                if (strlen($paymentAcct) < 2)
                    continue;
                $paymentAccount = explode(',',$paymentAcct);
                if (strpos($paymentAcct, $oldToken) !== false) {
                    $oldPaymentString = $paymentAccount[0];
                    $oldPaymentAccount = explode('-',$oldPaymentString)[0];
                    // gather new ACH info to update payment info in db
                    if ($info->getPaymentType() == 'ACH') {
                        $newPaymentString = str_replace(trim($oldPaymentAccount), $newPaymentAccount, $oldPaymentString); 
                    // gather new CC info to update payment info in db
                    } else {
                        $oldExpMonth = substr(explode('[',($oldPaymentString))[1],0,2);
                        $oldExpYear = substr(explode('[',($oldPaymentString))[1],3,2);
                        $oldCardType = explode('[',(explode('-',$oldPaymentString)[1]))[0];
                        $newPaymentString = str_replace($oldExpMonth, $newCcExpMonth, $oldPaymentString);  
                        $newPaymentString = str_replace($oldExpYear, $newCcExpYear, $newPaymentString);
                        $newPaymentString = str_replace(trim($oldPaymentAccount), $newPaymentAccount, $newPaymentString);
                        $newPaymentString = str_replace(trim($oldCardType), $newCardType, $newPaymentString);  
                    }
                    $paymentAcctString = str_replace($oldPaymentString, $newPaymentString, $paymentAcctString);
                    $paymentAcctString = str_replace($oldToken, $newToken, $paymentAcctString);
                }
            }
        }
        $customerData->setCustomAttribute('bluepay_stored_accts', $paymentAcctString);   
        $customer->updateData($customerData);
        $customer->save();
        return;
    }

}