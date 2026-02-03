<?php
/**
 * Bookurier API log handler.
 */
namespace Bookurier\Shipping\Logger;

use Magento\Framework\Logger\Handler\Base;
use Monolog\Logger;

class Handler extends Base
{
    /**
     * @var string
     */
    protected $fileName = '/var/log/bookurier_api.log';

    /**
     * @var int
     */
    protected $loggerType = Logger::DEBUG;
}
