<?php
/**
 * Bookurier carrier model.
 */
namespace Bookurier\Shipping\Model\Carrier;

use Bookurier\Shipping\Model\Tracking\HistoryProvider;
use Magento\Quote\Model\Quote\Address\RateRequest;
use Magento\Shipping\Model\Carrier\AbstractCarrier;
use Magento\Shipping\Model\Carrier\CarrierInterface;
use Magento\Shipping\Model\Rate\Result;
use Magento\Shipping\Model\Tracking\Result\StatusFactory as TrackingStatusFactory;

class Bookurier extends AbstractCarrier implements CarrierInterface
{
    /**
     * Carrier code.
     *
     * @var string
     */
    protected $_code = 'bookurier';

    /**
     * @var \Magento\Shipping\Model\Rate\ResultFactory
     */
    private $rateResultFactory;

    /**
     * @var \Magento\Quote\Model\Quote\Address\RateResult\MethodFactory
     */
    private $rateMethodFactory;

    /**
     * @var TrackingStatusFactory
     */
    private $trackingStatusFactory;

    /**
     * @var HistoryProvider
     */
    private $historyProvider;

    /**
     * @param \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig
     * @param \Magento\Quote\Model\Quote\Address\RateResult\ErrorFactory $rateErrorFactory
     * @param \Psr\Log\LoggerInterface $logger
     * @param \Magento\Shipping\Model\Rate\ResultFactory $rateResultFactory
     * @param \Magento\Quote\Model\Quote\Address\RateResult\MethodFactory $rateMethodFactory
     * @param TrackingStatusFactory $trackingStatusFactory
     * @param HistoryProvider $historyProvider
     * @param array $data
     */
    public function __construct(
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
        \Magento\Quote\Model\Quote\Address\RateResult\ErrorFactory $rateErrorFactory,
        \Psr\Log\LoggerInterface $logger,
        \Magento\Shipping\Model\Rate\ResultFactory $rateResultFactory,
        \Magento\Quote\Model\Quote\Address\RateResult\MethodFactory $rateMethodFactory,
        TrackingStatusFactory $trackingStatusFactory,
        HistoryProvider $historyProvider,
        array $data = []
    ) {
        $this->rateResultFactory = $rateResultFactory;
        $this->rateMethodFactory = $rateMethodFactory;
        $this->trackingStatusFactory = $trackingStatusFactory;
        $this->historyProvider = $historyProvider;
        parent::__construct($scopeConfig, $rateErrorFactory, $logger, $data);
    }

    /**
     * Collect and get rates.
     *
     * @param RateRequest $request
     * @return Result|bool
     */
    public function collectRates(RateRequest $request)
    {
        if (!$this->getConfigFlag('active')) {
            return false;
        }

        if (!$this->getConfigFlag('available_in_checkout')) {
            return false;
        }

        /** @var Result $result */
        $result = $this->rateResultFactory->create();

        $price = $this->getConfigData('price');
        $price = $this->getFinalPriceWithHandlingFee($price);

        $method = $this->rateMethodFactory->create();
        $method->setCarrier($this->_code);
        $method->setCarrierTitle($this->getConfigData('title'));
        $method->setMethod($this->_code);
        $method->setMethodTitle($this->getConfigData('name'));
        $method->setPrice($price);
        $method->setCost($price);

        $result->append($method);
        return $result;
    }

    /**
     * Tracking is available for this carrier.
     *
     * @return bool
     */
    public function isTrackingAvailable()
    {
        return true;
    }

    /**
     * Get allowed shipping methods.
     *
     * @return array
     */
    public function getAllowedMethods()
    {
        return [$this->_code => $this->getConfigData('name')];
    }

    /**
     * Get tracking info for a given AWB.
     *
     * @param string $trackingNumber
     * @return \Magento\Shipping\Model\Tracking\Result\Status|\Magento\Framework\Phrase
     */
    public function getTrackingInfo($trackingNumber)
    {
        $status = $this->trackingStatusFactory->create();

        $status->setCarrier($this->_code);
        $status->setCarrierTitle((string)$this->getConfigData('title'));
        $status->setTracking((string)$trackingNumber);
        $url = str_replace('%awb%', rawurlencode((string)$trackingNumber), (string)$this->getConfigData('tracking_url'));
        $status->setUrl($url);

        $historyMeta = $this->historyProvider->getHistoryWithMeta(
            (string)$trackingNumber,
            (int)$this->getStore()->getId(),
            null,
            false
        );
        $history = $historyMeta['response'];
        if (!empty($historyMeta['last_query_at'])) {
            $status->setData('last_query_at', $historyMeta['last_query_at']);
        }

        $items = $this->extractHistoryItems($history);
        if (!$this->isHistorySuccess($history) && empty($items)) {
            $status->setTrackSummary(__('Tracking information is currently not available.'));
            return $status;
        }

        $progress = $this->mapProgressDetails($items);
        if (!empty($progress)) {
            $status->setProgressdetail($progress);
            $latest = end($progress);
            $summary = $latest['activity'] ?? null;
            if (!empty($latest['deliverydate'])) {
                $summary .= ' (' . $latest['deliverydate'] . ' ' . ($latest['deliverytime'] ?? '') . ')';
            }
            if ($summary) {
                $status->setTrackSummary($summary);
            }
        } else {
            $status->setTrackSummary(__('Tracking information is currently not available.'));
        }

        return $status;
    }

    /**
     * @param array $history
     * @return bool
     */
    private function isHistorySuccess(array $history): bool
    {
        if (!array_key_exists('success', $history)) {
            return false;
        }

        $success = $history['success'];
        if (is_bool($success)) {
            return $success;
        }
        if (is_numeric($success)) {
            return (int)$success === 1;
        }
        if (is_string($success)) {
            $normalized = strtolower(trim($success));
            return in_array($normalized, ['1', 'true', 'ok', 'success'], true);
        }

        return !empty($success);
    }

    /**
     * @param array $history
     * @return array
     */
    private function extractHistoryItems(array $history): array
    {
        foreach (['data', 'awb_histories', 'history'] as $key) {
            if (isset($history[$key]) && is_array($history[$key])) {
                return $history[$key];
            }
        }

        return [];
    }

    /**
     * @param array $items
     * @return array
     */
    private function mapProgressDetails(array $items): array
    {
        $details = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $sortDate = $item['sort_date'] ?? null;
            if (!$sortDate) {
                continue;
            }
            $datePart = '';
            $timePart = '';
            if (strpos($sortDate, ' ') !== false) {
                [$datePart, $timePart] = explode(' ', $sortDate, 2);
            } else {
                $datePart = $sortDate;
            }

            $activity = $item['status_name'] ?? '';
            $obs = $item['obs'] ?? null;
            if ($obs) {
                $activity = trim($activity . ' - ' . $obs);
            }

            $details[] = [
                'deliverydate' => $datePart,
                'deliverytime' => $timePart,
                'deliverylocation' => '',
                'activity' => $activity,
            ];
        }

        usort($details, static function ($a, $b) {
            return strcmp(($a['deliverydate'] . ' ' . $a['deliverytime']), ($b['deliverydate'] . ' ' . $b['deliverytime']));
        });

        return $details;
    }
}
