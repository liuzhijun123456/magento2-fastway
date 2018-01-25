<?php
namespace DCOnline\Fastway\Model;

use Magento\Framework\Xml\Security;
use Magento\Quote\Model\Quote\Address\RateRequest;
use Magento\Shipping\Model\Carrier\AbstractCarrierOnline;
use Magento\Shipping\Model\Carrier\CarrierInterface;
use Magento\Shipping\Model\Rate\Result;
use Magento\Ups\Helper\Config;

class Carrier extends AbstractCarrierOnline implements CarrierInterface
{
    const CODE = 'fastway';

    /**
     * @var string
     */
    protected $_code = self::CODE;

    /**
     * @var RateRequest $_request
     */
    protected $_request;

    /**
     * @var Result $_result
     */
    protected $_result;

    /**
     * @var \DCOnline\Fastway\Api\ValidatorInterface $validator
     */
    protected $validator;

    /**
     * @var \Magento\Framework\HTTP\ZendClientFactory $httpClientFactory
     */
    protected $httpClientFactory;

    public function __construct(
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
        \Magento\Quote\Model\Quote\Address\RateResult\ErrorFactory $rateErrorFactory,
        \Psr\Log\LoggerInterface $logger,
        Security $xmlSecurity,
        \Magento\Shipping\Model\Simplexml\ElementFactory $xmlElFactory,
        \Magento\Shipping\Model\Rate\ResultFactory $rateFactory,
        \Magento\Quote\Model\Quote\Address\RateResult\MethodFactory $rateMethodFactory,
        \Magento\Shipping\Model\Tracking\ResultFactory $trackFactory,
        \Magento\Shipping\Model\Tracking\Result\ErrorFactory $trackErrorFactory,
        \Magento\Shipping\Model\Tracking\Result\StatusFactory $trackStatusFactory,
        \Magento\Directory\Model\RegionFactory $regionFactory,
        \Magento\Directory\Model\CountryFactory $countryFactory,
        \Magento\Directory\Model\CurrencyFactory $currencyFactory,
        \Magento\Directory\Helper\Data $directoryData,
        \Magento\CatalogInventory\Api\StockRegistryInterface $stockRegistry,
        \Magento\Framework\HTTP\ZendClientFactory $httpClientFactory,
        \DCOnline\Fastway\Api\ValidatorInterface $validator,
        array $data = []
    ) {
        $this->httpClientFactory = $httpClientFactory;
        $this->validator = $validator;
        parent::__construct(
            $scopeConfig,
            $rateErrorFactory,
            $logger,
            $xmlSecurity,
            $xmlElFactory,
            $rateFactory,
            $rateMethodFactory,
            $trackFactory,
            $trackErrorFactory,
            $trackStatusFactory,
            $regionFactory,
            $countryFactory,
            $currencyFactory,
            $directoryData,
            $stockRegistry,
            $data
        );
    }

    /**
     * Do shipment request to carrier web service, obtain Print Shipping Labels and process errors in response
     * @param \Magento\Framework\DataObject $request
     * @return \Magento\Framework\DataObject
     */
    protected function _doShipmentRequest(\Magento\Framework\DataObject $request)
    {
        $this->_prepareShipmentRequest($request);
        $this->_mapRequestToShipment($request);
        $this->setRequest($request);
        return $this->_doRequest();
    }

    /**
     * 获取支持的方式
     * @return array
     */
    public function getAllowedMethods()
    {
        return [$this->getCarrierCode() => __($this->getConfigData('name'))];
    }

    /**
     * 页面调用查询快递记录
     * @return Result|null
     */
    public function getTracking($trackings)
    {
        $tracksumary = $this->getXMLTracking($trackings);
        $result = $this->_trackFactory->create();
        $tracking = $this->_trackStatusFactory->create();
        $tracking->setCarrier($this->_code);
        $tracking->setCarrierTitle($this->getConfigData('title'));
        $tracking->setTracking($trackings);
        $tracking->setTrackSummary($tracksumary);
        $result->append($tracking);
        return $result;
    }

    /**
     * 获取物流信息
     * @param string $trackings
     * @return string
     */
    public function getXMLTracking($trackings)
    {
        // 先判断单号规则
        if (!$this->validator->isValid($trackings)) {
            return __('Invalid track number is %1 ', $trackings);
        }
        // 从缓存拿
        $response = $this->_getCachedQuotes($trackings);
        if ($response === null) {
            try {
                // 请求api查快递信息
                $base_url = $this->getConfigData('gateway_url');
                $api_key = $this->getConfigData('apikey');
                $url = $base_url . '/tracktrace/detail/' . rawurlencode($trackings) . '/24.xml?api_key=' . $api_key;
                $client = $this->httpClientFactory->create();
                $client->setUri($url);
                $client->setConfig(['maxredirects' => 0, 'timeout' => 30]);
                $response = $client->request()->getBody();
                $this->_setCachedQuotes($trackings, $response);
            } catch (\Exception $e) {
                $response = '';
            }
        }
        // 处理物流信息结果
        if (strlen(trim($response)) > 0) {
            $xml = $this->parseXml($response, 'Magento\Shipping\Model\Simplexml\Element');
            if (is_object($xml)) {
                // api结果没有返回错误
                if (strlen($xml->error) <= 0) {
                    $result = '';
                    foreach ($xml->result->Scans->array->item as $item) {
                        $result = $item->StatusDescription;
                    }
                    // 查询到有物流信息
                    return $result;
                }
            }
        }
        // 没有物流信息
        $result = __('%1 - tracking number does not have any information.', $trackings);
        return $result;
    }

