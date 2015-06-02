<?php

/**
 * Smartdevs indexer Helper
 *
 * @category    Smartdevs
 * @package     Smartdevs_Indexer
 * @author      Daniel Niedergesäß <daniel.niedergesaess@gmail.com>
 */
class Smartdevs_Indexer_Helper_Data extends Mage_Core_Helper_Abstract
{

    /**
     * generate a uuid needed for foreign keys
     *
     * @return string
     */
    public function getUUID()
    {
        return sprintf(
            '%04X%04X-%04X-%04X-%04X-%04X%04X%04X',
            mt_rand(0, 65535),
            mt_rand(0, 65535),
            mt_rand(0, 65535),
            mt_rand(16384, 20479),
            mt_rand(32768, 49151),
            mt_rand(0, 65535),
            mt_rand(0, 65535),
            mt_rand(0, 65535)
        );

    }
}