    /**
     * 是否可用
     * @return bool
     */
    public function canCollectRates()
    {
        return parent::canCollectRates() && $this->getConfigData('apikey');
    }

    /**
     * 获取运费
     * @param Magento\Quote\Model\Quote\Address\RateRequest $request
     * @return \Magento\Shipping\Model\Rate\ResultFactory
     */
    public function collectRates(RateRequest $request)
    {
        if (!$this->canCollectRates()) {
            return $this->getErrorMessage();
        }
        $this->setRequest($request);
        $city = $this->getCity();
        $postcode = $this->getPostcode();
        $weight = $this->getWeight();
        $params = [
            'city' => $city,
            'postcode' => $postcode,
            'weight' => $weight,
        ];
        $responseBody = $this->_getCachedQuotes($params);
        if ($responseBody === null) {
            try {
                $responseBody = $this->getQuotesFromServer($params);
                $this->_setCachedQuotes($params, $responseBody);
            } catch (\Exception $e) {
                $responseBody = '';
            }
        }
        return $this->parseXmlResponse($responseBody);
    }

    /**
     * 获取默认数据
     * NOTE: 目前没有使用官方地址为发货地址，这个方法暂时没用
     * @param string|int $origValue
     * @param string $pathToValue
     * @return string|int|null
     */
    protected function _getDefaultValue($origValue, $pathToValue)
    {
        if (!$origValue) {
            $origValue = $this->_scopeConfig->getValue(
                $pathToValue,
                \Magento\Store\Model\ScopeInterface::SCOPE_STORE,
                $this->getStore()
            );
        }

        return $origValue;
    }

    /**
     * 调用fastway接口查询费用
     * @param array $params
     * @return string|null
     */
    protected function getQuotesFromServer($params)
    {
        $city = $params['city'];
        $postcode = $params['postcode'];
        $weight = $params['weight'];
        if (strlen($city) > 0 && strlen($postcode) > 0 && $weight > 0) {
            $client = $this->httpClientFactory->create();
            $base_url = $this->getConfigData('gateway_url');
            $api_key = $this->getConfigData('apikey');
            // TODO: JNB使用配置设置
            $url = $base_url . '/psc/lookup/JNB/' . rawurlencode($city) . '/' . $postcode . '/1.xml?api_key=' . $api_key;
            $client->setUri($url);
            $client->setConfig(['maxredirects' => 0, 'timeout' => 30]);
            return $client->request()->getBody();
        } else {
            return "";
        }
    }

    /**
     * 解析返回结果xml
     * @param $response
     * @return \Magento\Shipping\Model\Rate\ResultFactory
     */
    protected function parseXmlResponse($response)
    {
        $weight = $this->getWeight();
        $base = ceil($weight / 30);
        $priceArr = [];
        if (strlen(trim($response)) > 0) {
            $xml = $this->parseXml($response, 'Magento\Shipping\Model\Simplexml\Element');
            if (is_object($xml)) {
                if (strlen($xml->error) == 0) {
                    $pri = 0;
                    foreach ($xml->result->services->array->item as $item) {
                        $temp = $item->totalprice_frequent;
                        if ($temp > $pri) {
                            $pri = $temp;
                            $priceArr[(string) $xml->result->delivery_timeframe_days . __('days')] = $this->getMethodPrice(
                                (float) ($base * (float) $item->totalprice_frequent),
                                (string) $xml->result->delivery_timeframe_days . __('days')
                            );
                        }
                    }
                    asort($priceArr);
                } else {
                }
            } else {
            }
        } else {
        }
        // 增加错误提示,需要开启showmethod
        $result = $this->_rateFactory->create();
        if (empty($priceArr)) {
            $error = $this->_rateErrorFactory->create();
            $error->setCarrier($this->_code);
            $error->setCarrierTitle($this->getConfigData('title'));
            $error->setErrorMessage($this->getConfigData('specificerrmsg'));
            $result->append($error);
        } else {
            foreach ($priceArr as $method => $price) {
                $rate = $this->_rateMethodFactory->create();
                $rate->setCarrier($this->_code);
                $rate->setCarrierTitle($this->getConfigData('title'));
                $rate->setMethod($method);
                $rate->setMethodTitle($method);
                $rate->setCost($price);
                $rate->setPrice($price);
                $result->append($rate);
            }
        }
        return $result;
    }

    /**
     * Returns request result
     * @return Result|null
     */
    public function getResult()
    {
        return $this->_result;
    }

    /**
     * 设置当前请求线程
     * @param RateRequest $request
     * @return $this
     */
    public function setRequest(RateRequest $request)
    {
        $this->_request = $request;
        return $this;
    }

    /**
     * not delete
     * @param \Magento\Framework\DataObject $request
     * @return bool
     */
    public function proccessAdditionalValidation(\Magento\Framework\DataObject $request)
    {
        return true;
    }

    /**
     * 购物车里的重量
     * @return int
     */
    protected function getWeight()
    {
        return ceil($this->_request->getPackageWeight());
    }

    /**
     * 收货地址城市
     * @return string|null
     */
    protected function getCity()
    {
        return $this->_request->getDestCity();
    }

    /**
     * 收货地址邮编
     * @return string|null
     */
    protected function getPostcode()
    {
        return $this->_request->getDestPostcode();
    }
}
